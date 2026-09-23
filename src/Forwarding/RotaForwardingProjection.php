<?php

declare(strict_types=1);

namespace Trusted\Forwarding;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Trusted\Domain\Rota;
use Trusted\Domain\ShiftTime;

/**
 * Lays a week of rota slots out as the hunt group that would forward them.
 *
 * Each assigned shift becomes one time-window rule on its weekday, forwarding
 * to the responder's telephone, in the shape Tamar's hunt-group editor holds:
 *
 *   - one row per shift, labelled with the responder's anonymous name;
 *   - `days` is the single weekday code the shift's date falls on;
 *   - `from`/`to` are the shift's times, with the end of the day as 23:59 —
 *     the convention Trusted stores and Tamar's editor uses alike;
 *   - the target id is `num:` plus the number's digits, which is how Tamar's
 *     parser identifies a number, so the same responder resolves to the same
 *     target however their number is spaced;
 *   - priority is the rule's position in the week, earliest first, because
 *     Tamar hunts in row order and that is what decides overlapping windows.
 *
 * A shift that runs overnight (end at or before start, see GapFinder) cannot
 * be one window, so it becomes two: its start to 23:59 on its own day, then
 * 00:00 to its end on the next. A Sunday night shift continues onto Monday —
 * a hunt group is a weekly schedule, so that is where it would ring.
 *
 * Shifts that cannot become a rule are returned as skipped, with the reason.
 *
 * Pure: nothing here reads WordPress or talks to a forwarding driver. It is
 * a preview, and building rules is not pushing them.
 *
 * @phpstan-import-type SkippedShift from ForwardingSchedule
 */
final class RotaForwardingProjection
{
    /** Weekday codes as a time-window match stores them, Monday first. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /**
     * @param array<Rota> $slots A week's slots, assignments eager-loaded, in any order.
     */
    public function project(array $slots): ForwardingSchedule
    {
        usort($slots, static fn (Rota $a, Rota $b): int
            => [$a->slotDate(), $a->startTime(), (int) $a->id()] <=> [$b->slotDate(), $b->startTime(), (int) $b->id()]);

        $rules   = [];
        $targets = [];
        $skipped = [];

        foreach ($slots as $slot) {
            $day        = $this->dayCode($slot->slotDate());
            $assignment = $slot->assignments()[0] ?? null;
            $member     = $assignment?->member();

            if ($assignment === null) {
                $skipped[] = $this->skip($slot, $day, '', ForwardingSchedule::UNASSIGNED);
                continue;
            }

            if ($member === null) {
                $skipped[] = $this->skip($slot, $day, '', ForwardingSchedule::MEMBER_MISSING);
                continue;
            }

            $number = trim($member->telephone());
            $digits = (string) preg_replace('/\D+/', '', $number);

            if ($digits === '') {
                $skipped[] = $this->skip($slot, $day, $member->name(), ForwardingSchedule::NO_TELEPHONE);
                continue;
            }

            $targetId = 'num:' . $digits;

            $targets[$targetId] ??= new ForwardingTarget([
                'id'      => $targetId,
                'kind'    => ForwardingTarget::KIND_NUMBER,
                'label'   => $member->name(),
                'address' => $number,
            ]);

            foreach ($this->windows($slot, $day) as [$windowDay, $from, $to]) {
                $priority = count($rules) + 1;

                $rules[] = new ForwardingRule([
                    'id'        => (string) $priority,
                    'label'     => $member->name(),
                    'match'     => [
                        'type'  => 'time_window',
                        'value' => ['days' => [$windowDay], 'from' => $from, 'to' => $to],
                    ],
                    'target_id' => $targetId,
                    'enabled'   => true,
                    'priority'  => $priority,
                ]);
            }
        }

        return new ForwardingSchedule($rules, array_values($targets), $skipped);
    }

    /**
     * The time windows a slot forwards in, as [day, from, to] triples.
     *
     * @return list<array{string, string, string}>
     */
    private function windows(Rota $slot, string $day): array
    {
        $start = $slot->startTime();
        $end   = $slot->endTime();

        if (ShiftTime::endMinutes($end) > ShiftTime::minutes($start)) {
            return [[$day, $start, $end]];
        }

        $windows = [[$day, $start, ShiftTime::LAST_MINUTE]];

        // An overnight shift ending at exactly midnight has nothing to carry.
        if (ShiftTime::minutes($end) > 0) {
            $windows[] = [$this->nextDay($day), '00:00', $end];
        }

        return $windows;
    }

    /**
     * @return SkippedShift
     */
    private function skip(Rota $slot, string $day, string $member, string $reason): array
    {
        return [
            'day'    => $day,
            'date'   => $slot->slotDate(),
            'start'  => $slot->startTime(),
            'end'    => ShiftTime::toShown($slot->endTime()),
            'label'  => $slot->label(),
            'member' => $member,
            'reason' => $reason,
        ];
    }

    /** The weekday code for a Y-m-d date: 'mon' … 'sun'. */
    private function dayCode(string $date): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $dt === false ? self::DAYS[0] : self::DAYS[(int) $dt->format('N') - 1];
    }

    private function nextDay(string $day): string
    {
        $index = (int) array_search($day, self::DAYS, true);

        return self::DAYS[($index + 1) % 7];
    }
}
