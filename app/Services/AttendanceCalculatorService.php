<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Models\ShiftException;
use App\Models\DutyCarryover;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * AttendanceCalculatorService
 *
 * The single source of truth for all attendance calculations.
 * All business rules from the spec live here — OT, exceptions,
 * duty carryover, leave approval, resident logic.
 */
class AttendanceCalculatorService
{
    // Shift codes considered as "off / no work expected"
    private const OFF_CODES = ['O', 'OFF', 'H', 'HOL', 'V', 'VAC', 'R', 'REST'];

    // Late thresholds in minutes (for violation categorisation)
    private const LATE_TIER_1 = 15;
    private const LATE_TIER_2 = 30;
    private const LATE_TIER_3 = 60;

    // Maximum allowed hours per day (base + OT combined)
    private const MAX_DAILY_HOURS = 12;

    /**
     * Main entry point.
     * Returns the full attendance report for one employee for one month.
     */
    public function calculate(Employee $employee, int $month, int $year): array
    {
        // ── 1. Load all data for this employee/month ────────────────────
        $scheduleRows = $this->loadSchedule($employee->employee_id, $month, $year);
        $fingerprintsByDay = $this->loadFingerprints($employee->employee_id, $month, $year);
        $leavesByDay   = $this->loadLeaves($employee->employee_id, $month, $year);
        $exception     = $this->loadException($employee->employee_id, $month, $year);
        $carryover     = $this->loadCarryover($employee->employee_id, $month, $year);

        // ── 2. Determine effective shift hours ──────────────────────────
        // Priority: explicit monthly exception → chemo / mixing department rule → base shift.
        $baseShiftHours    = $employee->getBaseShiftHours();
        $chemoShiftHours   = $this->chemoShiftHoursFor($employee);
        $effectiveShiftHours = $exception
            ? $exception->exception_hours
            : ($chemoShiftHours ?? $baseShiftHours);
        $effectiveShiftMin   = (int)($effectiveShiftHours * 60);

        // ── 3. Get effective duty quota (for 12hr employees) ────────────
        $baseQuota      = $employee->duty_quota ?? 17;
        $surplusShifts  = $carryover ? $carryover->surplus_shifts : 0;
        $effectiveQuota = max(0, $baseQuota - $surplusShifts);

        // ── 4. Build calendar days ──────────────────────────────────────
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        $days = [];

        $totalPresent        = 0;
        $totalAbsent         = 0;
        $totalLeave          = 0;
        $totalOff            = 0;
        $totalWorkedMin      = 0;
        $totalOtMin          = 0;
        $totalLateMin        = 0;
        $lateDays            = 0;
        $totalEarlyLeaveMin  = 0;
        $earlyLeaveDays      = 0;
        $dutyShiftsDone      = 0;  // for 12hr employees

        for ($d = 1; $d <= $daysInMonth; $d++) {
            $date       = Carbon::create($year, $month, $d);
            $dateStr    = $date->toDateString();
            $schedCode  = $scheduleRows[$d] ?? null;
            $punches    = $fingerprintsByDay[$d] ?? [];
            $leave      = $leavesByDay[$d] ?? null;

            $isOff      = $this->isOff($schedCode);
            $hasLeave   = $leave && $leave->isApprovedForAttendance();

            // ── Resident / Specialist: ignore schedule, count hours only ─
            if ($employee->isResident() || $employee->classification === 'specialist') {
                $day = $this->calcResidentDay($dateStr, $punches);
                $totalWorkedMin += $day['workedMin'];
                $days[] = $day;
                continue;
            }

            // ── Off day (no schedule) ───────────────────────────────────
            if ($isOff || $schedCode === null) {
                // Could still have worked (unscheduled OT) — check fingerprints
                if (!empty($punches)) {
                    $punchCalc   = $this->calcPunches($punches);
                    $workedMin   = $punchCalc['workedMin'];
                    $totalWorkedMin += $workedMin;
                    if ($employee->is12HourShift()) {
                        $dutyShiftsDone++;
                    }
                    $days[] = [
                        'date'       => $dateStr,
                        'dayNum'     => $d,
                        'schedCode'  => $schedCode ?? 'OFF',
                        'status'     => 'off_with_work',
                        'checkIn'    => $punchCalc['checkIn'],
                        'checkOut'   => $punchCalc['checkOut'],
                        'workedMin'  => $workedMin,
                        'otMin'      => 0,   // off-day work = unscheduled OT, calculated separately
                        'lateMin'    => 0,
                        'earlyMin'   => 0,
                        'leaveType'  => null,
                        'note'       => 'عمل في يوم راحة',
                    ];
                } else {
                    $totalOff++;
                    $days[] = [
                        'date'      => $dateStr,
                        'dayNum'    => $d,
                        'schedCode' => $schedCode ?? 'OFF',
                        'status'    => 'off',
                        'checkIn'   => null, 'checkOut' => null,
                        'workedMin' => 0, 'otMin' => 0,
                        'lateMin'   => 0, 'earlyMin' => 0,
                        'leaveType' => null, 'note' => null,
                    ];
                }
                continue;
            }

            // ── Leave day ───────────────────────────────────────────────
            if ($hasLeave) {
                // Leave counts as full shift worked (per spec rule 11)
                $totalLeave++;
                $totalWorkedMin += $effectiveShiftMin;
                if ($employee->is12HourShift()) {
                    $dutyShiftsDone++;
                }
                $days[] = [
                    'date'      => $dateStr,
                    'dayNum'    => $d,
                    'schedCode' => $schedCode,
                    'status'    => 'leave',
                    'checkIn'   => null, 'checkOut' => null,
                    'workedMin' => $effectiveShiftMin,
                    'otMin'     => 0,
                    'lateMin'   => 0, 'earlyMin' => 0,
                    'leaveType' => $leave->leave_type,
                    'note'      => $leave->leave_type,
                ];
                continue;
            }

            // ── Working day — no punches (absent) ──────────────────────
            if (empty($punches)) {
                // Could be a pending leave (not yet approved = absent)
                $pendingLeave = $leavesByDay[$d] ?? null;
                $totalAbsent++;
                $days[] = [
                    'date'      => $dateStr,
                    'dayNum'    => $d,
                    'schedCode' => $schedCode,
                    'status'    => 'absent',
                    'checkIn'   => null, 'checkOut' => null,
                    'workedMin' => 0, 'otMin' => 0,
                    'lateMin'   => 0, 'earlyMin' => 0,
                    'leaveType' => $pendingLeave?->leave_type,
                    'note'      => $pendingLeave ? 'إجازة معلقة عند القسم' : null,
                ];
                continue;
            }

            // ── Working day with punches ────────────────────────────────
            $punchCalc = $this->calcPunches($punches);
            $workedMin = $punchCalc['workedMin'];

            // Get expected start time from schedule code
            $expectedStart = $this->getExpectedStart($schedCode);

            // Late calculation
            $lateMin = 0;
            if ($expectedStart && $punchCalc['checkIn']) {
                $checkInTime = Carbon::createFromFormat('H:i', $punchCalc['checkIn']);
                $expected    = Carbon::createFromFormat('H:i', $expectedStart);
                $lateMin     = max(0, $checkInTime->diffInMinutes($expected, false) * -1);
                // diffInMinutes(false) = negative if checkIn is AFTER expected
                $lateMin     = max(0, $checkInTime->diffInMinutes($expected) * ($checkInTime->gt($expected) ? 1 : 0));
            }

            // Early leave calculation
            $earlyMin = 0;
            $expectedEnd = $this->getExpectedEnd($schedCode, $effectiveShiftHours);
            if ($expectedEnd && $punchCalc['checkOut']) {
                $checkOutTime = Carbon::createFromFormat('H:i', $punchCalc['checkOut']);
                $expectedEndTime = Carbon::createFromFormat('H:i', $expectedEnd);
                if ($checkOutTime->lt($expectedEndTime)) {
                    $earlyMin = $checkOutTime->diffInMinutes($expectedEndTime);
                }
            }

            // OT calculation for standard shift employees (7/8hr)
            $otMin = 0;
            if (!$employee->is12HourShift()) {
                $rawOt = max(0, $workedMin - $effectiveShiftMin);
                // Cap: total (base + OT) cannot exceed MAX_DAILY_HOURS
                $maxOtAllowed = (self::MAX_DAILY_HOURS * 60) - $effectiveShiftMin;
                $otMin = min($rawOt, $maxOtAllowed);
            }

            if ($lateMin > 0) {
                $lateDays++;
                $totalLateMin += $lateMin;
            }
            if ($earlyMin > 0) {
                $earlyLeaveDays++;
                $totalEarlyLeaveMin += $earlyMin;
            }

            $totalPresent++;
            $totalWorkedMin += $workedMin;
            $totalOtMin     += $otMin;

            if ($employee->is12HourShift()) {
                $dutyShiftsDone++;
            }

            $days[] = [
                'date'      => $dateStr,
                'dayNum'    => $d,
                'schedCode' => $schedCode,
                'status'    => 'present',
                'checkIn'   => $punchCalc['checkIn'],
                'checkOut'  => $punchCalc['checkOut'],
                'workedMin' => $workedMin,
                'otMin'     => $otMin,
                'lateMin'   => $lateMin,
                'earlyMin'  => $earlyMin,
                'leaveType' => null,
                'note'      => null,
            ];
        }

        // ── 5. OT for 12hr shift employees (duty-based) ─────────────────
        if ($employee->is12HourShift()) {
            $otShifts      = max(0, $dutyShiftsDone - $effectiveQuota);
            $totalOtMin    = $otShifts * 12 * 60;
        }

        // ── 6. Required hours ────────────────────────────────────────────
        // Working days = scheduled days (not off)
        $workingDays   = count(array_filter($days, fn($d) => !in_array($d['status'], ['off'])));
        $requiredMin   = $workingDays * $effectiveShiftMin;

        // For residents / specialists: use contract hours if available
        if ($employee->isResident() || $employee->classification === 'specialist') {
            $contract = \App\Models\DoctorContract::activeFor(
                $employee->employee_id,
                \Carbon\Carbon::create($year, $month, 1)->toDateString()
            );
            $contractHours = $contract
                ? $contract->monthly_hours
                : (float) config('app.resident_default_hours', 160);
            $requiredMin = (int)($contractHours * 60);
        }

        // For chemo mixing duty employees: use reduced_days (23) instead of scheduled days
        $chemoDuty = \App\Models\ChemoMixingDuty::where('employee_id', $employee->employee_id)
            ->where('month', $month)
            ->where('year', $year)
            ->first();
        if ($chemoDuty && !$employee->isResident()) {
            $reducedDays = $chemoDuty->reduced_days ?: (int) config('app.chemo_duty_reduced_days', 23);
            $requiredMin = $reducedDays * $effectiveShiftMin;
        }

        // ── 7. OT cash payout (for 12hr shift employees) ────────────────
        $otPayout = 0;
        if ($employee->is12HourShift() && $employee->basic_salary > 0 && $totalOtMin > 0) {
            $otPayout = $this->calcOtPayout($totalOtMin, $employee->basic_salary);
        }

        // ── 8. Violation summary ─────────────────────────────────────────
        $violations = $this->buildViolationSummary($days, $employee);

        return [
            'employee'       => [
                'id'             => $employee->employee_id,
                'name'           => $employee->name,
                'department'     => $employee->department?->name,
                'shift_type'     => $employee->shift_type,
                'shift_hours'    => $baseShiftHours,
                'classification' => $employee->classification,
                'basic_salary'   => $employee->basic_salary,
                'is_resident'    => $employee->isResident(),
            ],
            'period'         => ['month' => $month, 'year' => $year],
            'exception'      => $exception ? [
                'original_hours'  => $exception->original_hours,
                'exception_hours' => $exception->exception_hours,
                'reason'          => $exception->reason,
            ] : ($chemoShiftHours !== null ? [
                'original_hours'  => $baseShiftHours,
                'exception_hours' => $chemoShiftHours,
                'reason'          => 'قسم الكيمياء / المزج — وردية ' . $chemoShiftHours . ' ساعات',
            ] : null),
            'carryover'      => $carryover ? [
                'surplus_shifts'  => $carryover->surplus_shifts,
                'from_month'      => $carryover->from_month,
                'from_year'       => $carryover->from_year,
            ] : null,
            'summary'        => [
                'days_present'        => $totalPresent,
                'days_absent'         => $totalAbsent,
                'days_leave'          => $totalLeave,
                'days_off'            => $totalOff,
                'total_worked_min'    => $totalWorkedMin,
                'total_worked_hrs'    => round($totalWorkedMin / 60, 2),
                'required_min'        => $requiredMin,
                'required_hrs'        => round($requiredMin / 60, 2),
                'total_ot_min'        => $totalOtMin,
                'total_ot_hrs'        => round($totalOtMin / 60, 2),
                'ot_payout'           => $otPayout,
                'late_days'           => $lateDays,
                'late_total_min'      => $totalLateMin,
                'early_leave_days'    => $earlyLeaveDays,
                'early_leave_min'     => $totalEarlyLeaveMin,
                'working_days'        => $workingDays,
                'duty_shifts_done'    => $dutyShiftsDone,
                'effective_quota'     => $effectiveQuota,
                'surplus_carryover'   => $surplusShifts,
                'effective_shift_hrs' => $effectiveShiftHours,
            ],
            'violations'     => $violations,
            'days'           => $days,
        ];
    }

    /**
     * Chemo / IV-mixing departments work a shorter shift (7h instead of 8h)
     * regardless of what the uploaded Excel schedule says.
     * Returns null when the employee is not in such a department.
     */
    private function chemoShiftHoursFor(Employee $employee): ?float
    {
        $depName = $employee->department?->name;
        if (!$depName) return null;

        foreach ((array) config('app.chemo_department_keywords', []) as $keyword) {
            if (mb_stripos($depName, $keyword) !== false) {
                return (float) config('app.chemo_shift_hours', 7);
            }
        }
        return null;
    }

    // ── Resident day (only count hours, no schedule checking) ────────────
    private function calcResidentDay(string $dateStr, array $punches): array
    {
        if (empty($punches)) {
            return [
                'date' => $dateStr, 'dayNum' => (int)substr($dateStr, 8, 2),
                'schedCode' => null, 'status' => 'no_punch',
                'checkIn' => null, 'checkOut' => null,
                'workedMin' => 0, 'otMin' => 0, 'lateMin' => 0, 'earlyMin' => 0,
                'leaveType' => null, 'note' => 'طبيب مقيم - لا توجد بصمة',
            ];
        }
        $calc = $this->calcPunches($punches);
        return [
            'date'      => $dateStr,
            'dayNum'    => (int)substr($dateStr, 8, 2),
            'schedCode' => 'RESIDENT',
            'status'    => 'present',
            'checkIn'   => $calc['checkIn'],
            'checkOut'  => $calc['checkOut'],
            'workedMin' => $calc['workedMin'],
            'otMin'     => 0,
            'lateMin'   => 0,
            'earlyMin'  => 0,
            'leaveType' => null,
            'note'      => 'طبيب مقيم',
        ];
    }

    // ── Punch pair → check-in, check-out, worked minutes ─────────────────
    private function calcPunches(array $punches): array
    {
        if (empty($punches)) {
            return ['checkIn' => null, 'checkOut' => null, 'workedMin' => 0];
        }

        // Sort punches by time
        usort($punches, fn($a, $b) => strcmp($a->punch_time, $b->punch_time));

        $first = $punches[0]->punch_time;
        $last  = end($punches)->punch_time;

        if (count($punches) === 1) {
            // Only one punch — can't calculate worked time reliably
            return ['checkIn' => $first, 'checkOut' => null, 'workedMin' => 0];
        }

        $checkIn  = substr($first, 0, 5);
        $checkOut = substr($last, 0, 5);

        $inCarbon  = Carbon::createFromFormat('H:i', $checkIn);
        $outCarbon = Carbon::createFromFormat('H:i', $checkOut);

        // Handle overnight shifts (e.g. night shift 22:00 → 06:00)
        if ($outCarbon->lt($inCarbon)) {
            $outCarbon->addDay();
        }

        $workedMin = $inCarbon->diffInMinutes($outCarbon);

        return [
            'checkIn'   => $checkIn,
            'checkOut'  => $checkOut,
            'workedMin' => $workedMin,
        ];
    }

    // ── OT cash payout for 12hr shift employees ───────────────────────────
    // Formula: (OT_hours / 8) × (basic_salary / 30)
    private function calcOtPayout(int $otMinutes, float $salary): float
    {
        $otHours   = $otMinutes / 60;
        $otDays    = $otHours / 8;
        $dailyRate = $salary / 30;
        return round($otDays * $dailyRate, 2);
    }

    // ── Build violation summary from day results ───────────────────────────
    private function buildViolationSummary(array $days, Employee $employee): array
    {
        $violations = [];
        foreach ($days as $day) {
            if ($day['lateMin'] > 0) {
                $tier = $this->lateTier($day['lateMin']);
                $violations[] = [
                    'date' => $day['date'],
                    'type' => 'late',
                    'minutes' => $day['lateMin'],
                    'tier'    => $tier,
                    'description' => $this->lateTierDescription($tier),
                ];
            }
            if ($day['status'] === 'absent') {
                $violations[] = [
                    'date' => $day['date'],
                    'type' => 'absent',
                    'minutes' => 0,
                    'tier' => null,
                    'description' => 'غياب بدون إذن',
                ];
            }
        }
        return $violations;
    }

    private function lateTier(int $minutes): int
    {
        if ($minutes <= self::LATE_TIER_1) return 1;
        if ($minutes <= self::LATE_TIER_2) return 2;
        if ($minutes <= self::LATE_TIER_3) return 3;
        return 4;
    }

    private function lateTierDescription(int $tier): string
    {
        return match($tier) {
            1 => 'تأخير حتى 15 دقيقة',
            2 => 'تأخير 15-30 دقيقة',
            3 => 'تأخير 30-60 دقيقة',
            4 => 'تأخير أكثر من 60 دقيقة',
            default => '—',
        };
    }

    // ── Data loaders ──────────────────────────────────────────────────────

    private function loadSchedule(string $empId, int $month, int $year): array
    {
        return EmployeeSchedule::where('employee_id', $empId)
            ->where('month', $month)
            ->where('year', $year)
            ->pluck('shift_code', 'day')  // [day => shift_code]
            ->toArray();
    }

    private function loadFingerprints(string $empId, int $month, int $year): array
    {
        $punches = Fingerprint::where('employee_id', $empId)
            ->where('month', $month)
            ->where('year', $year)
            ->orderBy('punch_date')
            ->orderBy('punch_time')
            ->get();

        $byDay = [];
        foreach ($punches as $p) {
            $day = (int)$p->punch_date->day;
            $byDay[$day][] = $p;
        }
        return $byDay;
    }

    private function loadLeaves(string $empId, int $month, int $year): array
    {
        $leaves = Leave::where('employee_id', $empId)
            ->whereYear('leave_date', $year)
            ->whereMonth('leave_date', $month)
            ->get();

        $byDay = [];
        foreach ($leaves as $l) {
            $day = (int)$l->leave_date->day;
            $byDay[$day] = $l;
        }
        return $byDay;
    }

    private function loadException(string $empId, int $month, int $year): ?ShiftException
    {
        return ShiftException::where('employee_id', $empId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();
    }

    private function loadCarryover(string $empId, int $month, int $year): ?DutyCarryover
    {
        return DutyCarryover::where('employee_id', $empId)
            ->where('applied_month', $month)
            ->where('applied_year', $year)
            ->first();
    }

    // ── Schedule code → expected times ───────────────────────────────────
    // These are example times — adjust to your hospital's actual shift times
    private function getExpectedStart(string $code): ?string
    {
        return match(strtoupper(trim($code))) {
            'M', 'ص', 'S'  => '07:00',  // Morning
            'E', 'م', 'A'  => '14:00',  // Afternoon/Evening
            'N', 'ل', 'L'  => '21:00',  // Night
            '12', 'D'       => '07:00',  // 12-hour day
            default          => null,
        };
    }

    private function getExpectedEnd(string $code, float $shiftHours): ?string
    {
        $start = $this->getExpectedStart($code);
        if (!$start) return null;
        $startCarbon = Carbon::createFromFormat('H:i', $start);
        return $startCarbon->addHours($shiftHours)->format('H:i');
    }

    private function isOff(?string $code): bool
    {
        if ($code === null) return true;
        return in_array(strtoupper(trim($code)), self::OFF_CODES);
    }

    // ── Required hours preview (for the exception modal) ──────────────────
    // Used by the API to preview the formula before saving
    public function previewException(
        string $empId,
        int    $month,
        int    $year,
        float  $newShiftHours
    ): array {
        $scheduleRows = $this->loadSchedule($empId, $month, $year);
        $workingDays  = count(array_filter(
            $scheduleRows,
            fn($code) => !$this->isOff($code)
        ));

        $employee        = Employee::where('employee_id', $empId)->firstOrFail();
        $originalHours   = $employee->getBaseShiftHours();
        $originalReqMin  = $workingDays * $originalHours * 60;
        $newReqMin       = $workingDays * $newShiftHours * 60;

        return [
            'working_days'        => $workingDays,
            'original_hours'      => $originalHours,
            'new_hours'           => $newShiftHours,
            'original_required_hrs' => round($originalReqMin / 60, 1),
            'new_required_hrs'    => round($newReqMin / 60, 1),
            'difference_hrs'      => round(($originalReqMin - $newReqMin) / 60, 1),
            'formula'             => "{$originalHours}h × {$workingDays} days = " . round($originalReqMin/60,1) . "h → {$newShiftHours}h × {$workingDays} = " . round($newReqMin/60,1) . "h",
        ];
    }
}
