<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonthlySchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'department_name',
        'month',
        'year',
        'uploaded_by',
    ];

    protected $casts = [
        'month' => 'integer',
        'year' => 'integer',
    ];

    /**
     * Get the department
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}