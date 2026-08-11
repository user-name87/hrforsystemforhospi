<?php

namespace Tests\Unit;

use App\Services\ShiftWindow;
use PHPUnit\Framework\TestCase;

class ShiftWindowTest extends TestCase
{
    public function test_the_attendance_day_runs_from_seven_am_to_six_am_next_morning(): void
    {
        $this->assertSame('2025-07-20 07:00', ShiftWindow::start('2025-07-20')->format('Y-m-d H:i'));
        $this->assertSame('2025-07-21 06:00', ShiftWindow::end('2025-07-20')->format('Y-m-d H:i'));
    }

    public function test_a_night_shift_checkout_belongs_to_the_day_the_shift_started(): void
    {
        $this->assertSame('2025-07-20', ShiftWindow::dayFor('2025-07-21 05:45')->toDateString());
        $this->assertSame('2025-07-21', ShiftWindow::dayFor('2025-07-21 06:10')->toDateString());
        $this->assertSame('2025-07-20', ShiftWindow::dayFor('2025-07-20 21:00')->toDateString());
    }

    public function test_a_night_shift_ends_on_the_following_morning(): void
    {
        $end = ShiftWindow::expectedEndAt('2025-07-20', 'N', 12);

        $this->assertSame('2025-07-21 09:00', $end->format('Y-m-d H:i'));
    }
}
