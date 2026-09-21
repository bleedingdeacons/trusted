<?php

declare(strict_types=1);

namespace Trusted\Service;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Trusted\Domain\Rota;
use Trusted\Domain\ShiftTime;

/**
 * Finds the uncovered stretches of a telephone day.
 *
 * A day runs 00:00 to 24:00 and each shift covers [start, end). A shift whose
 * end is at or before its start runs overnight: it covers the rest of its own
 * day and carries on into the next, so the following day is covered until it
 * ends. That is why the previous day's slots are needed — its overnight shifts
 * decide where this day's first gap can begin.
 *
 * An end of 23:59 is the end of the day (see ShiftTime), so it neither leaves
 * a one-minute gap nor counts as running overnight.
 *
 * This is the one place these rules live. The calendar draws the gaps it is
 * given rather than working them out, so there is nothing to keep in step.
 */
final class GapFinder
{
    /**
     * @param array<Rota> $slots       The day's own slots, in any order.
     * @param array<Rota> $previousDay The slots of the day before.
     * @return list<array{start: string, end: string}>
     */
    public function forDay(array $slots, array $previousDay = []): array
    {
        $shifts = array_map(fn (Rota $slot): array => $this->span($slot), $slots);
        usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $gaps   = [];
        $cursor = $this->carriedInto($previousDay);

        foreach ($shifts as [$start, $end]) {
            if ($start > $cursor) {
                $gaps[] = $this->gap($cursor, $start);
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < ShiftTime::MINUTES_PER_DAY) {
            $gaps[] = $this->gap($cursor, ShiftTime::MINUTES_PER_DAY);
        }

        return $gaps;
    }

    /**
     * How far into the day the previous day's overnight shifts reach, in
     * minutes. Zero when none ran overnight.
     *
     * @param array<Rota> $previousDay
     */
    private function carriedInto(array $previousDay): int
    {
        $reach = 0;

        foreach ($previousDay as $slot) {
            if ($this->isOvernight($slot)) {
                $reach = max($reach, ShiftTime::minutes($slot->endTime()));
            }
        }

        return $reach;
    }

    /**
     * The part of a slot's own day it covers, as [start, end) in minutes.
     *
     * @return array{int, int}
     */
    private function span(Rota $slot): array
    {
        $start = ShiftTime::minutes($slot->startTime());
        $end   = $this->isOvernight($slot) ? ShiftTime::MINUTES_PER_DAY : ShiftTime::endMinutes($slot->endTime());

        return [$start, $end];
    }

    private function isOvernight(Rota $slot): bool
    {
        return ShiftTime::endMinutes($slot->endTime()) <= ShiftTime::minutes($slot->startTime());
    }

    /**
     * @return array{start: string, end: string}
     */
    private function gap(int $from, int $to): array
    {
        return ['start' => ShiftTime::format($from), 'end' => ShiftTime::format($to)];
    }
}
