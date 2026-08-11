<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\MonthlySchedule;
use App\Services\ReminderEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function __construct(private ReminderEmailService $mailer) {}

    // GET /api/departments?month=&year=
    public function index(Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year  = (int)($request->query('year',  now()->year));

        $deps = Department::with(['employees'])->get();

        // Which departments have uploaded their schedule this month?
        $uploaded = MonthlySchedule::where('month', $month)
            ->where('year', $year)
            ->pluck('department_id')
            ->toArray();

        $result = $deps->map(function ($dep) use ($uploaded, $month, $year) {
            return [
                'id'             => $dep->id,
                'name'           => $dep->name,
                'chairman_name'  => $dep->chairman_name,
                'chairman_email' => $dep->chairman_email,
                'employee_count' => $dep->employees->count(),
                'schedule_uploaded' => in_array($dep->id, $uploaded),
                'upload_date'    => MonthlySchedule::where('department_id', $dep->id)
                    ->where('month', $month)->where('year', $year)
                    ->value('created_at'),
            ];
        });

        return response()->json([
            'data'  => $result,
            'month' => $month,
            'year'  => $year,
            'stats' => [
                'total'    => $deps->count(),
                'uploaded' => count($uploaded),
                'missing'  => $deps->count() - count($uploaded),
            ],
        ]);
    }

    // GET /api/departments/{id}?month=&year=
    public function show(Request $request, int $id): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year  = (int)($request->query('year',  now()->year));

        $dep = Department::with(['employees'])->findOrFail($id);

        $uploaded = MonthlySchedule::where('department_id', $id)
            ->where('month', $month)->where('year', $year)->first();

        // Build employee list with their schedule summary
        $employees = $dep->employees->map(function ($emp) use ($month, $year) {
            $scheduledDays = \App\Models\EmployeeSchedule::where('employee_id', $emp->employee_id)
                ->where('month', $month)->where('year', $year)->count();
            return [
                'id'             => $emp->id,
                'employee_id'    => $emp->employee_id,
                'name'           => $emp->name,
                'job_title'      => $emp->job_title,
                'shift_type'     => $emp->shift_type,
                'classification' => $emp->classification,
                'scheduled_days' => $scheduledDays,
            ];
        });

        return response()->json([
            'department'        => $dep,
            'employees'         => $employees,
            'schedule_uploaded' => (bool)$uploaded,
            'upload_date'       => $uploaded?->created_at,
            'month'             => $month,
            'year'              => $year,
        ]);
    }

    // POST /api/departments
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'chairman_name'  => 'nullable|string|max:255',
            'chairman_email' => 'nullable|email|max:255',
        ]);

        $dep = Department::create($validated);
        return response()->json($dep, 201);
    }

    // PUT /api/departments/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $dep = Department::findOrFail($id);
        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'chairman_name'  => 'nullable|string|max:255',
            'chairman_email' => 'nullable|email|max:255',
        ]);
        $dep->update($validated);
        return response()->json($dep);
    }

    // DELETE /api/departments/{id}
    public function destroy(int $id): JsonResponse
    {
        Department::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // POST /api/departments/{id}/remind
    public function sendReminder(Request $request, int $id): JsonResponse
    {
        $month = (int)($request->input('month', now()->month));
        $year  = (int)($request->input('year',  now()->year));
        $dep   = Department::findOrFail($id);
        $result = $this->mailer->sendToOne($dep, $month, $year);
        return response()->json($result, $result['success'] ? 200 : 422);
    }

    // POST /api/departments/{id}/remind-all
    public function sendAllReminders(Request $request): JsonResponse
    {
        $month = (int)($request->input('month', now()->month));
        $year  = (int)($request->input('year',  now()->year));

        $uploaded = MonthlySchedule::where('month', $month)
            ->where('year', $year)->pluck('department_id');

        $pending = Department::whereNotIn('id', $uploaded)
            ->whereNotNull('chairman_email')->get();

        $results = $this->mailer->sendToAllPending($pending->all(), $month, $year);
        return response()->json(['results' => $results, 'sent' => count($results)]);
    }
}
