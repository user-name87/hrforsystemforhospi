<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Models\MonthlySchedule;
use App\Services\ReminderEmailService;
use Illuminate\Console\Command;

/**
 * Chases the departments that still have not uploaded the current month's
 * schedule. Runs on the 1st, when every schedule is due.
 */
class SendScheduleReminders extends Command
{
    protected $signature = 'hr:remind-schedules {--month=} {--year=}';

    protected $description = 'Email the departments that have not uploaded their monthly schedule yet';

    public function handle(ReminderEmailService $mailer): int
    {
        $month = (int) ($this->option('month') ?: now()->month);
        $year  = (int) ($this->option('year') ?: now()->year);

        $uploaded = MonthlySchedule::where('month', $month)
            ->where('year', $year)
            ->pluck('department_id')
            ->all();

        $pending = Department::whereNotNull('chairman_email')
            ->whereNotIn('id', $uploaded)
            ->get();

        if ($pending->isEmpty()) {
            $this->info("All departments have uploaded their {$month}/{$year} schedule.");
            return self::SUCCESS;
        }

        foreach ($mailer->sendToAllPending($pending->all(), $month, $year) as $result) {
            $this->line(($result['success'] ? '✔ ' : '✖ ') . $result['department'] . ' — ' . $result['message']);
        }

        return self::SUCCESS;
    }
}
