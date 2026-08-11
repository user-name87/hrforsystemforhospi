<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Models\CCTVViolation;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/attendance/{employeeId}/{month}/{year}
    public function getEmployeeAttendance(string $employeeId, int $month, int $year): JsonResponse
    {
        $emp = Employee::with('department')
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        $report = $this->calc->calculate($emp, $month, $year);
        return response()->json($report);
    }

    // GET /api/attendance/department/{depId}/{month}/{year}
    public function getDepartmentAttendance(int $depId, int $month, int $year): JsonResponse
    {
        $employees = Employee::where('department_id', $depId)->get();

        $reports = $employees->map(function ($emp) use ($month, $year) {
            return $this->calc->calculate($emp, $month, $year);
        });

        return response()->json([
            'department_id' => $depId,
            'month'         => $month,
            'year'          => $year,
            'employees'     => $reports,
        ]);
    }

    // POST /api/attendance/upload-fingerprints
    // Accepts JSON array of punch records (parsed from Excel by the frontend)
    // Format: [{ employee_id, date: "YYYY-MM-DD", time: "HH:MM", type: "in"|"out" }, ...]
    public function uploadFingerprints(Request $request): JsonResponse
    {
        $request->validate([
            'month'    => 'required|integer|between:1,12',
            'year'     => 'required|integer|min:2020',
            'punches'  => 'required|array|min:1',
            'punches.*.employee_id' => 'required|string',
            'punches.*.date'        => 'required|date_format:Y-m-d',
            'punches.*.time'        => 'required|date_format:H:i',
        ]);

        $month   = $request->month;
        $year    = $request->year;
        $punches = $request->punches;

        // Delete existing fingerprints for this month (re-upload replaces)
        $empIds = array_unique(array_column($punches, 'employee_id'));
        Fingerprint::whereIn('employee_id', $empIds)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        $inserted = 0;
        $chunks = array_chunk($punches, 300);

        foreach ($chunks as $chunk) {
            $rows = array_map(function ($p) use ($month, $year) {
                return [
                    'employee_id' => $p['employee_id'],
                    'punch_date'  => $p['date'],
                    'punch_time'  => $p['time'],
                    'punch_type'  => $p['type'] ?? null,
                    'source'      => 'upload',
                    'month'       => $month,
                    'year'        => $year,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }, $chunk);

            Fingerprint::insert($rows);
            $inserted += count($rows);
        }

        return response()->json([
            'success'  => true,
            'inserted' => $inserted,
            'message'  => "تم رفع {$inserted} سجل بصمة",
        ]);
    }

    // POST /api/attendance/upload-leaves
    // Accepts leave records parsed from Excel
    // Format: [{ employee_id, date: "YYYY-MM-DD", type: "annual"|"sick"|..., status: "approved"|... }]
    public function uploadLeaves(Request $request): JsonResponse
    {
        $request->validate([
            'month'          => 'required|integer|between:1,12',
            'year'           => 'required|integer|min:2020',
            'leaves'         => 'required|array|min:1',
            'leaves.*.employee_id' => 'required|string',
            'leaves.*.date'        => 'required|date_format:Y-m-d',
            'leaves.*.type'        => 'required|string',
            'leaves.*.status'      => 'nullable|string',
        ]);

        $month  = $request->month;
        $year   = $request->year;
        $leaves = $request->leaves;

        $empIds = array_unique(array_column($leaves, 'employee_id'));

        // Delete existing leaves for these employees in this month
        Leave::whereIn('employee_id', $empIds)
            ->whereYear('leave_date', $year)
            ->whereMonth('leave_date', $month)
            ->delete();

        $inserted = 0;
        foreach ($leaves as $l) {
            // Map status from Excel to our status values
            // If the Excel shows "approved" at dept level = pending_hr per our spec
            // Excel status mapping:
            // approved / موافق عليه at GM level = 'approved'
            // approved / موافق at HR = 'pending_gm'
            // approved at dept / معلق = 'pending_hr'
            // pending / قيد الانتظار = 'pending_dept'
            $status = $this->mapLeaveStatus($l['status'] ?? '');

            Leave::updateOrCreate(
                ['employee_id' => $l['employee_id'], 'leave_date' => $l['date']],
                [
                    'leave_type'  => $l['type'],
                    'status'      => $status,
                    'notes'       => $l['notes'] ?? null,
                    'approved_by' => $l['approved_by'] ?? null,
                ]
            );
            $inserted++;
        }

        return response()->json([
            'success'  => true,
            'inserted' => $inserted,
            'message'  => "تم رفع {$inserted} سجل إجازة",
        ]);
    }

    // Map Excel status text to our status enum
    private function mapLeaveStatus(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        if (in_array($raw, ['approved', 'موافق عليه', 'مقبول'])) return 'approved';
        if (in_array($raw, ['pending_hr', 'hr', 'موافق قسم'])) return 'pending_hr';
        if (in_array($raw, ['pending_gm', 'gm', 'موافق hr'])) return 'pending_gm';
        if (in_array($raw, ['rejected', 'مرفوض'])) return 'rejected';
        return 'pending_dept'; // default = not yet approved
    }

    // GET /api/attendance/daily-position/{date}?department_id=
    // Get daily position report (الموقف اليومي) including CCTV violations as unpaid leave
    public function getDailyPositionReport(string $date, Request $request): JsonResponse
    {
        $dateObj = Carbon::parse($date);
        $month = $dateObj->month;
        $year = $dateObj->year;

        $query = Employee::with('department');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        $employees = $query->get();

        // Get CCTV violations for this date with salary deductions
        $cctvViolations = CCTVViolation::whereDate('violation_date', $date)
            ->where('penalty_days', '>', 0)
            ->get()
            ->keyBy('employee_id');

        // Get all leaves for this date
        $leaves = Leave::whereDate('leave_date', $date)
            ->where('status', 'approved')
            ->get()
            ->keyBy('employee_id');

        // Get fingerprints for this date
        $fingerprints = Fingerprint::whereDate('punch_date', $date)
            ->get()
            ->groupBy('employee_id');

        $results = [];
        $stats = [
            'total_staff' => $employees->count(),
            'actual_attendance' => 0,
            'absences' => 0,
            'leaves_with_salary' => 0,
            'leaves_without_salary' => 0,
            'cctv_deductions' => 0,
        ];

        foreach ($employees as $emp) {
            $empId = $emp->employee_id;
            $hasLeave = isset($leaves[$empId]);
            $hasCCTV = isset($cctvViolations[$empId]);
            $hasPunches = isset($fingerprints[$empId]) && count($fingerprints[$empId]) > 0;

            $status = 'present';
            $leaveType = null;
            $notes = null;

            if ($hasCCTV) {
                // CCTV violation with salary deduction = unpaid leave
                $status = 'unpaid_leave_cctv';
                $leaveType = 'unpaid';
                $notes = 'إجازة بدون راتب - مخالفة CCTV: ' . $cctvViolations[$empId]->description;
                $stats['leaves_without_salary']++;
                $stats['cctv_deductions']++;
            } elseif ($hasLeave) {
                $leave = $leaves[$empId];
                $status = 'leave';
                $leaveType = $leave->leave_type;
                $notes = $leave->leave_type;
                
                if ($leave->leave_type === 'unpaid') {
                    $stats['leaves_without_salary']++;
                } else {
                    $stats['leaves_with_salary']++;
                }
            } elseif (!$hasPunches) {
                $status = 'absent';
                $stats['absences']++;
            } else {
                $stats['actual_attendance']++;
            }

            $results[] = [
                'employee_id' => $empId,
                'name' => $emp->name,
                'department' => $emp->department?->name ?? '—',
                'status' => $status,
                'leave_type' => $leaveType,
                'notes' => $notes,
                'check_in' => $hasPunches ? $fingerprints[$empId]->first()->punch_time : null,
                'check_out' => $hasPunches ? $fingerprints[$empId]->last()->punch_time : null,
                'cctv_violation' => $hasCCTV ? [
                    'description' => $cctvViolations[$empId]->description,
                    'penalty_days' => $cctvViolations[$empId]->penalty_days,
                ] : null,
            ];
        }

        return response()->json([
            'date' => $date,
            'statistics' => $stats,
            'employees' => $results,
        ]);
    }
}
