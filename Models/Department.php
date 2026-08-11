<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Department extends Model
{
    protected $table = 'departments';

    protected $fillable = [
        'name',
        'chairman_name',
        'chairman_email',
        'employee_count',
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id', 'id');
    }

    public function monthlySchedules(): HasMany
    {
        return $this->hasMany(MonthlySchedule::class, 'department_id', 'id');
    }

    public function hasUploadedSchedule(int $month, int $year): bool
    {
        return $this->monthlySchedules()
            ->where('month', $month)
            ->where('year', $year)
            ->exists();
    }
}
