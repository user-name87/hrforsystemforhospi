<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DutyCarryover extends Model
{
    protected $table = 'duty_carryover';

    protected $fillable = [
        'employee_id',
        'from_month',
        'from_year',
        'surplus_shifts',
        'applied_month',
        'applied_year',
    ];

    protected $casts = [
        'surplus_shifts' => 'integer',
        'from_month'     => 'integer',
        'from_year'      => 'integer',
        'applied_month'  => 'integer',
        'applied_year'   => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
