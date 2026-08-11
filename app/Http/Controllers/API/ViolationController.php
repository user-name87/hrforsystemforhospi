<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Violation;
use App\Models\DisciplinaryAction;
use App\Models\Employee;
use App\Services\WhatsAppNotificationService;
use App\Support\ViolationPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ViolationController extends Controller
{
    public function __construct(private WhatsAppNotificationService $whatsapp) {}

    // GET /api/violations/{employeeId}?month=&year=
    public function getForEmployee(string $employeeId, Request $request): JsonResponse
    {
        $query = Violation::where('employee_id', $employeeId)
            ->orderBy('incident_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('incident_date', $request->month)
                  ->whereYear('incident_date', $request->year);
        }

        return response()->json($query->get());
    }

    // GET /api/violations?month=&year=&department_id=
    public function index(Request $request): JsonResponse
    {
        $query = Violation::with(['employee.department'])
            ->orderBy('incident_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('incident_date', $request->month)
                  ->whereYear('incident_date', $request->year);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) =>
                $q->where('department_id', $request->department_id)
            );
        }

        return response()->json($query->paginate(50));
    }

    // POST /api/violations
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'          => 'required|string|exists:employees,employee_id',
            'violation_category'   => 'required|string',
            'violation_type'       => 'nullable|string|in:late,early_leave,absent,disruption,abandoning',
            'violation_row'        => 'required|integer|between:1,6',
            'minutes'              => 'nullable|integer|min:0',
            'incident_date'        => 'required|date',
            'notes'                => 'nullable|string',
        ]);

        $occurrenceNumber = Violation::where('employee_id', $validated['employee_id'])
            ->where('violation_row', $validated['violation_row'])
            ->count() + 1;

        $penalty = ViolationPolicy::penalty($validated['violation_row'], $occurrenceNumber);

        $violation = Violation::create([
            ...$validated,
            'violation_type'    => $validated['violation_type'] ?? 'late',
            'source'            => 'manual',
            'occurrence_number' => $occurrenceNumber,
            'penalty'           => $penalty,
        ]);

        return response()->json([
            'violation'         => $violation->load('employee'),
            'occurrence_number' => $occurrenceNumber,
            'penalty'           => $penalty,
        ], 201);
    }

    // DELETE /api/violations/{id}
    public function destroy(int $id): JsonResponse
    {
        Violation::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // GET /api/disciplinary/{employeeId}
    public function getDisciplinary(string $employeeId): JsonResponse
    {
        $actions = DisciplinaryAction::where('employee_id', $employeeId)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($actions);
    }

    // POST /api/disciplinary
    public function storeDisciplinary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'  => 'required|string|exists:employees,employee_id',
            'action_type'  => 'required|string',
            'severity'     => 'required|in:low,medium,high',
            'note'         => 'required|string',
            'penalty'      => 'nullable|string',
            'created_by'   => 'nullable|string',
        ]);

        $action = DisciplinaryAction::create($validated);

        // Also create a notification
        DB::table('notifications')->insert([
            'employee_id' => $validated['employee_id'],
            'kind'        => 'disciplinary',
            'title'       => $validated['action_type'],
            'detail'      => $validated['note'],
            'status'      => 'unread',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        return response()->json($action->load('employee'), 201);
    }

    // POST /api/violations/{id}/notify
    public function sendViolationNotification(int $id): JsonResponse
    {
        $violation = Violation::with('employee')->findOrFail($id);
        $employee = $violation->employee;

        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee not found'], 404);
        }

        $result = $this->whatsapp->sendViolationNotification($employee, $violation);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // POST /api/disciplinary/{id}/notify
    public function sendDisciplinaryNotification(int $id): JsonResponse
    {
        $action = DisciplinaryAction::with('employee')->findOrFail($id);
        $employee = $action->employee;

        if (!$employee) {
            return response()->json(['success' => false, 'message' => 'Employee not found'], 404);
        }

        $result = $this->whatsapp->sendDisciplinaryNotification(
            $employee,
            $action->toArray() + ['type' => DisciplinaryAction::class, 'id' => $action->id]
        );

        if ($result['success']) {
            $action->forceFill(['notified_at' => now()])->save();
        }

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // POST /api/violations/daily-notify
    public function sendDailyViolations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => 'required|date',
            'department_id' => 'nullable|integer|exists:departments,id',
            'violation_type' => 'nullable|string|in:absent,late,early_leave,disciplinary',
        ]);

        $date = $validated['date'];
        $query = Violation::with('employee')
            ->whereDate('incident_date', $date);

        if (isset($validated['department_id'])) {
            $query->whereHas('employee', fn($q) => 
                $q->where('department_id', $validated['department_id'])
            );
        }

        if (($validated['violation_type'] ?? null) === 'disciplinary') {
            $actions = DisciplinaryAction::with('employee')
                ->whereDate('created_at', $date)
                ->when($validated['department_id'] ?? null, fn($q, $dep) =>
                    $q->whereHas('employee', fn($e) => $e->where('department_id', $dep))
                )
                ->get();

            $results = [];
            foreach ($actions as $action) {
                if (!$action->employee) continue;

                $result = $this->whatsapp->sendDisciplinaryNotification(
                    $action->employee,
                    $action->toArray() + ['type' => DisciplinaryAction::class, 'id' => $action->id]
                );

                if ($result['success']) {
                    $action->forceFill(['notified_at' => now()])->save();
                }

                $results[] = $result;
            }

            return response()->json([
                'total' => $actions->count(),
                'sent' => count(array_filter($results, fn($r) => $r['success'])),
                'failed' => count(array_filter($results, fn($r) => !$r['success'])),
                'results' => $results,
            ]);
        }

        if (isset($validated['violation_type'])) {
            $query->where('violation_type', $validated['violation_type']);
        }

        return response()->json(
            $this->whatsapp->sendDailyViolationNotifications($query->get())
        );
    }
}
