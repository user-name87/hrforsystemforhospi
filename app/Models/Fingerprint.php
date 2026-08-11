<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fingerprint extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'punch_date',
        'punch_time',
        'punch_type',
        'source',
        'month',
        'year',
    ];

    protected $casts = [
        'punch_date' => 'date',
        'month' => 'integer',
        'year' => 'integer',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}