<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChemoMixingDuty extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'department_id',
        'month',
        'year',
        'reduced_hours',
        'reduced_days',
        'confirmed',
        'confirmed_by',
        'confirmed_at',
        'email_sent',
        'email_sent_at',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'reduced_hours' => 'float',
        'reduced_days' => 'integer',
        'confirmed' => 'boolean',
        'confirmed_at' => 'datetime',
        'email_sent' => 'boolean',
        'email_sent_at' => 'datetime',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Get the department
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}