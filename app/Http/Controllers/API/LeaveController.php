<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Leave;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    /*
     * Leave Status State Machine:
     *
     *  pending_dept
     *       ↓ dept approves
     *  pending_hr        ← counts as approved for attendance from this point on
     *       ↓ HR approves
     *  pending_gm
     *       ↓ GM approves
     *  approved
     *
     * Any step can go to → rejected
     *
     * Rule: If status is uploaded from Excel, it maps based on
     * where approval currently sits (see AttendanceController::mapLeaveStatus)
     */

    // GET /api/leaves?month=&year=&department_id=&status=
    public function index(Request $request): JsonResponse
    {
        $query = Leave::with(['employee.department'])
            ->orderBy('leave_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('leave_date', $request->month)
                  ->whereYear('leave_date', $request->year);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) =>
                $q->where('department_id', $request->department_id)
            );
        }

        return response()->json($query->paginate(100));
    }

    // GET /api/leaves/{employeeId}?month=&year=
    public function getForEmployee(string $employeeId, Request $request): JsonResponse
    {
        $query = Leave::where('employee_id', $employeeId)
            ->orderBy('leave_date', 'desc');

        if ($request->filled('month') && $request->filled('year')) {
            $query->whereMonth('leave_date', $request->month)
                  ->whereYear('leave_date', $request->year);
        }

        $leaves = $query->get();

        return response()->json([
            'leaves'   => $leaves,
            'summary'  => [
                'total'        => $leaves->count(),
                'approved'     => $leaves->whereIn('status', ['pending_hr','pending_gm','approved'])->count(),
                'pending_dept' => $leaves->where('status', 'pending_dept')->count(),
                'rejected'     => $leaves->where('status', 'rejected')->count(),
            ],
        ]);
    }

    // POST /api/leaves — manual single leave entry
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|string|exists:employees,employee_id',
            'leave_date'  => 'required|date',
            'leave_type'  => 'required|string|max:100',
            'status'      => 'nullable|in:pending_dept,pending_hr,pending_gm,approved,rejected',
            'notes'       => 'nullable|string',
        ]);

        $leave = Leave::updateOrCreate(
            ['employee_id' => $validated['employee_id'], 'leave_date' => $validated['leave_date']],
            [
                'leave_type' => $validated['leave_type'],
                'status'     => $validated['status'] ?? 'pending_dept',
                'notes'      => $validated['notes'] ?? null,
            ]
        );

        return response()->json($leave->load('employee'), 201);
    }

    // PUT /api/leaves/{id}/approve — advance to next status
    public function approve(Request $request, int $id): JsonResponse
    {
        $leave      = Leave::findOrFail($id);
        $approvedBy = $request->input('approved_by', 'system');

        $nextStatus = match($leave->status) {
            'pending_dept' => 'pending_hr',
            'pending_hr'   => 'pending_gm',
            'pending_gm'   => 'approved',
            default        => $leave->status,
        };

        $leave->update([
            'status'      => $nextStatus,
            'approved_by' => $approvedBy,
            'approved_at' => now(),
        ]);

        return response()->json([
            'leave'      => $leave->fresh()->load('employee'),
            'new_status' => $nextStatus,
            'counts_as_approved_for_attendance' => $leave->fresh()->isApprovedForAttendance(),
        ]);
    }

    // PUT /api/leaves/{id}/reject
    public function reject(Request $request, int $id): JsonResponse
    {
        $leave = Leave::findOrFail($id);
        $leave->update([
            'status'      => 'rejected',
            'approved_by' => $request->input('rejected_by', 'system'),
            'approved_at' => now(),
            'notes'       => $request->input('reason', $leave->notes),
        ]);

        return response()->json($leave->load('employee'));
    }

    // DELETE /api/leaves/{id}
    public function destroy(int $id): JsonResponse
    {
        Leave::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
