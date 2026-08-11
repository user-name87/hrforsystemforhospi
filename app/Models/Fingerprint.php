<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fingerprint extends Model
{
    protected $table = 'fingerprints';

    protected $fillable = [
        'employee_id',
        'punch_date',
        'punch_time',
        'punch_type',   // 'in' | 'out'
        'source',       // 'upload' | 'manual'
        'month',
        'year',
    ];

    protected $casts = [
        'punch_date' => 'date',
        'month'      => 'integer',
        'year'       => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
