<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'violation_category',
        'violation_row',
        'incident_date',
        'occurrence_number',
        'penalty',
        'notes',
    ];

    protected $casts = [
        'incident_date' => 'date',
        'violation_row' => 'integer',
        'occurrence_number' => 'integer',
    ];

    /**
     * Get the employee that committed the violation
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}