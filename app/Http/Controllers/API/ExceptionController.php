<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\ShiftException;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExceptionController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/exceptions?month=&year=&department_id=
    public function index(Request $request): JsonResponse
    {
        $month = (int)($request->query('month', now()->month));
        $year  = (int)($request->query('year',  now()->year));

        $query = ShiftException::with(['employee.department'])
            ->where('month', $month)
            ->where('year', $year);

        if ($request->filled('department_id')) {
            $query->whereHas('employee', fn($q) =>
                $q->where('department_id', $request->department_id)
            );
        }

        return response()->json($query->get());
    }

    // GET /api/exceptions/{employeeId}/{month}/{year}
    public function getForEmployee(string $employeeId, int $month, int $year): JsonResponse
    {
        $exception = ShiftException::where('employee_id', $employeeId)
            ->where('month', $month)
            ->where('year', $year)
            ->with('employee')
            ->first();

        // Also return preview of how it affects required hours
        $preview = null;
        if ($exception) {
            $preview = $this->calc->previewException(
                $employeeId, $month, $year, $exception->exception_hours
            );
        }

        return response()->json([
            'exception' => $exception,
            'preview'   => $preview,
        ]);
    }

    // POST /api/exceptions
    // Also used for the "preview" when exception_hours is sent but save=false
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'     => 'required|string|exists:employees,employee_id',
            'month'           => 'required|integer|between:1,12',
            'year'            => 'required|integer|min:2020',
            'exception_hours' => 'required|numeric|min:0.5|max:24',
            'reason'          => 'nullable|string|max:500',
            'preview_only'    => 'nullable|boolean',  // if true, return preview without saving
        ]);

        $empId = $validated['employee_id'];
        $month = $validated['month'];
        $year  = $validated['year'];
        $newHours = $validated['exception_hours'];

        // Always return preview data
        $preview = $this->calc->previewException($empId, $month, $year, $newHours);

        // If preview_only, don't save
        if ($request->boolean('preview_only')) {
            return response()->json(['preview' => $preview]);
        }

        // Get original hours from employee record
        $employee = Employee::where('employee_id', $empId)->firstOrFail();
        $originalHours = $employee->getBaseShiftHours();

        // Upsert — one exception per employee per month
        $exception = ShiftException::updateOrCreate(
            ['employee_id' => $empId, 'month' => $month, 'year' => $year],
            [
                'original_hours'  => $originalHours,
                'exception_hours' => $newHours,
                'reason'          => $validated['reason'] ?? null,
                'created_by'      => 'dashboard',
            ]
        );

        return response()->json([
            'exception' => $exception->load('employee'),
            'preview'   => $preview,
        ], 201);
    }

    // PUT /api/exceptions/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $exception = ShiftException::findOrFail($id);
        $validated = $request->validate([
            'exception_hours' => 'required|numeric|min:0.5|max:24',
            'reason'          => 'nullable|string|max:500',
        ]);

        $exception->update($validated);

        $preview = $this->calc->previewException(
            $exception->employee_id,
            $exception->month,
            $exception->year,
            $exception->exception_hours
        );

        return response()->json([
            'exception' => $exception->load('employee'),
            'preview'   => $preview,
        ]);
    }

    // DELETE /api/exceptions/{id}
    public function destroy(int $id): JsonResponse
    {
        ShiftException::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }
}
