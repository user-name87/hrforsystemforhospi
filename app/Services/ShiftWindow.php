<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * A hospital "attendance day" is not a calendar day: it starts at 07:00 and ends
 * at 06:00 the next morning so that a night shift and its check-out belong to the
 * same day. Every punch lookup in the system goes through this window.
 */
class ShiftWindow
{
    public const DAY_STARTS_AT = 7;   // 07:00
    public const DAY_ENDS_AT   = 6;   // 06:00 next day

    /** Shift code → expected start time (24h). */
    private const SHIFT_STARTS = [
        'M' => '07:00', 'ص' => '07:00', 'S' => '07:00', 'D' => '07:00', '12' => '07:00',
        'E' => '14:00', 'م' => '14:00', 'A' => '14:00',
        'N' => '21:00', 'ل' => '21:00', 'L' => '21:00',
    ];

    public static function start(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay()->addHours(self::DAY_STARTS_AT);
    }

    public static function end(CarbonInterface|string $date): Carbon
    {
        return Carbon::parse($date)->startOfDay()->addDay()->addHours(self::DAY_ENDS_AT);
    }

    /**
     * The attendance day a punch belongs to: punches before 06:00 close out the
     * previous day, everything else belongs to the calendar day it happened on.
     */
    public static function dayFor(CarbonInterface|string $punchedAt): Carbon
    {
        $moment = Carbon::parse($punchedAt);

        return $moment->hour < self::DAY_ENDS_AT
            ? $moment->copy()->subDay()->startOfDay()
            : $moment->copy()->startOfDay();
    }

    public static function expectedStart(?string $shiftCode): ?string
    {
        if ($shiftCode === null) return null;

        return self::SHIFT_STARTS[mb_strtoupper(trim($shiftCode))] ?? null;
    }

    /** Expected start as a moment on the given attendance day. */
    public static function expectedStartAt(CarbonInterface|string $date, ?string $shiftCode): ?Carbon
    {
        $time = self::expectedStart($shiftCode);
        if (!$time) return null;

        [$h, $m] = array_map('intval', explode(':', $time));

        return Carbon::parse($date)->startOfDay()->setTime($h, $m);
    }

    /** Expected end, rolled over to the next morning for night shifts. */
    public static function expectedEndAt(CarbonInterface|string $date, ?string $shiftCode, float $shiftHours): ?Carbon
    {
        $start = self::expectedStartAt($date, $shiftCode);

        return $start?->copy()->addMinutes((int) round($shiftHours * 60));
    }
}
