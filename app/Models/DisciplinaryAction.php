<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'action_type',
        'severity',
        'note',
        'penalty',
        'created_by',
        'notified_at',
    ];

    protected $casts = [
        'severity' => 'string',
        'notified_at' => 'datetime',
    ];

    /**
     * Get the employee that received the disciplinary action
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}