<?php

namespace App\Console\Commands;

use App\Models\Department;
use App\Services\ReminderEmailService;
use Illuminate\Console\Command;

/**
 * Emails every head of department on the 26th asking for next month's schedule
 * (26 July → the August schedule).
 */
class RequestMonthlySchedules extends Command
{
    protected $signature = 'hr:request-schedules {--month=} {--year=}';

    protected $description = "Email all heads of department requesting next month's duty schedule";

    public function handle(ReminderEmailService $mailer): int
    {
        $target = now()->addMonthNoOverflow();
        $month  = (int) ($this->option('month') ?: $target->month);
        $year   = (int) ($this->option('year') ?: $target->year);

        $departments = Department::whereNotNull('chairman_email')->get();

        if ($departments->isEmpty()) {
            $this->warn('No department has a chairman email on file.');
            return self::SUCCESS;
        }

        $sent = 0;
        foreach ($departments as $department) {
            $result = $mailer->sendScheduleRequest($department, $month, $year);
            $result['success'] ? $sent++ : $this->error("{$department->name}: {$result['message']}");
        }

        $this->info("Requested {$month}/{$year} schedules from {$sent}/{$departments->count()} departments.");

        return self::SUCCESS;
    }
}
