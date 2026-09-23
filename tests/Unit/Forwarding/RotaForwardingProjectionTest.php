<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Forwarding;

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Factory\RotaFactory;
use Trusted\Forwarding\ForwardingSchedule;
use Trusted\Forwarding\RotaForwardingProjection;

/*
 * A week of the rota laid out as a hunt group.
 *
 * The shape under test is Tamar's: one time-window row per shift, on the one
 * weekday its date falls on, within the forwarding system's 00:00–23:59 day,
 * hunted in row order. A filled shift forwards to a `num:<digits>` target; an
 * unfilled one forwards to voicemail, and the schedule records why. A shift
 * that straddles midnight is split, both halves to the same destination.
 *
 * 2026-09-21 is a Monday.
 */

covers(RotaForwardingProjection::class, ForwardingSchedule::class);

/** A slot built the way the plugin builds them, so storage rules apply. */
function slot(string $date, string $start, string $end, ?Member $member = null, bool $assigned = true, int $id = 1): Rota
{
    $rota = (new RotaFactory())->create($date, $start, $end, 'Shift')->withId($id);

    if (! $assigned) {
        return $rota;
    }

    return $rota->withAssignments([
        (new Assignment(1, $id, $member?->id() ?? '404'))->withMember($member),
    ]);
}

function responder(string $name = 'Steve C', string $telephone = '07700 900123', string $id = '7'): Member
{
    return new Member($id, $name, 'steve@example.org', $telephone);
}

/**
 * @return array{days: list<string>, from: string, to: string}
 */
function windowOf(ForwardingRule $rule): array
{
    /** @var array{days: list<string>, from: string, to: string} */
    return $rule->getMatch()['value'];
}

describe('an assigned shift', function () {
    it('becomes one time-window rule on its weekday, forwarding to the responder', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-23', '10:00', '14:00', responder())]);

        expect($schedule->rules)->toHaveCount(1)
            ->and($schedule->unfilled)->toBe([]);

        $rule = $schedule->rules[0];

        expect($rule->getLabel())->toBe('Steve C')
            ->and($rule->getMatchType())->toBe('time_window')
            ->and(windowOf($rule))->toBe(['days' => ['wed'], 'from' => '10:00', 'to' => '14:00'])
            ->and($rule->getTargetId())->toBe('num:07700900123')
            ->and($rule->isEnabled())->toBeTrue()
            ->and($rule->getPriority())->toBe(1);
    });

    it('keeps the end of the day as 23:59, the way Tamar holds it', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-21', '18:00', '24:00', responder())]);

        expect(windowOf($schedule->rules[0])['to'])->toBe('23:59');
    });

    it('offers the responder as a number target', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-21', '10:00', '14:00', responder())]);

        expect($schedule->targets)->toHaveCount(1)
            ->and($schedule->targets[0]->toArray())->toBe([
                'id'      => 'num:07700900123',
                'label'   => 'Steve C',
                'kind'    => 'number',
                'address' => '07700 900123',
            ]);
    });

    // However a number is spaced, the digits identify it, as Tamar's parser does.
    it('shares one target between shifts forwarding to the same number', function () {
        $schedule = (new RotaForwardingProjection())->project([
            slot('2026-09-21', '10:00', '14:00', responder(telephone: '07700 900123'), id: 1),
            slot('2026-09-22', '10:00', '14:00', responder(telephone: '07700900123'), id: 2),
        ]);

        expect($schedule->rules)->toHaveCount(2)
            ->and($schedule->targets)->toHaveCount(1);
    });
});

describe('hunt order', function () {
    it('numbers rules through the week, earliest first, whatever order the slots arrive in', function () {
        $schedule = (new RotaForwardingProjection())->project([
            slot('2026-09-22', '09:00', '12:00', responder('Tue'), id: 3),
            slot('2026-09-21', '14:00', '18:00', responder('Mon pm'), id: 2),
            slot('2026-09-21', '09:00', '12:00', responder('Mon am'), id: 1),
        ]);

        expect(array_map(static fn (ForwardingRule $r): string => $r->getLabel(), $schedule->rules))
            ->toBe(['Mon am', 'Mon pm', 'Tue'])
            ->and(array_map(static fn (ForwardingRule $r): int => $r->getPriority(), $schedule->rules))
            ->toBe([1, 2, 3])
            ->and(array_map(static fn (ForwardingRule $r): string => $r->getId(), $schedule->rules))
            ->toBe(['1', '2', '3']);
    });
});

describe('an overnight shift', function () {
    it('splits into the rest of its own day and the start of the next, both to the same person', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-23', '22:00', '06:00', responder())]);

        expect($schedule->rules)->toHaveCount(2)
            ->and($schedule->rules[0]->getLabel())->toBe('Steve C')
            ->and($schedule->rules[1]->getLabel())->toBe('Steve C')
            ->and($schedule->rules[1]->getTargetId())->toBe($schedule->rules[0]->getTargetId())
            ->and(windowOf($schedule->rules[0]))->toBe(['days' => ['wed'], 'from' => '22:00', 'to' => '23:59'])
            ->and(windowOf($schedule->rules[1]))->toBe(['days' => ['thu'], 'from' => '00:00', 'to' => '06:00'])
            ->and($schedule->rules[1]->getPriority())->toBe(2);
    });

    // A hunt group repeats weekly, so Sunday night carries on into Monday.
    it('carries a Sunday night shift onto Monday', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-27', '22:00', '02:00', responder())]);

        expect(windowOf($schedule->rules[1])['days'])->toBe(['mon']);
    });

    it('has nothing to carry when it ends at exactly midnight', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-23', '22:00', '00:00', responder())]);

        expect($schedule->rules)->toHaveCount(1)
            ->and(windowOf($schedule->rules[0]))->toBe(['days' => ['wed'], 'from' => '22:00', 'to' => '23:59']);
    });
});

describe('an unfilled shift', function () {
    it('forwards to voicemail when nobody has it', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-24', '10:00', '24:00', assigned: false)]);

        expect($schedule->rules)->toHaveCount(1);

        $rule = $schedule->rules[0];

        expect($rule->getLabel())->toBe('Voicemail')
            ->and($rule->getTargetId())->toBe('vm:default')
            ->and(windowOf($rule))->toBe(['days' => ['thu'], 'from' => '10:00', 'to' => '23:59'])
            ->and($schedule->unfilledFor($rule))->toBe(['reason' => ForwardingSchedule::UNASSIGNED, 'member' => ''])
            ->and($schedule->targets)->toHaveCount(1)
            ->and($schedule->targets[0]->toArray())->toBe([
                'id'      => 'vm:default',
                'label'   => 'Voicemail',
                'kind'    => 'voicemail',
                'address' => '',
            ]);
    });

    it('forwards to voicemail when the assigned member is gone from Unity', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-24', '10:00', '14:00', null)]);

        expect($schedule->rules[0]->getTargetId())->toBe('vm:default')
            ->and($schedule->unfilledFor($schedule->rules[0]))
            ->toBe(['reason' => ForwardingSchedule::MEMBER_MISSING, 'member' => '']);
    });

    it('forwards to voicemail, naming the member, when they have no number', function (string $telephone) {
        $schedule = (new RotaForwardingProjection())->project([
            slot('2026-09-24', '10:00', '14:00', responder(telephone: $telephone)),
        ]);

        expect($schedule->rules[0]->getTargetId())->toBe('vm:default')
            ->and($schedule->unfilledFor($schedule->rules[0]))
            ->toBe(['reason' => ForwardingSchedule::NO_TELEPHONE, 'member' => 'Steve C']);
    })->with([
        'empty'     => [''],
        'blank'     => ['   '],
        'no digits' => ['n/a'],
    ]);

    it('is split across midnight like any other, both halves to voicemail', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-23', '20:00', '08:00', assigned: false)]);

        expect($schedule->rules)->toHaveCount(2)
            ->and(windowOf($schedule->rules[0]))->toBe(['days' => ['wed'], 'from' => '20:00', 'to' => '23:59'])
            ->and(windowOf($schedule->rules[1]))->toBe(['days' => ['thu'], 'from' => '00:00', 'to' => '08:00'])
            ->and($schedule->rules[0]->getTargetId())->toBe('vm:default')
            ->and($schedule->rules[1]->getTargetId())->toBe('vm:default')
            ->and($schedule->unfilledFor($schedule->rules[1]))->toBe(['reason' => ForwardingSchedule::UNASSIGNED, 'member' => '']);
    });

    it('takes its place in hunt order among the filled shifts', function () {
        $schedule = (new RotaForwardingProjection())->project([
            slot('2026-09-21', '09:00', '12:00', responder(), id: 1),
            slot('2026-09-21', '12:00', '15:00', assigned: false, id: 2),
            slot('2026-09-21', '15:00', '18:00', responder(), id: 3),
        ]);

        expect(array_map(static fn (ForwardingRule $r): string => $r->getTargetId(), $schedule->rules))
            ->toBe(['num:07700900123', 'vm:default', 'num:07700900123'])
            ->and($schedule->unfilled)->toHaveCount(1)
            ->and($schedule->unfilledFor($schedule->rules[0]))->toBeNull()
            ->and($schedule->unfilledFor($schedule->rules[1]))->not->toBeNull()
            ->and($schedule->unfilledFor($schedule->rules[2]))->toBeNull();
    });

    it('forwards to whichever voicemail target it is given', function () {
        $mailbox  = new ForwardingTarget(['id' => 'vm:20042', 'kind' => 'voicemail', 'label' => 'Voice to Email', 'address' => '20042']);
        $schedule = (new RotaForwardingProjection($mailbox))->project([slot('2026-09-24', '10:00', '14:00', assigned: false)]);

        expect($schedule->rules[0])
            ->getTargetId()->toBe('vm:20042')
            ->getLabel()->toBe('Voice to Email')
            ->and($schedule->targets)->toBe([$mailbox]);
    });
});

it('makes an empty schedule from an empty week', function () {
    $schedule = (new RotaForwardingProjection())->project([]);

    expect($schedule->rules)->toBe([])
        ->and($schedule->targets)->toBe([])
        ->and($schedule->unfilled)->toBe([]);
});
