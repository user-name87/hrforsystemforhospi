<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Violation;
use App\Models\DisciplinaryAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ViolationController extends Controller
{
    // Violation table per the spec (Arabic HR policy)
    private const VIOLATION_PENALTIES = [
        // [row][occurrence] => penalty text
        1 => [
            1 => 'تنبيه شفوي',
            2 => 'إنذار كتابي',
            3 => 'خصم مرتب ربع يوم عمل',
            4 => 'خصم مرتب نصف يوم عمل',
        ],
        2 => [
            1 => 'تنبيه مع خصم مدة التأخير',
            2 => 'خصم مرتب ربع يوم عمل',
            3 => 'خصم مرتب نصف يوم عمل',
            4 => 'خصم مرتب يوم عمل جزاء',
        ],
        3 => [
            1 => 'تنبيه مع خصم مدة التأخير',
            2 => 'خصم مرتب نصف يوم عمل',
            3 => 'خصم مرتب يوم عمل',
            4 => 'إنذار كتابي نهائي مع خصم يومان عمل',
        ],
        4 => [
            1 => 'إنذار كتابي مع خصم مدة التأخير',
            2 => 'خصم مرتب يوم عمل',
            3 => 'إنذار كتابي نهائي مع خصم 3 أيام عمل',
            4 => 'خصم 5 أيام عمل + رفع توصية بإنهاء الخدمة',
        ],
        5 => [
            1 => 'خصم مرتب يوم عمل',
            2 => 'خصم مرتب يومان عمل',
            3 => 'خصم 3 أيام عمل',
            4 => 'خصم 4 أيام عمل',
        ],
        6 => [
            1 => 'عقوبة خصم يوم عمل جزاء',
            2 => 'خصم مرتب يومان عمل جزاء',
            3 => 'إنذار كتابي نهائي مع خصم 3 أيام عمل جزاء',
            4 => 'خصم مرتب 5 أيام عمل + رفع توصية بإنهاء الخدمة',
        ],
    ];

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
            'violation_row'        => 'required|integer|between:1,6',
            'incident_date'        => 'required|date',
            'notes'                => 'nullable|string',
        ]);

        // Auto-determine occurrence number for this violation row
        $occurrenceNumber = Violation::where('employee_id', $validated['employee_id'])
            ->where('violation_row', $validated['violation_row'])
            ->count() + 1;

        // Cap at 4 (max in the penalty table)
        $occurrenceNumber = min($occurrenceNumber, 4);

        // Look up penalty
        $penalty = self::VIOLATION_PENALTIES[$validated['violation_row']][$occurrenceNumber]
            ?? 'يرجى مراجعة لجنة الشؤون الإدارية';

        $violation = Violation::create([
            ...$validated,
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
            'created_by'   => 'nullable|string',
        ]);

        $action = DisciplinaryAction::create($validated);

        // Also create a notification
        \DB::table('notifications')->insert([
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
}
