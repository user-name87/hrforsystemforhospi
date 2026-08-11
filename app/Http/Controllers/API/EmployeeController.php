<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    // GET /api/employees?department_id=&search=&month=&year=
    public function index(Request $request): JsonResponse
    {
        $query = Employee::with('department');

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('name', 'ilike', "%{$s}%")
                  ->orWhere('employee_id', 'ilike', "%{$s}%");
            });
        }

        if ($request->filled('classification')) {
            $query->where('classification', $request->classification);
        }

        $employees = $query->orderBy('name')->get();

        return response()->json([
            'data'  => $employees,
            'count' => $employees->count(),
        ]);
    }

    // GET /api/employees/{employeeId}
    public function show(string $employeeId): JsonResponse
    {
        $emp = Employee::with('department')
            ->where('employee_id', $employeeId)
            ->firstOrFail();

        return response()->json($emp);
    }

    // POST /api/employees
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'    => 'required|string|unique:employees,employee_id',
            'name'           => 'required|string|max:255',
            'department_id'  => 'nullable|exists:departments,id',
            'job_title'      => 'nullable|string|max:255',
            'shift_type'     => 'nullable|in:7hr,8hr,12hr',
            'nationality'    => 'nullable|string|max:100',
            'basic_salary'   => 'nullable|numeric|min:0',
            'full_or_part'   => 'nullable|in:full,part',
            'classification' => 'nullable|string|max:100',
            'duty_quota'     => 'nullable|integer|min:1',
        ]);

        $emp = Employee::create($validated);
        return response()->json($emp->load('department'), 201);
    }

    // PUT /api/employees/{employeeId}
    public function update(Request $request, string $employeeId): JsonResponse
    {
        $emp = Employee::where('employee_id', $employeeId)->firstOrFail();

        $validated = $request->validate([
            'name'           => 'sometimes|string|max:255',
            'department_id'  => 'nullable|exists:departments,id',
            'job_title'      => 'nullable|string|max:255',
            'shift_type'     => 'nullable|in:7hr,8hr,12hr',
            'nationality'    => 'nullable|string|max:100',
            'basic_salary'   => 'nullable|numeric|min:0',
            'full_or_part'   => 'nullable|in:full,part',
            'classification' => 'nullable|string|max:100',
            'duty_quota'     => 'nullable|integer|min:1',
        ]);

        $emp->update($validated);
        return response()->json($emp->load('department'));
    }

    // DELETE /api/employees/{employeeId}
    public function destroy(string $employeeId): JsonResponse
    {
        Employee::where('employee_id', $employeeId)->firstOrFail()->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
