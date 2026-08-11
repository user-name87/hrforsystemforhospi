<?php

namespace App\Services;

use App\Models\CCTVViolation;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Models\Violation;
use App\Support\ViolationPolicy;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the daily violation picture (الموقف اليومي للمخالفات) for one attendance
 * day — 07:00 of the given date until 06:00 the next morning, so night shifts are
 * attributed to the day they started on.
 */
class DailyViolationService
{
    private const OFF_CODES = ['O', 'OFF', 'H', 'HOL', 'V', 'VAC', 'R', 'REST'];

    /**
     * Recalculate the attendance violations of one day and persist them.
     * Re-running the same day updates the existing records instead of duplicating.
     */
    public function generate(Carbon $date, ?int $departmentId = null): array
    {
        $day       = $date->copy()->startOfDay();
        $employees = $this->employeesFor($departmentId);
        $schedules = $this->schedulesFor($day, $employees->pluck('employee_id'));
        $punches   = $this->punchesFor($day, $employees->pluck('employee_id'));
        $leaves    = $this->leavesFor($day, $employees->pluck('employee_id'));

        $created = [];

        foreach ($employees as $employee) {
            $shiftCode = $schedules[$employee->employee_id] ?? null;

            // Nothing is expected from an employee who is off, on approved leave,
            // or who has no schedule row for the day.
            if ($shiftCode === null || $this->isOff($shiftCode)) continue;
            if (isset($leaves[$employee->employee_id])) continue;

            // Residents and specialists are measured against monthly contract hours,
            // not against a daily shift, so they raise no daily attendance violation.
            if ($employee->isResident() || $employee->classification === 'specialist') continue;

            $employeePunches = $punches[$employee->employee_id] ?? collect();

            if ($employeePunches->isEmpty()) {
                $created[] = $this->record($employee, $day, 'absent', null, 0, 'لا توجد بصمة خلال الوردية');
                continue;
            }

            $shiftHours   = $this->effectiveShiftHours($employee);
            $expectedIn   = ShiftWindow::expectedStartAt($day, $shiftCode);
            $expectedOut  = ShiftWindow::expectedEndAt($day, $shiftCode, $shiftHours);
            $firstPunch   = $employeePunches->first();
            $lastPunch    = $employeePunches->last();

            if ($expectedIn && $firstPunch->gt($expectedIn)) {
                $lateMin = $expectedIn->diffInMinutes($firstPunch);
                if ($lateMin > 0) {
                    $created[] = $this->record(
                        $employee, $day, 'late', ViolationPolicy::rowForMinutes($lateMin), $lateMin,
                        "تأخير {$lateMin} دقيقة — الحضور " . $firstPunch->format('H:i') .
                        ' والمتوقع ' . $expectedIn->format('H:i')
                    );
                }
            }

            if ($expectedOut && $employeePunches->count() > 1 && $lastPunch->lt($expectedOut)) {
                $earlyMin = $lastPunch->diffInMinutes($expectedOut);
                if ($earlyMin > 0) {
                    $created[] = $this->record(
                        $employee, $day, 'early_leave', ViolationPolicy::rowForMinutes($earlyMin), $earlyMin,
                        "ترك العمل مبكراً {$earlyMin} دقيقة — الخروج " . $lastPunch->format('H:i') .
                        ' والمتوقع ' . $expectedOut->format('H:i')
                    );
                }
            }
        }

        return $created;
    }

    /**
     * The stored violations of one day plus the CCTV-sourced disciplinary rows,
     * shaped for the daily violation report screen.
     */
    public function report(Carbon $date, ?int $departmentId = null, ?string $type = null): array
    {
        $day = $date->copy()->startOfDay();

        $violations = Violation::with('employee.department')
            ->whereDate('incident_date', $day)
            ->when($departmentId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $departmentId)))
            ->when($type && $type !== 'disciplinary', fn ($q) => $q->where('violation_type', $type))
            ->orderBy('violation_type')
            ->get()
            ->map(fn (Violation $v) => $this->presentViolation($v));

        $cctv = collect();
        if (!$type || $type === 'disciplinary') {
            $cctv = CCTVViolation::with('employee.department')
                ->whereDate('violation_date', $day)
                ->when($departmentId, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $departmentId)))
                ->get()
                ->map(fn (CCTVViolation $v) => $this->presentCctv($v));
        }

        $rows = $violations->concat($cctv)->values();

        return [
            'date'      => $day->toDateString(),
            'window'    => [
                'from' => ShiftWindow::start($day)->format('Y-m-d H:i'),
                'to'   => ShiftWindow::end($day)->format('Y-m-d H:i'),
            ],
            'filters'   => ['department_id' => $departmentId, 'violation_type' => $type],
            'summary'   => [
                'total'        => $rows->count(),
                'late'         => $rows->where('violation_type', 'late')->count(),
                'early_leave'  => $rows->where('violation_type', 'early_leave')->count(),
                'absent'       => $rows->where('violation_type', 'absent')->count(),
                'disciplinary' => $rows->where('violation_type', 'disciplinary')->count(),
                'notified'     => $rows->where('notified', true)->count(),
            ],
            'violations' => $rows,
        ];
    }

    private function presentViolation(Violation $v): array
    {
        return [
            'id'              => $v->id,
            'source_table'    => 'violations',
            'employee_id'     => $v->employee_id,
            'employee_name'   => $v->employee?->name,
            'phone_number'    => $v->employee?->phone_number,
            'department_id'   => $v->employee?->department_id,
            'department'      => $v->employee?->department?->name,
            'violation_type'  => $v->violation_type,
            'type_label'      => ViolationPolicy::typeLabel($v->violation_type, $v->violation_row),
            'violation_row'   => $v->violation_row,
            'article'         => ViolationPolicy::article($v->violation_row),
            'minutes'         => $v->minutes,
            'occurrence'      => $v->occurrence_number,
            'penalty'         => $v->penalty,
            'status'          => $v->status,
            'notified'        => $v->notified_at !== null,
            'notes'           => $v->notes,
            'incident_date'   => $v->incident_date?->toDateString(),
        ];
    }

    private function presentCctv(CCTVViolation $v): array
    {
        return [
            'id'              => $v->id,
            'source_table'    => 'cctv_violations',
            'employee_id'     => $v->employee_id,
            'employee_name'   => $v->employee?->name,
            'phone_number'    => $v->employee?->phone_number,
            'department_id'   => $v->employee?->department_id,
            'department'      => $v->employee?->department?->name,
            'violation_type'  => 'disciplinary',
            'type_label'      => $v->violation_type,
            'violation_row'   => null,
            'article'         => 'مخالفة انضباطية',
            'minutes'         => 0,
            'occurrence'      => null,
            'penalty'         => $v->penalty_days > 0
                ? "خصم مرتب {$v->penalty_days} يوم عمل"
                : 'إنذار',
            'status'          => 'recorded',
            'notified'        => false,
            'notes'           => $v->description,
            'incident_date'   => $v->violation_date?->toDateString(),
        ];
    }

    private function record(Employee $employee, Carbon $day, string $type, ?int $row, int $minutes, string $notes): Violation
    {
        $existing = Violation::where('employee_id', $employee->employee_id)
            ->where('violation_type', $type)
            ->whereDate('incident_date', $day)
            ->first() ?? new Violation([
                'employee_id'    => $employee->employee_id,
                'violation_type' => $type,
                'incident_date'  => $day->toDateString(),
            ]);

        $occurrence = $existing->exists
            ? $existing->occurrence_number
            : $this->occurrenceNumber($employee->employee_id, $type, $row, $day);

        $existing->fill([
            'violation_category' => ViolationPolicy::typeLabel($type, $row),
            'violation_row'      => $row,
            'minutes'            => $minutes,
            'source'             => 'attendance_engine',
            'occurrence_number'  => $occurrence,
            'penalty'            => ViolationPolicy::penalty($row, $occurrence),
            'notes'              => $notes,
        ])->save();

        return $existing;
    }

    /**
     * How many times this employee already committed the same article this year,
     * counting the violation being recorded. Earlier days only, so re-running a
     * day never inflates the ladder.
     */
    private function occurrenceNumber(string $employeeId, string $type, ?int $row, Carbon $day): int
    {
        $previous = Violation::where('employee_id', $employeeId)
            ->whereYear('incident_date', $day->year)
            ->whereDate('incident_date', '<', $day->toDateString())
            ->when($row === null,
                fn ($q) => $q->where('violation_type', $type)->whereNull('violation_row'),
                fn ($q) => $q->where('violation_row', $row)
            )
            ->count();

        return $previous + 1;
    }

    private function employeesFor(?int $departmentId): Collection
    {
        return Employee::with('department')
            ->when($departmentId, fn ($q) => $q->where('department_id', $departmentId))
            ->get();
    }

    /** @return array<string,string> employee_id => shift code */
    private function schedulesFor(Carbon $day, Collection $employeeIds): array
    {
        return EmployeeSchedule::whereIn('employee_id', $employeeIds)
            ->where('year', $day->year)
            ->where('month', $day->month)
            ->where('day', $day->day)
            ->pluck('shift_code', 'employee_id')
            ->toArray();
    }

    /** @return array<string,Collection<Carbon>> employee_id => punch moments inside the window */
    private function punchesFor(Carbon $day, Collection $employeeIds): array
    {
        $from = ShiftWindow::start($day);
        $to   = ShiftWindow::end($day);

        return Fingerprint::whereIn('employee_id', $employeeIds)
            ->whereBetween('punch_date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->map(fn (Fingerprint $p) => [
                'employee_id' => $p->employee_id,
                'moment'      => Carbon::parse($p->punch_date->toDateString() . ' ' . $p->punch_time),
            ])
            ->filter(fn (array $p) => $p['moment']->between($from, $to))
            ->groupBy('employee_id')
            ->map(fn (Collection $group) => $group->pluck('moment')->sort()->values())
            ->all();
    }

    /** @return array<string,Leave> employee_id => approved leave covering the day */
    private function leavesFor(Carbon $day, Collection $employeeIds): array
    {
        return Leave::whereIn('employee_id', $employeeIds)
            ->whereDate('leave_date', $day)
            ->get()
            ->filter(fn (Leave $l) => $l->isApprovedForAttendance())
            ->keyBy('employee_id')
            ->all();
    }

    private function effectiveShiftHours(Employee $employee): float
    {
        $depName = $employee->department?->name;

        if ($depName) {
            foreach ((array) config('app.chemo_department_keywords', []) as $keyword) {
                if (mb_stripos($depName, $keyword) !== false) {
                    return (float) config('app.chemo_shift_hours', 7);
                }
            }
        }

        return $employee->getBaseShiftHours();
    }

    private function isOff(?string $code): bool
    {
        return $code === null || in_array(mb_strtoupper(trim($code)), self::OFF_CODES, true);
    }
}
