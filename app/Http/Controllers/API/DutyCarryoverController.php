<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DutyCarryover;
use App\Models\Employee;
use App\Models\EmployeeSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DutyCarryoverController extends Controller
{
    // GET /api/carryover?month=&year=
    public function index(Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year  = (int)($request->query('year',  now()->year));

        $records = DutyCarryover::with(['employee.department'])
            ->where('applied_month', $month)
            ->where('applied_year', $year)
            ->get();

        return response()->json($records);
    }

    // GET /api/carryover/{employeeId}/{month}/{year}
    public function getForEmployee(string $employeeId, int $month, int $year): JsonResponse
    {
        $carryover = DutyCarryover::where('employee_id', $employeeId)
            ->where('applied_month', $month)
            ->where('applied_year', $year)
            ->with('employee')
            ->first();

        $employee  = Employee::where('employee_id', $employeeId)->firstOrFail();
        $baseQuota = $employee->duty_quota ?? 17;
        $surplus   = $carryover?->surplus_shifts ?? 0;

        return response()->json([
            'carryover'       => $carryover,
            'base_quota'      => $baseQuota,
            'surplus_shifts'  => $surplus,
            'effective_quota' => max(0, $baseQuota - $surplus),
            'from_month'      => $carryover?->from_month,
            'from_year'       => $carryover?->from_year,
        ]);
    }

    // POST /api/carryover
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'    => 'required|string|exists:employees,employee_id',
            'from_month'     => 'required|integer|between:1,12',
            'from_year'      => 'required|integer|min:2020',
            'surplus_shifts' => 'required|integer|min:1',
            'applied_month'  => 'required|integer|between:1,12',
            'applied_year'   => 'required|integer|min:2020',
        ]);

        $carryover = DutyCarryover::updateOrCreate(
            [
                'employee_id'   => $validated['employee_id'],
                'applied_month' => $validated['applied_month'],
                'applied_year'  => $validated['applied_year'],
            ],
            [
                'from_month'     => $validated['from_month'],
                'from_year'      => $validated['from_year'],
                'surplus_shifts' => $validated['surplus_shifts'],
            ]
        );

        $employee  = Employee::where('employee_id', $validated['employee_id'])->first();
        $baseQuota = $employee->duty_quota ?? 17;

        return response()->json([
            'carryover'       => $carryover->load('employee'),
            'base_quota'      => $baseQuota,
            'surplus_shifts'  => $carryover->surplus_shifts,
            'effective_quota' => max(0, $baseQuota - $carryover->surplus_shifts),
        ], 201);
    }

    // PUT /api/carryover/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $carryover = DutyCarryover::findOrFail($id);
        $validated = $request->validate([
            'surplus_shifts' => 'required|integer|min:0',
            'from_month'     => 'nullable|integer|between:1,12',
            'from_year'      => 'nullable|integer|min:2020',
        ]);
        $carryover->update($validated);

        $employee  = Employee::where('employee_id', $carryover->employee_id)->first();
        $baseQuota = $employee->duty_quota ?? 17;

        return response()->json([
            'carryover'       => $carryover->load('employee'),
            'effective_quota' => max(0, $baseQuota - $carryover->surplus_shifts),
        ]);
    }

    // DELETE /api/carryover/{id}
    public function destroy(int $id): JsonResponse
    {
        DutyCarryover::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
