<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    protected $table = 'leaves';

    protected $fillable = [
        'employee_id',
        'leave_date',
        'leave_type',   // 'annual' | 'sick' | 'emergency' | 'maternity' | 'other'
        'status',       // 'pending_dept' | 'pending_hr' | 'pending_gm' | 'approved' | 'rejected'
        'approved_by',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'leave_date'  => 'date',
        'approved_at' => 'datetime',
    ];

    // A leave counts as "valid" (= treated as normal working day) only when approved
    // or when it has passed dept-level and is sitting at HR / GM
    // per spec: pending_dept = NOT approved yet
    public function isApprovedForAttendance(): bool
    {
        return in_array($this->status, ['pending_hr', 'pending_gm', 'approved']);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }
}
