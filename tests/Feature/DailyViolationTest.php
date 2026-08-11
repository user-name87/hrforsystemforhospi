<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Models\Violation;
use App\Models\WhatsAppLog;
use App\Services\DailyViolationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyViolationTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $day;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->day = Carbon::parse('2025-07-20');
        $this->department = Department::create([
            'name'           => 'Adult BMT',
            'chairman_name'  => 'Dr. Head',
            'chairman_email' => 'head@example.com',
        ]);
    }

    public function test_leaving_before_the_shift_ends_creates_an_early_leave_violation(): void
    {
        $employee = $this->employee('2812');
        $this->schedule($employee, 'M');
        $this->punch($employee, '2025-07-20', '07:00');
        $this->punch($employee, '2025-07-20', '14:20'); // 40 minutes early on an 07:00–15:00 shift

        app(DailyViolationService::class)->generate($this->day);

        $violation = Violation::where('employee_id', '2812')->where('violation_type', 'early_leave')->firstOrFail();

        $this->assertSame(40, $violation->minutes);
        $this->assertSame(3, $violation->violation_row);          // >30 and ≤60 minutes
        $this->assertSame(1, $violation->occurrence_number);
        $this->assertSame('تنبيه مع خصم مدة التأخير', $violation->penalty);
    }

    public function test_arriving_after_the_shift_start_creates_a_late_violation(): void
    {
        $employee = $this->employee('3001');
        $this->schedule($employee, 'M');
        $this->punch($employee, '2025-07-20', '07:10');
        $this->punch($employee, '2025-07-20', '15:00');

        app(DailyViolationService::class)->generate($this->day);

        $violation = Violation::where('employee_id', '3001')->where('violation_type', 'late')->firstOrFail();

        $this->assertSame(10, $violation->minutes);
        $this->assertSame(1, $violation->violation_row);
        $this->assertSame('تنبيه شفوي', $violation->penalty);
    }

    public function test_a_night_shift_ending_next_morning_is_judged_on_the_day_it_started(): void
    {
        $employee = $this->employee('3002');
        $this->schedule($employee, 'N');                 // 21:00 → 05:00 (8h)
        $this->punch($employee, '2025-07-20', '21:00');
        $this->punch($employee, '2025-07-21', '05:00');

        app(DailyViolationService::class)->generate($this->day);

        $this->assertSame(0, Violation::where('employee_id', '3002')->count());
    }

    public function test_no_punch_on_a_scheduled_day_is_an_absence(): void
    {
        $employee = $this->employee('3003');
        $this->schedule($employee, 'M');

        app(DailyViolationService::class)->generate($this->day);

        $violation = Violation::where('employee_id', '3003')->firstOrFail();

        $this->assertSame('absent', $violation->violation_type);
        $this->assertNull($violation->violation_row);
    }

    public function test_an_approved_leave_raises_no_violation(): void
    {
        $employee = $this->employee('3004');
        $this->schedule($employee, 'M');
        Leave::create([
            'employee_id' => $employee->employee_id,
            'leave_date'  => $this->day->toDateString(),
            'leave_type'  => 'annual',
            'status'      => 'approved',
        ]);

        app(DailyViolationService::class)->generate($this->day);

        $this->assertSame(0, Violation::where('employee_id', '3004')->count());
    }

    public function test_rerunning_a_day_updates_instead_of_duplicating(): void
    {
        $employee = $this->employee('3005');
        $this->schedule($employee, 'M');
        $this->punch($employee, '2025-07-20', '07:20');
        $this->punch($employee, '2025-07-20', '15:00');

        app(DailyViolationService::class)->generate($this->day);
        app(DailyViolationService::class)->generate($this->day);

        $this->assertSame(1, Violation::where('employee_id', '3005')->count());
        $this->assertSame(1, Violation::where('employee_id', '3005')->first()->occurrence_number);
    }

    public function test_the_report_endpoint_summarises_and_filters_the_day(): void
    {
        $late = $this->employee('3006');
        $this->schedule($late, 'M');
        $this->punch($late, '2025-07-20', '07:25');
        $this->punch($late, '2025-07-20', '15:00');

        $this->postJson('/api/daily-violations/2025-07-20/generate')->assertOk();

        $this->getJson('/api/daily-violations/2025-07-20')
            ->assertOk()
            ->assertJsonPath('summary.late', 1)
            ->assertJsonPath('window.from', '2025-07-20 07:00')
            ->assertJsonPath('window.to', '2025-07-21 06:00');

        $this->getJson('/api/daily-violations/2025-07-20?violation_type=absent')
            ->assertOk()
            ->assertJsonPath('summary.total', 0);
    }

    public function test_notifying_a_violation_renders_the_arabic_message_and_audits_it(): void
    {
        $employee = $this->employee('2812', '07701234567');
        $this->schedule($employee, 'M');
        $this->punch($employee, '2025-07-20', '07:00');
        $this->punch($employee, '2025-07-20', '14:20');

        $this->postJson('/api/daily-violations/2025-07-20/generate')->assertOk();
        $this->postJson('/api/daily-violations/2025-07-20/notify')
            ->assertOk()
            ->assertJsonPath('sent', 1);

        $log = WhatsAppLog::where('employee_id', '2812')->firstOrFail();

        $this->assertSame('+9647701234567', $log->phone_number);
        $this->assertStringContainsString('ترك العمل مبكراً', $log->message);
        $this->assertStringContainsString('الرقم الوظيفي: 2812', $log->message);
        $this->assertNotNull(Violation::where('employee_id', '2812')->first()->notified_at);
    }

    public function test_an_employee_without_a_usable_phone_number_is_reported_as_failed(): void
    {
        $employee = $this->employee('3007', '123');
        $this->schedule($employee, 'M');

        $this->postJson('/api/daily-violations/2025-07-20/generate')->assertOk();
        $this->postJson('/api/daily-violations/2025-07-20/notify')
            ->assertOk()
            ->assertJsonPath('failed', 1);

        $this->assertSame('failed', WhatsAppLog::where('employee_id', '3007')->first()->status);
    }

    private function employee(string $id, string $phone = '07700000000'): Employee
    {
        return Employee::create([
            'employee_id'   => $id,
            'name'          => "Employee {$id}",
            'department_id' => $this->department->id,
            'shift_type'    => '8hr',
            'phone_number'  => $phone,
        ]);
    }

    private function schedule(Employee $employee, string $code): void
    {
        EmployeeSchedule::create([
            'employee_id'   => $employee->employee_id,
            'department_id' => $employee->department_id,
            'month'         => $this->day->month,
            'year'          => $this->day->year,
            'day'           => $this->day->day,
            'shift_code'    => $code,
        ]);
    }

    private function punch(Employee $employee, string $date, string $time): void
    {
        $moment = Carbon::parse($date);

        Fingerprint::create([
            'employee_id' => $employee->employee_id,
            'punch_date'  => $moment->toDateString(),
            'punch_time'  => $time,
            'month'       => $moment->month,
            'year'        => $moment->year,
        ]);
    }
}
