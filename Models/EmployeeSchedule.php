<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSchedule extends Model
{
    protected $table = 'employee_schedules';

    protected $fillable = [
        'employee_id',
        'department_id',
        'month',
        'year',
        'day',
        'shift_code',   // e.g. 'M' morning | 'E' evening | 'N' night | 'O' off | '12' 12hr
    ];

    protected $casts = [
        'month' => 'integer',
        'year'  => 'integer',
        'day'   => 'integer',
    ];

    // Codes that mean the employee is scheduled to work (not off/holiday)
    public static function isWorkingCode(string $code): bool
    {
        $offCodes = ['O', 'OFF', 'H', 'HOL', 'V', 'VAC', ''];
        return !in_array(strtoupper(trim($code)), $offCodes);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
