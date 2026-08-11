<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Violation;
use App\Services\DailyViolationService;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The daily violation report (تقرير المخالفات اليومي).
 *
 * Everything here works on an attendance day of 07:00 → 06:00 the next morning,
 * so a night shift that ends at 06:00 on the 21st is judged as the 20th.
 */
class DailyViolationController extends Controller
{
    public function __construct(
        private DailyViolationService $daily,
        private WhatsAppNotificationService $whatsapp
    ) {}

    // GET /api/daily-violations/{date}?department_id=&violation_type=
    public function index(string $date, Request $request): JsonResponse
    {
        return response()->json($this->daily->report(
            Carbon::parse($date),
            $request->integer('department_id') ?: null,
            $request->string('violation_type')->toString() ?: null
        ));
    }

    // POST /api/daily-violations/{date}/generate
    public function generate(string $date, Request $request): JsonResponse
    {
        $day         = Carbon::parse($date);
        $departmentId = $request->integer('department_id') ?: null;

        $created = $this->daily->generate($day, $departmentId);

        return response()->json([
            'generated' => count($created),
            'report'    => $this->daily->report($day, $departmentId),
        ]);
    }

    // POST /api/daily-violations/{date}/notify
    public function notify(string $date, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id'   => 'nullable|integer|exists:departments,id',
            'violation_type'  => 'nullable|string|in:late,early_leave,absent',
            'violation_ids'   => 'nullable|array',
            'violation_ids.*' => 'integer',
            'resend'          => 'nullable|boolean',
        ]);

        $violations = Violation::with('employee')
            ->whereDate('incident_date', Carbon::parse($date))
            ->when($validated['violation_ids'] ?? null, fn ($q, $ids) => $q->whereIn('id', $ids))
            ->when($validated['violation_type'] ?? null, fn ($q, $type) => $q->where('violation_type', $type))
            ->when($validated['department_id'] ?? null, fn ($q, $dep) =>
                $q->whereHas('employee', fn ($e) => $e->where('department_id', $dep)))
            ->when(!($validated['resend'] ?? false), fn ($q) => $q->whereNull('notified_at'))
            ->get();

        return response()->json($this->whatsapp->sendDailyViolationNotifications($violations));
    }

    // GET /api/daily-violations/message/{violationId}
    // Renders the WhatsApp text for one violation without sending anything.
    public function previewMessage(int $violationId): JsonResponse
    {
        $violation = Violation::with('employee')->findOrFail($violationId);
        $employee  = $violation->employee
            ?? Employee::where('employee_id', $violation->employee_id)->firstOrFail();

        return response()->json($this->whatsapp->previewViolationMessage($employee, $violation));
    }
}
