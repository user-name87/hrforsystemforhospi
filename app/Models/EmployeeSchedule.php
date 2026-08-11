<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'department_id',
        'month',
        'year',
        'day',
        'shift_code',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
        'day' => 'integer',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Check if a shift code indicates a working day
     */
    public static function isWorkingCode(?string $code): bool
    {
        if (!$code) return false;
        
        $offCodes = ['O', 'OFF', 'H', 'HOL', 'V', 'VAC', 'R', 'REST'];
        return !in_array(strtoupper(trim($code)), $offCodes);
    }
}