<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Leave extends Model
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'leave_date',
        'leave_type',
        'status',
        'notes',
        'approved_by',
    ];

    protected $casts = [
        'leave_date' => 'date',
    ];

    /**
     * Get the employee
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id', 'employee_id');
    }

    /**
     * Check if leave is approved for attendance calculation
     */
    public function isApprovedForAttendance(): bool
    {
        return in_array($this->status, ['approved', 'pending_hr', 'pending_gm']);
    }
}