<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChemoMixingDuty extends Model
{
    protected $table = 'chemo_mixing_duty';

    protected $fillable = [
        'employee_id',
        'home_department_id',
        'chemo_department_id',
        'month',
        'year',
        'reduced_days',
        'original_days',
        'confirmed',
        'confirmed_by',
        'confirmed_at',
        'email_sent',
        'email_sent_at',
        'notes',
    ];

    protected $casts = [
        'confirmed'      => 'boolean',
        'email_sent'     => 'boolean',
        'confirmed_at'   => 'datetime',
        'email_sent_at'  => 'datetime',
        'month'          => 'integer',
        'year'           => 'integer',
        'reduced_days'   => 'integer',
        'original_days'  => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    public function homeDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'home_department_id', 'id');
    }
}
