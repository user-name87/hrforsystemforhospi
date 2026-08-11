<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends Model
{
    protected $table = 'violations';

    protected $fillable = [
        'employee_id',
        'violation_category',
        'violation_row',
        'occurrence_number',
        'penalty',
        'incident_date',
        'notes',
    ];

    protected $casts = [
        'incident_date'     => 'date',
        'violation_row'     => 'integer',
        'occurrence_number' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
