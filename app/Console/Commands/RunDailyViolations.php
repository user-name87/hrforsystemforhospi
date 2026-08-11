<?php

namespace App\Console\Commands;

use App\Models\Violation;
use App\Services\DailyViolationService;
use App\Services\WhatsAppNotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Closes an attendance day (07:00 → 06:00 next morning): records the lateness,
 * early-leave and absence violations, then notifies the employees on WhatsApp.
 *
 * Runs at 06:30, right after the previous day's window closes.
 */
class RunDailyViolations extends Command
{
    protected $signature = 'hr:daily-violations {--date=} {--department=} {--no-notify}';

    protected $description = 'Generate and send the attendance violations of one day';

    public function handle(DailyViolationService $daily, WhatsAppNotificationService $whatsapp): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : now()->subDay();

        $departmentId = $this->option('department') ? (int) $this->option('department') : null;

        $created = $daily->generate($date, $departmentId);
        $this->info("Recorded " . count($created) . " violation(s) for {$date->toDateString()}.");

        if ($this->option('no-notify')) {
            return self::SUCCESS;
        }

        $pending = Violation::with('employee')
            ->whereDate('incident_date', $date)
            ->whereNull('notified_at')
            ->get();

        $result = $whatsapp->sendDailyViolationNotifications($pending);
        $this->info("Notified {$result['sent']}/{$result['total']} employee(s), {$result['failed']} failed.");

        return self::SUCCESS;
    }
}
