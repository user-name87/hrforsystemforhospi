<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftException extends Model
{
    protected $table = 'shift_exceptions';

    protected $fillable = [
        'employee_id',
        'month',
        'year',
        'original_hours',
        'exception_hours',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'original_hours'  => 'float',
        'exception_hours' => 'float',
        'month'           => 'integer',
        'year'            => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
