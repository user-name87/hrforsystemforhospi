<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $table      = 'employees';
    protected $primaryKey = 'id';
    public    $timestamps = true;

    protected $fillable = [
        'employee_id',
        'name',
        'department_id',
        'job_title',
        'shift_type',       // '7hr' | '8hr' | '12hr'
        'nationality',
        'basic_salary',
        'full_or_part',     // 'full' | 'part'
        'classification',   // 'resident' | null
        'duty_quota',       // default 17 (only relevant for 12hr)
    ];

    protected $casts = [
        'basic_salary' => 'float',
        'duty_quota'   => 'integer',
    ];

    // ── Relationships ─────────────────────────────────────────────────────

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class, 'employee_id', 'employee_id');
    }

    public function fingerprints(): HasMany
    {
        return $this->hasMany(Fingerprint::class, 'employee_id', 'employee_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class, 'employee_id', 'employee_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class, 'employee_id', 'employee_id');
    }

    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(DisciplinaryAction::class, 'employee_id', 'employee_id');
    }

    public function shiftExceptions(): HasMany
    {
        return $this->hasMany(ShiftException::class, 'employee_id', 'employee_id');
    }

    public function dutyCarryovers(): HasMany
    {
        return $this->hasMany(DutyCarryover::class, 'employee_id', 'employee_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    public function isResident(): bool
    {
        return $this->classification === 'resident';
    }

    public function is12HourShift(): bool
    {
        return $this->shift_type === '12hr';
    }

    public function getBaseShiftHours(): float
    {
        return match($this->shift_type) {
            '7hr'  => 7.0,
            '12hr' => 12.0,
            default => 8.0,
        };
    }
}
