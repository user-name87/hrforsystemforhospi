<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Fingerprint;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/overtime/{employeeId}/{month}/{year}
    // Returns OT breakdown only (lightweight version of attendance report)
    public function calculate(string $employeeId, int $month, int $year): JsonResponse
    {
        $emp    = Employee::where('employee_id', $employeeId)->firstOrFail();
        $report = $this->calc->calculate($emp, $month, $year);

        $summary = $report['summary'];

        $otData = [
            'employee_id'    => $employeeId,
            'employee_name'  => $report['employee']['name'],
            'month'          => $month,
            'year'           => $year,
            'shift_type'     => $emp->shift_type,
            'total_ot_min'   => $summary['total_ot_min'],
            'total_ot_hrs'   => $summary['total_ot_hrs'],
            'ot_payout'      => $summary['ot_payout'],
            'basic_salary'   => $emp->basic_salary,
        ];

        // For 12hr employees, add duty breakdown
        if ($emp->is12HourShift()) {
            $otData['duty_breakdown'] = [
                'base_quota'      => $emp->duty_quota ?? 17,
                'surplus_carryover' => $summary['surplus_carryover'],
                'effective_quota' => $summary['effective_quota'],
                'shifts_done'     => $summary['duty_shifts_done'],
                'ot_shifts'       => max(0, $summary['duty_shifts_done'] - $summary['effective_quota']),
                'ot_hours'        => $summary['total_ot_hrs'],
                'payout_formula'  => $emp->basic_salary > 0
                    ? "({$summary['total_ot_hrs']}h ÷ 8) × ({$emp->basic_salary} ÷ 30) = {$summary['ot_payout']} IQD"
                    : 'لم يتم تعيين الراتب الأساسي',
            ];
        }

        return response()->json($otData);
    }

    // POST /api/overtime/manual — add manual unscheduled OT entry
    public function addManual(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|exists:employees,employee_id',
            'date'        => 'required|date',
            'hours'       => 'required|numeric|min:0.5',
            'reason'      => 'nullable|string',
        ]);

        // Store as a special fingerprint pair (manual source)
        // Start at 07:00, end at 07:00 + hours
        $start  = '07:00';
        $endMin = (int)($validated['hours'] * 60);
        $endH   = str_pad(floor($endMin / 60), 2, '0', STR_PAD_LEFT);
        $endM   = str_pad($endMin % 60, 2, '0', STR_PAD_LEFT);
        $end    = "{$endH}:{$endM}";

        $date   = $validated['date'];
        $empId  = $validated['employee_id'];
        $month  = (int)date('m', strtotime($date));
        $year   = (int)date('Y', strtotime($date));

        Fingerprint::insert([
            ['employee_id'=>$empId,'punch_date'=>$date,'punch_time'=>$start,'punch_type'=>'in', 'source'=>'manual','month'=>$month,'year'=>$year,'created_at'=>now(),'updated_at'=>now()],
            ['employee_id'=>$empId,'punch_date'=>$date,'punch_time'=>$end,  'punch_type'=>'out','source'=>'manual','month'=>$month,'year'=>$year,'created_at'=>now(),'updated_at'=>now()],
        ]);

        return response()->json(['success' => true, 'message' => "تم إضافة {$validated['hours']} ساعة إضافية يدوياً"]);
    }
}
