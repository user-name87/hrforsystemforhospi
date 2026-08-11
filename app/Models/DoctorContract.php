<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'contract_type',
        'start_date',
        'end_date',
        'monthly_hours',
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'monthly_hours' => 'float',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Scope for active contracts on a given date
     */
    public function scopeActiveFor($query, string $date)
    {
        return $query->where('start_date', '<=', $date)
                    ->where(function ($q) use ($date) {
                        $q->where('end_date', '>=', $date)
                          ->orWhereNull('end_date');
                    });
    }
}