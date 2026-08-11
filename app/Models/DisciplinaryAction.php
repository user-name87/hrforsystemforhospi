<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryAction extends Model
{
    protected $table = 'disciplinary_actions';

    protected $fillable = [
        'employee_id',
        'action_type',
        'severity',     // 'low' | 'medium' | 'high'
        'note',
        'created_by',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
