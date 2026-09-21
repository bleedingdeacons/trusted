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
 * When nothing on the previous day runs past 24:00, the stretch from 00:00 to
 * the day's first shift is locked. It is not uncovered time anyone could fill:
 * it is before the rota starts — the first Monday the telephone service runs
 * has no Sunday shift before it. Only that opening gap is locked, and only
 * when the day has a first shift; an empty day stays an ordinary gap, or an
 * empty week could never be filled in.
 *
 * This is the one place these rules live. The calendar draws the gaps it is
 * given rather than working them out, so there is nothing to keep in step.
 */
final class GapFinder
{
    /**
     * @param array<Rota> $slots       The day's own slots, in any order.
     * @param array<Rota> $previousDay The slots of the day before.
     * @return list<array{start: string, end: string, locked: bool}>
     */
    public function forDay(array $slots, array $previousDay = []): array
    {
        $shifts = array_map(fn (Rota $slot): array => $this->span($slot), $slots);
        usort($shifts, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $gaps   = [];
        $cursor = $this->carriedInto($previousDay);

        foreach ($shifts as [$start, $end]) {
            if ($start > $cursor) {
                // A first gap starting at 00:00 means nothing ran into this
                // day from the night before: it is before the rota starts,
                // so it is locked rather than offered for a new shift.
                $gaps[] = $this->gap($cursor, $start, $gaps === [] && $cursor === 0);
            }

            $cursor = max($cursor, $end);
        }

        if ($cursor < ShiftTime::MINUTES_PER_DAY) {
            $gaps[] = $this->gap($cursor, ShiftTime::MINUTES_PER_DAY, false);
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
     * @return array{start: string, end: string, locked: bool}
     */
    private function gap(int $from, int $to, bool $locked): array
    {
        return ['start' => ShiftTime::format($from), 'end' => ShiftTime::format($to), 'locked' => $locked];
    }
}
