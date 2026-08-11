<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Services\AttendanceCalculatorService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const MONTH = 7;
    private const YEAR  = 2025;

    public function test_overtime_only_counts_from_a_full_hour_beyond_the_shift(): void
    {
        $employee = $this->employee('4001', '8hr');

        // Day 1: 45 minutes extra — below the one-hour floor, not paid.
        $this->workday($employee, 1, '07:00', '15:45');
        // Day 2: two hours extra.
        $this->workday($employee, 2, '07:00', '17:00');

        $report = $this->calculate($employee);

        $this->assertSame(120, $report['summary']['total_ot_min']);
    }

    public function test_daily_overtime_is_capped_at_twelve_hours_in_total(): void
    {
        $employee = $this->employee('4002', '8hr');
        $this->workday($employee, 1, '07:00', '21:00'); // 14 worked hours

        $report = $this->calculate($employee);

        $this->assertSame(4 * 60, $report['summary']['total_ot_min']);
    }

    public function test_a_twelve_hour_employee_earns_overtime_after_the_seventeenth_duty(): void
    {
        $employee = $this->employee('4003', '12hr', salary: 300000);

        for ($day = 1; $day <= 19; $day++) {
            $this->workday($employee, $day, '07:00', '19:00', 'D');
        }

        $report = $this->calculate($employee);

        $this->assertSame(2, $report['summary']['duty_shifts_done'] - $report['summary']['effective_quota']);
        $this->assertSame(24.0, $report['summary']['total_ot_hrs']);
        // 24h ÷ 8 = 3 days × (300,000 ÷ 30) = 30,000 IQD
        $this->assertSame(30000.0, $report['summary']['ot_payout']);
    }

    public function test_an_approved_leave_counts_as_a_full_worked_shift(): void
    {
        $employee = $this->employee('4004', '8hr');
        $this->schedule($employee, 1, 'M');
        Leave::create([
            'employee_id' => $employee->employee_id,
            'leave_date'  => Carbon::create(self::YEAR, self::MONTH, 1)->toDateString(),
            'leave_type'  => 'annual',
            'status'      => 'approved',
        ]);

        $report = $this->calculate($employee);

        $this->assertSame(1, $report['summary']['days_leave']);
        $this->assertSame(8.0, $report['summary']['total_worked_hrs']);
    }

    public function test_the_chemo_department_shift_is_seven_hours_even_when_the_schedule_says_eight(): void
    {
        $chemo    = Department::create(['name' => 'Chemo Mixing Unit']);
        $employee = $this->employee('4005', '8hr', department: $chemo);

        for ($day = 1; $day <= 26; $day++) {
            $this->schedule($employee, $day, 'M');
        }

        $report = $this->calculate($employee);

        // 26 scheduled days × 7h = 182h required instead of 208h.
        $this->assertSame(7.0, $report['summary']['effective_shift_hrs']);
        $this->assertSame(182.0, $report['summary']['required_hrs']);
    }

    public function test_a_night_shift_that_ends_next_morning_is_counted_on_the_day_it_started(): void
    {
        $employee = $this->employee('4006', '8hr');
        $this->schedule($employee, 20, 'N');
        $this->punch($employee, 20, '21:00');
        $this->punch($employee, 21, '05:00');

        $report = $this->calculate($employee);
        $day20  = collect($report['days'])->firstWhere('dayNum', 20);

        $this->assertSame('present', $day20['status']);
        $this->assertSame(8 * 60, $day20['workedMin']);
        $this->assertSame(0, $day20['earlyMin']);
    }

    private function calculate(Employee $employee): array
    {
        return app(AttendanceCalculatorService::class)
            ->calculate($employee->fresh(), self::MONTH, self::YEAR);
    }

    private function employee(
        string $id,
        string $shiftType,
        ?Department $department = null,
        ?float $salary = null
    ): Employee {
        $department ??= Department::firstOrCreate(['name' => 'General Ward']);

        return Employee::create([
            'employee_id'   => $id,
            'name'          => "Employee {$id}",
            'department_id' => $department->id,
            'shift_type'    => $shiftType,
            'basic_salary'  => $salary,
        ]);
    }

    private function workday(Employee $employee, int $day, string $in, string $out, string $code = 'M'): void
    {
        $this->schedule($employee, $day, $code);
        $this->punch($employee, $day, $in);
        $this->punch($employee, $day, $out);
    }

    private function schedule(Employee $employee, int $day, string $code): void
    {
        EmployeeSchedule::create([
            'employee_id'   => $employee->employee_id,
            'department_id' => $employee->department_id,
            'month'         => self::MONTH,
            'year'          => self::YEAR,
            'day'           => $day,
            'shift_code'    => $code,
        ]);
    }

    private function punch(Employee $employee, int $day, string $time): void
    {
        Fingerprint::create([
            'employee_id' => $employee->employee_id,
            'punch_date'  => Carbon::create(self::YEAR, self::MONTH, $day)->toDateString(),
            'punch_time'  => $time,
            'month'       => self::MONTH,
            'year'        => self::YEAR,
        ]);
    }
}
