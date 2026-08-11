<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CCTVViolation extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'violation_date',
        'violation_type',
        'description',
        'penalty_days',
        'notes',
        'month',
        'year',
        'recorded_by',
    ];

    protected $casts = [
        'violation_date' => 'date',
        'penalty_days' => 'integer',
        'month' => 'integer',
        'year' => 'integer',
    ];

    /**
     * Get the employee that committed the violation
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Scope for violations with salary deductions
     */
    public function scopeWithSalaryDeduction($query)
    {
        return $query->where('penalty_days', '>', 0);
    }

    /**
     * Scope for a specific date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('violation_date', [$startDate, $endDate]);
    }
}