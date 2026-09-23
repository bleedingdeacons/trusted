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
 * Each shift becomes a time-window rule on its weekday, in the shape Tamar's
 * hunt-group editor holds:
 *
 *   - `days` is the single weekday code the window falls on;
 *   - `from`/`to` lie within 00:00–23:59, the forwarding system's day — the
 *     end of the day is 23:59, which is also how Trusted stores it;
 *   - a filled shift forwards to the responder's telephone, labelled with
 *     their anonymous name. The target id is `num:` plus the number's digits,
 *     which is how Tamar's parser identifies a number, so the same responder
 *     resolves to the same target however their number is spaced;
 *   - an unfilled shift forwards to voicemail. So does one whose member Unity
 *     no longer has, or who has no telephone: a call cannot reach them either;
 *   - priority is the rule's position in the week, earliest first, because
 *     Tamar hunts in row order and that is what decides overlapping windows.
 *
 * A shift that straddles midnight (end at or before start, see GapFinder)
 * cannot be one window within a day, so it is split: its start to 23:59 on its
 * own day, then 00:00 to its end on the next, both forwarding to the same
 * destination. A Sunday night shift continues onto Monday — a hunt group is a
 * weekly schedule, so that is where it would ring.
 *
 * Pure: nothing here reads WordPress or talks to a forwarding driver. It is
 * a preview, and building rules is not pushing them.
 *
 * @phpstan-import-type UnfilledShift from ForwardingSchedule
 */
final class RotaForwardingProjection
{
    /** Weekday codes as a time-window match stores them, Monday first. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** The first minute of the forwarding system's day. */
    private const START_OF_DAY = '00:00';

    private ForwardingTarget $voicemail;

    /**
     * @param ForwardingTarget|null $voicemail Where unfilled shifts forward.
     *                                         Trusted cannot know the upstream's
     *                                         mailbox id, so the default is a
     *                                         generic voicemail target.
     */
    public function __construct(?ForwardingTarget $voicemail = null)
    {
        $this->voicemail = $voicemail ?? new ForwardingTarget([
            'id'    => 'vm:default',
            'kind'  => ForwardingTarget::KIND_VOICEMAIL,
            'label' => 'Voicemail',
        ]);
    }

    /**
     * @param array<Rota> $slots A week's slots, assignments eager-loaded, in any order.
     */
    public function project(array $slots): ForwardingSchedule
    {
        usort($slots, static fn (Rota $a, Rota $b): int
            => [$a->slotDate(), $a->startTime(), (int) $a->id()] <=> [$b->slotDate(), $b->startTime(), (int) $b->id()]);

        $rules    = [];
        $targets  = [];
        $unfilled = [];

        foreach ($slots as $slot) {
            [$target, $label, $why] = $this->destination($slot);

            $targets[$target->getId()] ??= $target;

            foreach ($this->windows($slot, $this->dayCode($slot->slotDate())) as [$day, $from, $to]) {
                $priority = count($rules) + 1;
                $id       = (string) $priority;

                $rules[] = new ForwardingRule([
                    'id'        => $id,
                    'label'     => $label,
                    'match'     => [
                        'type'  => 'time_window',
                        'value' => ['days' => [$day], 'from' => $from, 'to' => $to],
                    ],
                    'target_id' => $target->getId(),
                    'enabled'   => true,
                    'priority'  => $priority,
                ]);

                if ($why !== null) {
                    $unfilled[$id] = $why;
                }
            }
        }

        return new ForwardingSchedule($rules, array_values($targets), $unfilled);
    }

    /**
     * Where a slot's calls go, the rule label, and — when that is voicemail —
     * why.
     *
     * @return array{ForwardingTarget, string, UnfilledShift|null}
     */
    private function destination(Rota $slot): array
    {
        $assignment = $slot->assignments()[0] ?? null;

        if ($assignment === null) {
            return $this->toVoicemail(ForwardingSchedule::UNASSIGNED);
        }

        $member = $assignment->member();

        if ($member === null) {
            return $this->toVoicemail(ForwardingSchedule::MEMBER_MISSING);
        }

        $number = trim($member->telephone());
        $digits = (string) preg_replace('/\D+/', '', $number);

        if ($digits === '') {
            return $this->toVoicemail(ForwardingSchedule::NO_TELEPHONE, $member->name());
        }

        $target = new ForwardingTarget([
            'id'      => 'num:' . $digits,
            'kind'    => ForwardingTarget::KIND_NUMBER,
            'label'   => $member->name(),
            'address' => $number,
        ]);

        return [$target, $member->name(), null];
    }

    /**
     * @return array{ForwardingTarget, string, UnfilledShift}
     */
    private function toVoicemail(string $reason, string $member = ''): array
    {
        return [$this->voicemail, $this->voicemail->getLabel(), ['reason' => $reason, 'member' => $member]];
    }

    /**
     * The time windows a slot forwards in, as [day, from, to] triples, each
     * within 00:00–23:59.
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

        // A shift ending at exactly midnight has nothing to carry over.
        if (ShiftTime::minutes($end) > 0) {
            $windows[] = [$this->nextDay($day), self::START_OF_DAY, $end];
        }

        return $windows;
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
