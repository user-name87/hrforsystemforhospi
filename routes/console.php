<?php

use Illuminate\Support\Facades\Schedule;

// The attendance day runs 07:00 → 06:00, so the previous day is only complete
// at 06:00; violations are recorded and sent half an hour later.
Schedule::command('hr:daily-violations')->dailyAt('06:30');

// Schedules for the coming month are requested on the 26th …
Schedule::command('hr:request-schedules')->monthlyOn(26, '09:00');

// … and the departments that still have not uploaded are chased on the 1st.
Schedule::command('hr:remind-schedules')->monthlyOn(1, '09:00');
