<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeSchedule;
use App\Models\MonthlySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    // GET /api/schedules/{depId}/{month}/{year}
    public function getForDepartment(int $depId, int $month, int $year): JsonResponse
    {
        $dep = Department::findOrFail($depId);

        $schedules = EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->with('employee')
            ->get()
            ->groupBy('employee_id');

        // Build per-employee schedule grid
        $result = $schedules->map(function ($rows, $empId) {
            $emp = $rows->first()->employee;
            $grid = [];
            foreach ($rows as $row) {
                $grid[$row->day] = $row->shift_code;
            }
            return [
                'employee_id' => $empId,
                'name'        => $emp?->name,
                'job_title'   => $emp?->job_title,
                'shift_type'  => $emp?->shift_type,
                'schedule'    => $grid,
                'working_days'=> count(array_filter($grid, fn($c) => EmployeeSchedule::isWorkingCode($c))),
            ];
        })->values();

        $uploaded = MonthlySchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        return response()->json([
            'department'        => ['id' => $dep->id, 'name' => $dep->name],
            'month'             => $month,
            'year'              => $year,
            'schedule_uploaded' => (bool)$uploaded,
            'upload_date'       => $uploaded?->created_at,
            'employees'         => $result,
        ]);
    }

    // POST /api/schedules/upload
    // Receives parsed schedule data from the frontend (from the Excel upload)
    // Format: {
    //   department_id, department_name, month, year,
    //   employees: [{ employee_id, days: { 1: "M", 2: "O", ... } }]
    // }
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'department_id'            => 'required|integer',
            'month'                    => 'required|integer|between:1,12',
            'year'                     => 'required|integer|min:2020',
            'employees'                => 'required|array|min:1',
            'employees.*.employee_id'  => 'required|string',
            'employees.*.days'         => 'required|array',
        ]);

        $depId     = $request->department_id;
        $depName   = $request->department_name ?? Department::find($depId)?->name ?? '';
        $month     = $request->month;
        $year      = $request->year;
        $employees = $request->employees;

        // Delete old schedule for this dept/month before inserting new one
        EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        // De-duplicate employees by employee_id (last one wins)
        $empMap = [];
        foreach ($employees as $empData) {
            $empId = trim($empData['employee_id']);
            if ($empId) $empMap[$empId] = $empData;
        }

        // Upsert employees into employees table
        foreach ($empMap as $empId => $empData) {
            \DB::table('employees')->upsert(
                [[
                    'employee_id'   => $empId,
                    'name'          => $empData['name'] ?? $empId,
                    'department_id' => $depId,
                    'shift_type'    => '8hr',
                    'full_or_part'  => 'full',
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]],
                ['employee_id'],
                ['name', 'department_id', 'updated_at']
            );
        }

        $insertedRows = 0;
        foreach ($empMap as $empId => $empData) {
            $days = $empData['days'] ?? [];

            $rows = [];
            foreach ($days as $day => $code) {
                if (!$code) continue;
                $rows[] = [
                    'employee_id'   => $empId,
                    'department_id' => $depId,
                    'month'         => $month,
                    'year'          => $year,
                    'day'           => (int)$day,
                    'shift_code'    => strtoupper(trim($code)),
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }

            // Upsert in chunks to handle re-uploads
            foreach (array_chunk($rows, 100) as $chunk) {
                \DB::table('employee_schedules')->upsert(
                    $chunk,
                    ['employee_id', 'month', 'year', 'day'],
                    ['shift_code', 'department_id', 'updated_at']
                );
                $insertedRows += count($chunk);
            }
        }

        // Mark department as having uploaded a schedule this month
        MonthlySchedule::updateOrCreate(
            ['department_id' => $depId, 'month' => $month, 'year' => $year],
            ['department_name' => $depName, 'uploaded_by' => 'dashboard']
        );

        return response()->json([
            'success'          => true,
            'department'       => $depName,
            'month'            => $month,
            'year'             => $year,
            'employees_count'  => count($employees),
            'schedule_rows'    => $insertedRows,
            'message'          => "تم رفع جدول {$depName} بنجاح — {$insertedRows} خلية جدول",
        ], 201);
    }

    // DELETE /api/schedules/{depId}/{month}/{year}
    public function delete(int $depId, int $month, int $year): JsonResponse
    {
        EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        MonthlySchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        return response()->json(['message' => 'Schedule deleted']);
    }
}
