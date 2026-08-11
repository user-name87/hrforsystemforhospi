<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ChemoMixingDuty;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ChemoMixingDutyController extends Controller
{
    // GET /api/chemo-duty?month=&year=&department_id=
    public function index(Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year  = (int)($request->query('year',  now()->year));

        $query = ChemoMixingDuty::with(['employee.department', 'homeDepartment'])
            ->where('month', $month)
            ->where('year',  $year);

        if ($request->filled('department_id')) {
            $query->where('home_department_id', $request->department_id);
        }

        return response()->json($query->get());
    }

    // GET /api/chemo-duty/{employeeId}/{month}/{year}
    public function getForEmployee(string $empId, int $month, int $year): JsonResponse
    {
        $duty = ChemoMixingDuty::where('employee_id', $empId)
            ->where('month', $month)
            ->where('year', $year)
            ->with(['employee', 'homeDepartment'])
            ->first();

        return response()->json($duty);
    }

    // POST /api/chemo-duty
    // Auto-detects chemo duty employees from uploaded schedule:
    // Any employee whose schedule shows fewer days than expected (< 26) is flagged
    // The system compares their scheduled days vs the 23-day reduced quota
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'         => 'required|string|exists:employees,employee_id',
            'home_department_id'  => 'required|integer|exists:departments,id',
            'chemo_department_id' => 'nullable|integer|exists:departments,id',
            'month'               => 'required|integer|between:1,12',
            'year'                => 'required|integer|min:2020',
            'original_days'       => 'nullable|integer',
            'notes'               => 'nullable|string',
        ]);

        $duty = ChemoMixingDuty::updateOrCreate(
            [
                'employee_id' => $validated['employee_id'],
                'month'       => $validated['month'],
                'year'        => $validated['year'],
            ],
            [
                'home_department_id'  => $validated['home_department_id'],
                'chemo_department_id' => $validated['chemo_department_id'] ?? null,
                'reduced_days'        => (int) config('app.chemo_duty_reduced_days', 23),
                'original_days'       => $validated['original_days'] ?? null,
                'notes'               => $validated['notes'] ?? null,
                'confirmed'           => false,
                'email_sent'          => false,
            ]
        );

        return response()->json($duty->load(['employee', 'homeDepartment']), 201);
    }

    // POST /api/chemo-duty/auto-detect/{depId}/{month}/{year}
    // Scans uploaded schedule for employees with reduced days (< 26)
    // and auto-creates chemo_mixing_duty records for them
    public function autoDetect(int $depId, int $month, int $year): JsonResponse
    {
        // Get all employees scheduled in this department
        $schedules = EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->get()
            ->groupBy('employee_id');

        // Standard working days threshold — employees with fewer than this are flagged
        $threshold = 25;
        $detected  = [];

        foreach ($schedules as $empId => $rows) {
            // Count actual working days (non-off shift codes)
            $workingDays = $rows->filter(fn($r) =>
                !in_array(strtoupper(trim($r->shift_code)), ['O','OFF','H','HOL','R','REST',''])
            )->count();

            if ($workingDays <= $threshold && $workingDays > 0) {
                // This employee has reduced days — flag as chemo duty.
                // Chemo mixing duty always reduces the month to the fixed quota (23 days),
                // the scheduled day count is only what triggered the detection.
                $duty = ChemoMixingDuty::updateOrCreate(
                    ['employee_id' => $empId, 'month' => $month, 'year' => $year],
                    [
                        'home_department_id' => $depId,
                        'reduced_days'       => (int) config('app.chemo_duty_reduced_days', 23),
                        'original_days'      => 26, // standard expected
                        'confirmed'          => false,
                        'email_sent'         => false,
                        'notes'              => "اكتشاف تلقائي — الأيام المجدولة في ملف الإكسل: {$workingDays}",
                    ]
                );
                $emp = Employee::where('employee_id', $empId)->first();
                $detected[] = [
                    'employee_id'  => $empId,
                    'name'         => $emp?->name,
                    'working_days' => $workingDays,
                    'duty_id'      => $duty->id,
                ];
            }
        }

        return response()->json([
            'detected' => $detected,
            'count'    => count($detected),
            'message'  => count($detected) > 0
                ? 'تم اكتشاف ' . count($detected) . ' موظف بأيام مخفضة'
                : 'لا يوجد موظفين بأيام مخفضة في هذا القسم',
        ]);
    }

    // POST /api/chemo-duty/{id}/confirm
    public function confirm(Request $request, int $id): JsonResponse
    {
        $duty = ChemoMixingDuty::findOrFail($id);
        $duty->update([
            'confirmed'    => true,
            'confirmed_by' => $request->input('confirmed_by', 'hr'),
            'confirmed_at' => now(),
        ]);

        return response()->json($duty->load(['employee', 'homeDepartment']));
    }

    // POST /api/chemo-duty/{id}/send-email
    // Sends confirmation request to the head of the home department
    public function sendConfirmationEmail(int $id): JsonResponse
    {
        $duty = ChemoMixingDuty::with(['employee', 'homeDepartment'])->findOrFail($id);
        $dep  = $duty->homeDepartment;

        if (!$dep?->chairman_email) {
            return response()->json([
                'success' => false,
                'message' => 'لا يوجد بريد إلكتروني لرئيس القسم',
            ], 422);
        }

        $emp       = $duty->employee;
        $monthNames = [
            1=>'يناير',2=>'فبراير',3=>'مارس',4=>'أبريل',
            5=>'مايو',6=>'يونيو',7=>'يوليو',8=>'أغسطس',
            9=>'سبتمبر',10=>'أكتوبر',11=>'نوفمبر',12=>'ديسمبر',
        ];
        $monthName = $monthNames[$duty->month] ?? $duty->month;

        try {
            Mail::send([], [], function ($message) use ($duty, $dep, $emp, $monthName) {
                $message->to($dep->chairman_email, $dep->chairman_name ?? $dep->name)
                    ->subject("تأكيد مطلوب: واجب الكيمياء لـ{$emp->name} — {$monthName} {$duty->year}")
                    ->html($this->buildEmail($duty, $dep, $emp, $monthName));
            });

            $duty->update(['email_sent' => true, 'email_sent_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => "تم إرسال البريد إلى {$dep->chairman_email}",
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'فشل الإرسال: ' . $e->getMessage(),
            ], 500);
        }
    }

    // POST /api/chemo-duty/send-all-emails/{month}/{year}
    // Sends confirmation emails for ALL unconfirmed chemo duty employees
    public function sendAllEmails(int $month, int $year): JsonResponse
    {
        $duties = ChemoMixingDuty::where('month', $month)
            ->where('year', $year)
            ->where('confirmed', false)
            ->where('email_sent', false)
            ->with(['employee', 'homeDepartment'])
            ->get();

        $results = [];
        foreach ($duties as $duty) {
            $result = $this->sendConfirmationEmail($duty->id);
            $results[] = [
                'employee' => $duty->employee?->name,
                'success'  => $result->getStatusCode() === 200,
            ];
        }

        $sent = collect($results)->where('success', true)->count();
        return response()->json(['sent' => $sent, 'results' => $results]);
    }

    // DELETE /api/chemo-duty/{id}
    public function destroy(int $id): JsonResponse
    {
        ChemoMixingDuty::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    private function buildEmail(ChemoMixingDuty $duty, Department $dep, Employee $emp, string $monthName): string
    {
        $reduced  = $duty->reduced_days;
        $original = $duty->original_days ?? 26;

        return <<<HTML
        <!DOCTYPE html>
        <html dir="rtl" lang="ar">
        <head><meta charset="UTF-8">
        <style>
          body{font-family:Tahoma,Arial,sans-serif;direction:rtl;background:#f5f5f5;margin:0;padding:20px}
          .container{max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.1)}
          .header{background:linear-gradient(135deg,#5C0A15,#C01C2C);color:#fff;padding:24px 28px;text-align:center}
          .header h1{margin:0;font-size:18px}
          .body{padding:28px}
          .body p{font-size:15px;line-height:1.8;color:#333;margin:0 0 14px}
          .highlight{background:#FEF9E7;border-right:4px solid #C01C2C;padding:14px 16px;border-radius:6px;margin:16px 0}
          .data-table{width:100%;border-collapse:collapse;margin:16px 0}
          .data-table td{padding:10px 14px;border:1px solid #eee;font-size:14px}
          .data-table td:first-child{background:#f9f4f4;font-weight:700;width:40%}
          .footer{background:#f9f4f4;padding:16px 28px;text-align:center;font-size:12px;color:#888;border-top:1px solid #eee}
        </style>
        </head>
        <body>
        <div class="container">
          <div class="header">
            <h1>مستشفى الإمام الحسن المجتبى (ع)</h1>
            <p style="margin:6px 0 0;opacity:.85;font-size:13px">نظام الموارد البشرية — طلب تأكيد واجب الكيمياء</p>
          </div>
          <div class="body">
            <p>السلام عليكم ورحمة الله وبركاته،</p>
            <p>الأستاذ/ة <strong>{$dep->chairman_name}</strong>، رئيس <strong>{$dep->name}</strong></p>
            <div class="highlight">
              يُرجى التأكيد على أن الموظف المذكور أدى <strong>واجب تحضير الكيمياء (Chemo Mixing)</strong>
              خلال شهر <strong>{$monthName} {$duty->year}</strong>، مما أدى إلى تخفيض أيام دوامه.
            </div>
            <table class="data-table">
              <tr><td>اسم الموظف</td><td>{$emp->name}</td></tr>
              <tr><td>الرقم الوظيفي</td><td>{$emp->employee_id}</td></tr>
              <tr><td>القسم الأصلي</td><td>{$dep->name}</td></tr>
              <tr><td>الشهر</td><td>{$monthName} {$duty->year}</td></tr>
              <tr><td>الأيام الأصلية</td><td>{$original} يوم</td></tr>
              <tr><td>الأيام بعد واجب الكيمياء</td><td><strong style="color:#C01C2C">{$reduced} يوم</strong></td></tr>
            </table>
            <p>يُرجى الرد على هذا البريد بالتأكيد أو الاعتراض في أقرب وقت ممكن.</p>
            <p>مع التحيات،<br><strong>قسم الموارد البشرية</strong></p>
          </div>
          <div class="footer">هذا البريد تلقائي من نظام HR — مستشفى الإمام الحسن المجتبى (ع)</div>
        </div>
        </body>
        </html>
        HTML;
    }
}
