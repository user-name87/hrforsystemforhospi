<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/attendance/{employeeId}/{month}/{year}
    public function getEmployeeAttendance(string $employeeId, int $month, int $year): JsonResponse
    {
        $emp = Employee::with('department')
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        $report = $this->calc->calculate($emp, $month, $year);
        return response()->json($report);
    }

    // GET /api/attendance/department/{depId}/{month}/{year}
    public function getDepartmentAttendance(int $depId, int $month, int $year): JsonResponse
    {
        $employees = Employee::where('department_id', $depId)->get();

        $reports = $employees->map(function ($emp) use ($month, $year) {
            return $this->calc->calculate($emp, $month, $year);
        });

        return response()->json([
            'department_id' => $depId,
            'month'         => $month,
            'year'          => $year,
            'employees'     => $reports,
        ]);
    }

    // POST /api/attendance/upload-fingerprints
    // Accepts JSON array of punch records (parsed from Excel by the frontend)
    // Format: [{ employee_id, date: "YYYY-MM-DD", time: "HH:MM", type: "in"|"out" }, ...]
    public function uploadFingerprints(Request $request): JsonResponse
    {
        $request->validate([
            'month'    => 'required|integer|between:1,12',
            'year'     => 'required|integer|min:2020',
            'punches'  => 'required|array|min:1',
            'punches.*.employee_id' => 'required|string',
            'punches.*.date'        => 'required|date_format:Y-m-d',
            'punches.*.time'        => 'required|date_format:H:i',
        ]);

        $month   = $request->month;
        $year    = $request->year;
        $punches = $request->punches;

        // Delete existing fingerprints for this month (re-upload replaces)
        $empIds = array_unique(array_column($punches, 'employee_id'));
        Fingerprint::whereIn('employee_id', $empIds)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        $inserted = 0;
        $chunks = array_chunk($punches, 300);

        foreach ($chunks as $chunk) {
            $rows = array_map(function ($p) use ($month, $year) {
                return [
                    'employee_id' => $p['employee_id'],
                    'punch_date'  => $p['date'],
                    'punch_time'  => $p['time'],
                    'punch_type'  => $p['type'] ?? null,
                    'source'      => 'upload',
                    'month'       => $month,
                    'year'        => $year,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ];
            }, $chunk);

            Fingerprint::insert($rows);
            $inserted += count($rows);
        }

        return response()->json([
            'success'  => true,
            'inserted' => $inserted,
            'message'  => "تم رفع {$inserted} سجل بصمة",
        ]);
    }

    // POST /api/attendance/upload-leaves
    // Accepts leave records parsed from Excel
    // Format: [{ employee_id, date: "YYYY-MM-DD", type: "annual"|"sick"|..., status: "approved"|... }]
    public function uploadLeaves(Request $request): JsonResponse
    {
        $request->validate([
            'month'          => 'required|integer|between:1,12',
            'year'           => 'required|integer|min:2020',
            'leaves'         => 'required|array|min:1',
            'leaves.*.employee_id' => 'required|string',
            'leaves.*.date'        => 'required|date_format:Y-m-d',
            'leaves.*.type'        => 'required|string',
            'leaves.*.status'      => 'nullable|string',
        ]);

        $month  = $request->month;
        $year   = $request->year;
        $leaves = $request->leaves;

        $empIds = array_unique(array_column($leaves, 'employee_id'));

        // Delete existing leaves for these employees in this month
        Leave::whereIn('employee_id', $empIds)
            ->whereYear('leave_date', $year)
            ->whereMonth('leave_date', $month)
            ->delete();

        $inserted = 0;
        foreach ($leaves as $l) {
            // Map status from Excel to our status values
            // If the Excel shows "approved" at dept level = pending_hr per our spec
            // Excel status mapping:
            // approved / موافق عليه at GM level = 'approved'
            // approved / موافق at HR = 'pending_gm'
            // approved at dept / معلق = 'pending_hr'
            // pending / قيد الانتظار = 'pending_dept'
            $status = $this->mapLeaveStatus($l['status'] ?? '');

            Leave::updateOrCreate(
                ['employee_id' => $l['employee_id'], 'leave_date' => $l['date']],
                [
                    'leave_type'  => $l['type'],
                    'status'      => $status,
                    'notes'       => $l['notes'] ?? null,
                    'approved_by' => $l['approved_by'] ?? null,
                ]
            );
            $inserted++;
        }

        return response()->json([
            'success'  => true,
            'inserted' => $inserted,
            'message'  => "تم رفع {$inserted} سجل إجازة",
        ]);
    }

    // Map Excel status text to our status enum
    private function mapLeaveStatus(string $raw): string
    {
        $raw = mb_strtolower(trim($raw));
        if (in_array($raw, ['approved', 'موافق عليه', 'مقبول'])) return 'approved';
        if (in_array($raw, ['pending_hr', 'hr', 'موافق قسم'])) return 'pending_hr';
        if (in_array($raw, ['pending_gm', 'gm', 'موافق hr'])) return 'pending_gm';
        if (in_array($raw, ['rejected', 'مرفوض'])) return 'rejected';
        return 'pending_dept'; // default = not yet approved
    }
}
