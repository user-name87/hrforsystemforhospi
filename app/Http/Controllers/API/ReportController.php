<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Employee;
use App\Models\MonthlySchedule;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/reports/employee/{employeeId}/{month}/{year}
    // Full combined report for one employee — what the big HTML report page shows
    public function employeeMonthly(string $employeeId, int $month, int $year): JsonResponse
    {
        $emp    = Employee::with(['department', 'violations', 'disciplinaryActions'])
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        $report = $this->calc->calculate($emp, $month, $year);

        // Append violations from DB (both auto-calculated and manual)
        $report['disciplinary_actions'] = $emp->disciplinaryActions()
            ->orderBy('created_at', 'desc')
            ->get();

        $report['manual_violations'] = $emp->violations()
            ->whereMonth('incident_date', $month)
            ->whereYear('incident_date', $year)
            ->get();

        return response()->json($report);
    }

    // GET /api/reports/department/{depId}/{month}/{year}
    public function departmentMonthly(int $depId, int $month, int $year): JsonResponse
    {
        $dep       = Department::findOrFail($depId);
        $employees = Employee::where('department_id', $depId)->get();

        $rows = $employees->map(function ($emp) use ($month, $year) {
            $r = $this->calc->calculate($emp, $month, $year);
            $s = $r['summary'];

            // Flatten to table row (matches the HTML report columns)
            return [
                'employee_id'       => $emp->employee_id,
                'name'              => $emp->name,
                'job_title'         => $emp->job_title,
                'shift_type'        => $emp->shift_type,
                'classification'    => $emp->classification,
                'days_present'      => $s['days_present'],
                'days_absent'       => $s['days_absent'],
                'days_leave'        => $s['days_leave'],
                'days_off'          => $s['days_off'],
                'late_days'         => $s['late_days'],
                'late_total_min'    => $s['late_total_min'],
                'early_leave_days'  => $s['early_leave_days'],
                'early_leave_min'   => $s['early_leave_min'],
                'total_worked_hrs'  => $s['total_worked_hrs'],
                'required_hrs'      => $s['required_hrs'],
                'total_ot_hrs'      => $s['total_ot_hrs'],
                'ot_payout'         => $s['ot_payout'],
                'violations_count'  => count($r['violations']),
                'exception'         => $r['exception'],
                'carryover'         => $r['carryover'],
            ];
        });

        return response()->json([
            'department' => [
                'id'   => $dep->id,
                'name' => $dep->name,
            ],
            'month'    => $month,
            'year'     => $year,
            'schedule_uploaded' => MonthlySchedule::where('department_id', $depId)
                ->where('month', $month)->where('year', $year)->exists(),
            'employees' => $rows,
            'totals' => [
                'present'    => $rows->sum('days_present'),
                'absent'     => $rows->sum('days_absent'),
                'leave'      => $rows->sum('days_leave'),
                'ot_hrs'     => $rows->sum('total_ot_hrs'),
                'ot_payout'  => $rows->sum('ot_payout'),
                'late_days'  => $rows->sum('late_days'),
            ],
        ]);
    }

    // GET /api/reports/kpi/{month}/{year}
    // Dashboard homepage KPI cards
    public function kpi(int $month, int $year): JsonResponse
    {
        $totalDeps     = Department::count();
        $uploadedDeps  = MonthlySchedule::where('month', $month)->where('year', $year)->count();
        $totalEmps     = Employee::count();
        $residents     = Employee::where('classification', 'resident')->count();

        return response()->json([
            'month'             => $month,
            'year'              => $year,
            'total_departments' => $totalDeps,
            'uploaded_schedules'=> $uploadedDeps,
            'missing_schedules' => $totalDeps - $uploadedDeps,
            'total_employees'   => $totalEmps,
            'residents'         => $residents,
        ]);
    }
}
