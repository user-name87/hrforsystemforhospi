<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\DoctorContract;
use App\Models\Employee;
use App\Services\AttendanceCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DoctorContractController extends Controller
{
    public function __construct(private AttendanceCalculatorService $calc) {}

    // GET /api/contracts?type=resident&department_id=
    public function index(Request $request): JsonResponse
    {
        $query = DoctorContract::with(['employee.department', 'department'])
            ->orderBy('start_date', 'desc');

        if ($request->filled('type')) {
            $query->where('contract_type', $request->type);
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        // Only active contracts by default
        if (!$request->boolean('all')) {
            $query->where('start_date', '<=', now()->toDateString())
                  ->where(fn($q) => $q->whereNull('end_date')
                      ->orWhere('end_date', '>=', now()->toDateString()));
        }

        return response()->json($query->get());
    }

    // GET /api/contracts/{employeeId}
    public function getForEmployee(string $employeeId): JsonResponse
    {
        $contracts = DoctorContract::where('employee_id', $employeeId)
            ->with(['department'])
            ->orderByDesc('start_date')
            ->get();

        $active = DoctorContract::activeFor($employeeId);

        return response()->json([
            'contracts' => $contracts,
            'active'    => $active,
        ]);
    }

    // POST /api/contracts
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'   => 'required|string|exists:employees,employee_id',
            'contract_type' => 'required|in:resident,specialist',
            'department_id' => 'nullable|exists:departments,id',
            'monthly_hours' => 'required|numeric|min:1|max:744',
            'start_date'    => 'required|date',
            'end_date'      => 'nullable|date|after:start_date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $contract = DoctorContract::create([
            ...$validated,
            'created_by' => 'hr',
        ]);

        // Auto-update employee classification
        Employee::where('employee_id', $validated['employee_id'])
            ->update(['classification' => $validated['contract_type']]);

        return response()->json($contract->load(['employee', 'department']), 201);
    }

    // PUT /api/contracts/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $contract  = DoctorContract::findOrFail($id);
        $validated = $request->validate([
            'contract_type' => 'nullable|in:resident,specialist',
            'department_id' => 'nullable|exists:departments,id',
            'monthly_hours' => 'nullable|numeric|min:1|max:744',
            'start_date'    => 'nullable|date',
            'end_date'      => 'nullable|date',
            'notes'         => 'nullable|string|max:1000',
        ]);

        $contract->update($validated);
        return response()->json($contract->load(['employee', 'department']));
    }

    // DELETE /api/contracts/{id}
    public function destroy(int $id): JsonResponse
    {
        DoctorContract::findOrFail($id)->delete();
        return response()->json(['message' => 'Deleted']);
    }

    // GET /api/contracts/summary/{month}/{year}
    // Returns all resident/specialist doctors with their required vs actual hours
    public function monthlySummary(int $month, int $year): JsonResponse
    {
        $calc = $this->calc;

        // Get all employees who are residents or specialists
        $doctors = Employee::whereIn('classification', ['resident', 'specialist'])
            ->with('department')
            ->get();

        $summary = $doctors->map(function ($emp) use ($month, $year, $calc) {
            $contract = DoctorContract::activeFor(
                $emp->employee_id,
                \Carbon\Carbon::create($year, $month, 1)->toDateString()
            );

            // Actual hours punched this month, from the attendance calculator
            $report       = $calc->calculate($emp, $month, $year);
            $workedHours  = $report['summary']['total_worked_hrs'];
            $requiredHours = (float) ($contract?->monthly_hours ?? config('app.resident_default_hours', 160));

            return [
                'employee_id'    => $emp->employee_id,
                'name'           => $emp->name,
                'department'     => $emp->department?->name,
                'classification' => $emp->classification,
                'contract'       => $contract ? [
                    'id'            => $contract->id,
                    'type'          => $contract->contract_type,
                    'monthly_hours' => $contract->monthly_hours,
                    'start_date'    => $contract->start_date,
                    'end_date'      => $contract->end_date,
                ] : null,
                'required_hours' => $requiredHours,
                'worked_hours'   => $workedHours,
                'balance_hours'  => round($workedHours - $requiredHours, 2),
                'met'            => $workedHours >= $requiredHours,
                'has_contract'   => (bool)$contract,
            ];
        });

        return response()->json([
            'month'   => $month,
            'year'    => $year,
            'doctors' => $summary,
            'totals'  => [
                'residents'   => $doctors->where('classification', 'resident')->count(),
                'specialists' => $doctors->where('classification', 'specialist')->count(),
                'no_contract' => $summary->where('has_contract', false)->count(),
                'met'         => $summary->where('met', true)->count(),
            ],
        ]);
    }
}
