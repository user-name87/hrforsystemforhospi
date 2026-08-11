<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'chairman_name',
        'chairman_email',
    ];

    /**
     * Get the employees in the department
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    /**
     * Get the monthly schedules for this department
     */
    public function monthlySchedules(): HasMany
    {
        return $this->hasMany(MonthlySchedule::class);
    }
}