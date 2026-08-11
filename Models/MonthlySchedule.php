<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlySchedule extends Model
{
    protected $table = 'monthly_schedules';

    protected $fillable = [
        'department_id',
        'department_name',
        'month',
        'year',
        'uploaded_by',
    ];

    protected $casts = [
        'month' => 'integer',
        'year'  => 'integer',
    ];
}
