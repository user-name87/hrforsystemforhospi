<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'name',
        'department_id',
        'job_title',
        'shift_type',
        'nationality',
        'basic_salary',
        'full_or_part',
        'classification',
        'duty_quota',
        'phone_number',
    ];

    protected $casts = [
        'basic_salary' => 'decimal:2',
        'duty_quota' => 'integer',
    ];

    /**
     * Get the department the employee belongs to
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Get the employee's schedules
     */
    public function schedules(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's fingerprints
     */
    public function fingerprints(): HasMany
    {
        return $this->hasMany(Fingerprint::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's leaves
     */
    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's violations
     */
    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's disciplinary actions
     */
    public function disciplinaryActions(): HasMany
    {
        return $this->hasMany(DisciplinaryAction::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's CCTV violations
     */
    public function cctvViolations(): HasMany
    {
        return $this->hasMany(CCTVViolation::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the employee's doctor contracts
     */
    public function doctorContracts(): HasMany
    {
        return $this->hasMany(DoctorContract::class, 'employee_id', 'employee_id');
    }

    /**
     * Get base shift hours based on shift_type
     */
    public function getBaseShiftHours(): float
    {
        return match($this->shift_type) {
            '7hr' => 7.0,
            '8hr' => 8.0,
            '12hr' => 12.0,
            default => 8.0,
        };
    }

    /**
     * Check if employee works 12-hour shifts
     */
    public function is12HourShift(): bool
    {
        return $this->shift_type === '12hr';
    }

    /**
     * Check if employee is a resident doctor
     */
    public function isResident(): bool
    {
        return $this->classification === 'resident';
    }

    /**
     * Scope for residents
     */
    public function scopeResidents($query)
    {
        return $query->where('classification', 'resident');
    }

    /**
     * Scope for specialists
     */
    public function scopeSpecialists($query)
    {
        return $query->where('classification', 'specialist');
    }

    /**
     * Scope for specific department
     */
    public function scopeInDepartment($query, $departmentId)
    {
        return $query->where('department_id', $departmentId);
    }
}