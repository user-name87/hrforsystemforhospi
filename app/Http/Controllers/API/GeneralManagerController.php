<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Violation;
use App\Models\DisciplinaryAction;
use App\Models\CCTVViolation;
use App\Models\Leave;
use App\Models\Department;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class GeneralManagerController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/gm/dashboard?date=
    public function dashboard(Request $request): JsonResponse
    {
        $date = $request->filled('date') 
            ? Carbon::parse($request->date) 
            : Carbon::today();

        $month = $date->month;
        $year = $date->year;

        // Get daily violations count
        $dailyViolations = Violation::whereDate('incident_date', $date)->count();
        $dailyCCTVViolations = CCTVViolation::whereDate('violation_date', $date)->count();
        $dailyDisciplinary = DisciplinaryAction::whereDate('created_at', $date)->count();

        // Get daily position report summary
        $attendanceData = $this->getDailyAttendanceSummary($date);

        // Get department schedule upload status
        $uploadedSchedules = Department::whereHas('monthlySchedules', function($q) use ($month, $year) {
            $q->where('month', $month)->where('year', $year);
        })->count();
        $totalDepartments = Department::count();

        // Get recent violations (last 7 days)
        $recentViolations = Violation::with('employee.department')
            ->where('incident_date', '>=', $date->copy()->subDays(7))
            ->orderBy('incident_date', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'date' => $date->toDateString(),
            'daily_summary' => [
                'violations' => $dailyViolations,
                'cctv_violations' => $dailyCCTVViolations,
                'disciplinary_actions' => $dailyDisciplinary,
            ],
            'daily_position' => $attendanceData,
            'schedules_status' => [
                'uploaded' => $uploadedSchedules,
                'total' => $totalDepartments,
                'missing' => $totalDepartments - $uploadedSchedules,
            ],
            'recent_violations' => $recentViolations,
        ]);
    }

    // GET /api/gm/daily-violations?date=&department_id=
    public function dailyViolations(Request $request): JsonResponse
    {
        $date = $request->filled('date') 
            ? Carbon::parse($request->date) 
            : Carbon::today();

        $query = Violation::with(['employee.department'])
            ->whereDate('incident_date', $date);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) => 
                $q->where('department_id', $request->department_id)
            );
        }

        $violations = $query->orderBy('incident_date', 'desc')->get();

        // Add CCTV violations for the same date
        $cctvQuery = CCTVViolation::with(['employee.department'])
            ->whereDate('violation_date', $date);

        if ($request->filled('department_id')) {
            $cctvQuery->whereHas('employee', fn($q) => 
                $q->where('department_id', $request->department_id)
            );
        }

        $cctvViolations = $cctvQuery->get();

        return response()->json([
            'date' => $date->toDateString(),
            'attendance_violations' => $violations,
            'cctv_violations' => $cctvViolations,
            'total_count' => $violations->count() + $cctvViolations->count(),
        ]);
    }

    // GET /api/gm/employee/{employeeId}?month=&year=
    public function employeeDetails(string $employeeId, Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year = (int)($request->query('year', now()->year));

        $emp = Employee::with(['department', 'violations', 'disciplinaryActions'])
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        // Get attendance report
        $attendanceReport = $this->calc->calculate($emp, $month, $year);

        // Get violations for the period
        $violations = Violation::where('employee_id', $employeeId)
            ->whereMonth('incident_date', $month)
            ->whereYear('incident_date', $year)
            ->orderBy('incident_date', 'desc')
            ->get();

        // Get disciplinary actions
        $disciplinary = DisciplinaryAction::where('employee_id', $employeeId)
            ->whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->orderBy('created_at', 'desc')
            ->get();

        // Get CCTV violations
        $cctvViolations = CCTVViolation::where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->orderBy('violation_date', 'desc')
            ->get();

        // Check continuity confirmation eligibility
        $continuityEligible = $this->checkContinuityEligibility($emp, $attendanceReport);

        return response()->json([
            'employee' => [
                'employee_id' => $emp->employee_id,
                'name' => $emp->name,
                'department' => $emp->department?->name,
                'job_title' => $emp->job_title,
                'shift_type' => $emp->shift_type,
                'classification' => $emp->classification,
                'basic_salary' => $emp->basic_salary,
                'nationality' => $emp->nationality,
                'phone_number' => $emp->phone_number,
            ],
            'period' => ['month' => $month, 'year' => $year],
            'attendance_summary' => $attendanceReport['summary'],
            'violations' => $violations,
            'disciplinary_actions' => $disciplinary,
            'cctv_violations' => $cctvViolations,
            'continuity_confirmation' => [
                'eligible' => $continuityEligible,
                'required_hours' => $attendanceReport['summary']['required_hrs'],
                'actual_hours' => $attendanceReport['summary']['total_worked_hrs'],
                'days_present' => $attendanceReport['summary']['days_present'],
                'days_absent' => $attendanceReport['summary']['days_absent'],
                'violations_count' => count($violations) + count($cctvViolations),
            ],
        ]);
    }

    // GET /api/gm/continuity-check/{employeeId}?month=&year=
    public function checkContinuity(string $employeeId, Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year = (int)($request->query('year', now()->year));

        $emp = Employee::where('employee_id', $employeeId)->firstOrFail();
        $attendanceReport = $this->calc->calculate($emp, $month, $year);

        $eligible = $this->checkContinuityEligibility($emp, $attendanceReport);

        return response()->json([
            'employee_id' => $employeeId,
            'employee_name' => $emp->name,
            'period' => ['month' => $month, 'year' => $year],
            'eligible' => $eligible,
            'details' => [
                'required_hours' => $attendanceReport['summary']['required_hrs'],
                'actual_hours' => $attendanceReport['summary']['total_worked_hrs'],
                'attendance_rate' => $attendanceReport['summary']['required_hrs'] > 0 
                    ? round(($attendanceReport['summary']['total_worked_hrs'] / $attendanceReport['summary']['required_hrs']) * 100, 2) 
                    : 0,
                'days_present' => $attendanceReport['summary']['days_present'],
                'days_absent' => $attendanceReport['summary']['days_absent'],
                'violations_count' => count($attendanceReport['violations']),
            ],
        ]);
    }

    // Helper: Get daily attendance summary
    private function getDailyAttendanceSummary(Carbon $date): array
    {
        $totalEmployees = Employee::count();
        
        // Get approved leaves for the date
        $leaves = Leave::whereDate('leave_date', $date)
            ->where('status', 'approved')
            ->get();

        $annualLeaves = $leaves->where('leave_type', 'annual')->count();
        $sickLeaves = $leaves->where('leave_type', 'sick')->count();
        $unpaidLeaves = $leaves->where('leave_type', 'unpaid')->count();

        // Get CCTV violations with salary deductions
        $cctvDeductions = CCTVViolation::whereDate('violation_date', $date)
            ->where('penalty_days', '>', 0)
            ->count();

        return [
            'total_staff' => $totalEmployees,
            'annual_leaves' => $annualLeaves,
            'sick_leaves' => $sickLeaves,
            'unpaid_leaves' => $unpaidLeaves + $cctvDeductions, // Include CCTV deductions
            'cctv_deductions' => $cctvDeductions,
        ];
    }

    // Helper: Check continuity confirmation eligibility
    private function checkContinuityEligibility(Employee $emp, array $attendanceReport): bool
    {
        $summary = $attendanceReport['summary'];
        
        // Basic requirements:
        // 1. Met required hours (at least 95%)
        // 2. No serious violations (penalty > 2 days deduction)
        // 3. Not more than 3 days absent without approved leave
        
        $requiredHours = $summary['required_hrs'];
        $actualHours = $summary['total_worked_hrs'];
        $attendanceRate = $requiredHours > 0 ? ($actualHours / $requiredHours) : 0;

        if ($attendanceRate < 0.95) {
            return false;
        }

        if ($summary['days_absent'] > 3) {
            return false;
        }

        // Check for serious violations
        foreach ($attendanceReport['violations'] as $violation) {
            if (str_contains($violation['penalty'] ?? '', '3 أيام') || 
                str_contains($violation['penalty'] ?? '', '5 أيام')) {
                return false;
            }
        }

        return true;
    }
}