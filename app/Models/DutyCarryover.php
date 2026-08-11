<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DutyCarryover extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'from_month',
        'from_year',
        'surplus_shifts',
        'applied_month',
        'applied_year',
    ];

    protected $casts = [
        'from_month' => 'integer',
        'from_year' => 'integer',
        'surplus_shifts' => 'integer',
        'applied_month' => 'integer',
        'applied_year' => 'integer',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}