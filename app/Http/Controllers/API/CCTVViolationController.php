<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CCTVViolation;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class CCTVViolationController extends Controller
{
    // POST /api/cctv-violations/upload
    // Import CCTV violations from Excel file
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'month' => 'required|integer|between:1,12',
            'year' => 'required|integer|min:2020',
            'violations' => 'required|array|min:1',
            'violations.*.employee_id' => 'required|string|exists:employees,employee_id',
            'violations.*.date' => 'required|date_format:Y-m-d',
            'violations.*.violation_type' => 'required|string',
            'violations.*.description' => 'required|string',
            'violations.*.penalty_days' => 'nullable|integer|min:0',
            'violations.*.notes' => 'nullable|string',
        ]);

        $month = $request->month;
        $year = $request->year;
        $violations = $request->violations;

        $inserted = 0;
        foreach ($violations as $v) {
            // Check if employee exists
            $employee = Employee::where('employee_id', $v['employee_id'])->first();
            if (!$employee) continue;

            CCTVViolation::updateOrCreate(
                [
                    'employee_id' => $v['employee_id'],
                    'violation_date' => $v['date'],
                    'violation_type' => $v['violation_type'],
                ],
                [
                    'description' => $v['description'],
                    'penalty_days' => $v['penalty_days'] ?? 0,
                    'notes' => $v['notes'] ?? null,
                    'month' => $month,
                    'year' => $year,
                    'recorded_by' => 'cctv_import',
                ]
            );
            $inserted++;
        }

        return response()->json([
            'success' => true,
            'inserted' => $inserted,
            'message' => "تم استيراد {$inserted} مخالفة CCTV",
        ]);
    }

    // GET /api/cctv-violations?month=&year=&department_id=&employee_id=
    public function index(Request $request): JsonResponse
    {
        $query = CCTVViolation::with(['employee.department'])
            ->orderBy('violation_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->where('month', $request->month)
                  ->where('year', $request->year);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) =>
                $q->where('department_id', $request->department_id)
            );
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->filled('violation_type')) {
            $query->where('violation_type', $request->violation_type);
        }

        if ($request->filled('date')) {
            $query->whereDate('violation_date', $request->date);
        }

        return response()->json($query->paginate(50));
    }

    // GET /api/cctv-violations/{employeeId}?month=&year=
    public function getForEmployee(string $employeeId, Request $request): JsonResponse
    {
        $query = CCTVViolation::where('employee_id', $employeeId)
            ->orderBy('violation_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->where('month', $request->month)
                  ->where('year', $request->year);
        }

        return response()->json($query->get());
    }

    // GET /api/cctv-violations/daily/{date}?department_id=
    // Get CCTV violations for a specific date (for daily position report)
    public function getDailyViolations(string $date, Request $request): JsonResponse
    {
        $query = CCTVViolation::with(['employee.department'])
            ->whereDate('violation_date', $date);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) =>
                $q->where('department_id', $request->department_id)
            );
        }

        $violations = $query->get();

        // Format for daily position report integration
        $formatted = $violations->map(function ($v) {
            return [
                'employee_id' => $v->employee_id,
                'employee_name' => $v->employee->name ?? '—',
                'department' => $v->employee->department?->name ?? '—',
                'violation_date' => $v->violation_date,
                'violation_type' => $v->violation_type,
                'description' => $v->description,
                'penalty_days' => $v->penalty_days,
                'is_salary_deduction' => $v->penalty_days > 0,
                'notes' => $v->notes,
            ];
        });

        return response()->json([
            'date' => $date,
            'total_violations' => $violations->count(),
            'salary_deduction_count' => $violations->where('penalty_days', '>', 0)->count(),
            'violations' => $formatted,
        ]);
    }

    // PUT /api/cctv-violations/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $violation = CCTVViolation::findOrFail($id);

        $validated = $request->validate([
            'violation_type' => 'sometimes|string',
            'description' => 'sometimes|string',
            'penalty_days' => 'sometimes|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $violation->update($validated);

        return response()->json($violation->load('employee'));
    }

    // DELETE /api/cctv-violations/{id}
    public function destroy(int $id): JsonResponse
    {
        CCTVViolation::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // POST /api/cctv-violations/{id}/convert-to-leave
    // Convert CCTV violation with salary deduction to unpaid leave for daily position report
    public function convertToUnpaidLeave(int $id): JsonResponse
    {
        $violation = CCTVViolation::with('employee')->findOrFail($id);

        if ($violation->penalty_days <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'هذه المخالفة لا تحتوي على خصم راتب',
            ], 422);
        }

        // Create a leave record as unpaid leave
        $leave = \App\Models\Leave::updateOrCreate(
            [
                'employee_id' => $violation->employee_id,
                'leave_date' => $violation->violation_date,
                'leave_type' => 'unpaid',
            ],
            [
                'status' => 'approved',
                'notes' => "تحويل مخالفة CCTV إلى إجازة بدون راتب - {$violation->description}",
                'approved_by' => 'system_cctv_conversion',
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحويل المخالفة إلى إجازة بدون راتب',
            'leave' => $leave,
            'cctv_violation' => $violation,
        ]);
    }
}