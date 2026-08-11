<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DoctorContract extends Model
{
    protected $table = 'doctor_contracts';

    protected $fillable = [
        'employee_id',
        'contract_type',    // 'resident' | 'specialist'
        'department_id',
        'monthly_hours',    // agreed hours per month
        'start_date',
        'end_date',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'monthly_hours' => 'float',
        'start_date'    => 'date',
        'end_date'      => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id', 'id');
    }

    // Get the active contract for an employee at a given date
    public static function activeFor(string $empId, ?string $date = null): ?self
    {
        $date = $date ?? now()->toDateString();
        return static::where('employee_id', $empId)
            ->where('start_date', '<=', $date)
            ->where(fn($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $date))
            ->orderByDesc('start_date')
            ->first();
    }
}
