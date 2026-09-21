<?php

declare(strict_types=1);

namespace Trusted\Domain;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

/**
 * The end-of-day convention for shift times.
 *
 * People write a shift that runs to midnight as ending at 24:00, so the
 * calendar and the templates accept and show that. What is stored is 23:59,
 * the last minute of the shift's own date, so a shift never appears to end on
 * the following day. Both spellings mean the end of the day: nothing in the
 * rota treats 23:59 as leaving a minute uncovered.
 *
 * Only ends are affected. A start of 23:59 is simply a minute before midnight.
 */
final class ShiftTime
{
    /** How the end of the day is written and shown. */
    public const END_OF_DAY = '24:00';

    /** How the end of the day is stored. */
    public const LAST_MINUTE = '23:59';

    public const MINUTES_PER_DAY = 1440;

    /**
     * The form an end time is stored in: 24:00 becomes 23:59.
     */
    public static function toStored(string $end): string
    {
        return $end === self::END_OF_DAY ? self::LAST_MINUTE : $end;
    }

    /**
     * The form an end time is shown in: 23:59 becomes 24:00.
     */
    public static function toShown(string $end): string
    {
        return $end === self::LAST_MINUTE ? self::END_OF_DAY : $end;
    }

    /**
     * Minutes past midnight for an "H:i" time.
     */
    public static function minutes(string $time): int
    {
        [$hours, $minutes] = array_pad(explode(':', $time), 2, '0');

        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * Minutes past midnight for an end time, with the end of the day — however
     * it is spelt — counted as a full day.
     */
    public static function endMinutes(string $end): int
    {
        return $end === self::LAST_MINUTE || $end === self::END_OF_DAY
            ? self::MINUTES_PER_DAY
            : self::minutes($end);
    }

    /**
     * "H:i" for minutes past midnight, with a full day shown as 24:00.
     */
    public static function format(int $minutes): string
    {
        if ($minutes >= self::MINUTES_PER_DAY) {
            return self::END_OF_DAY;
        }

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
