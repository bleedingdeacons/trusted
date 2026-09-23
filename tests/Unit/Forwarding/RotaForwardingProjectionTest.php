<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Forwarding;

use Beacon\Forwarding\Models\ForwardingRule;
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
 * weekday its date falls on, forwarding to a `num:<digits>` target, hunted in
 * row order. What the projection must never do is drop a shift quietly — a
 * shift that cannot become a row comes back as skipped, with the reason.
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
            ->and($schedule->skipped)->toBe([]);

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
    it('splits into the rest of its own day and the start of the next', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-23', '22:00', '06:00', responder())]);

        expect($schedule->rules)->toHaveCount(2)
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

describe('a shift that forwards nowhere', function () {
    it('is skipped as unassigned when nobody has it', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-24', '10:00', '24:00', assigned: false)]);

        expect($schedule->rules)->toBe([])
            ->and($schedule->targets)->toBe([])
            ->and($schedule->skipped)->toBe([[
                'day'    => 'thu',
                'date'   => '2026-09-24',
                'start'  => '10:00',
                'end'    => '24:00',
                'label'  => 'Shift',
                'member' => '',
                'reason' => ForwardingSchedule::UNASSIGNED,
            ]]);
    });

    it('is skipped when the assigned member is gone from Unity', function () {
        $schedule = (new RotaForwardingProjection())->project([slot('2026-09-24', '10:00', '14:00', null)]);

        expect($schedule->skipped[0]['reason'])->toBe(ForwardingSchedule::MEMBER_MISSING);
    });

    it('is skipped, naming the member, when they have no number', function (string $telephone) {
        $schedule = (new RotaForwardingProjection())->project([
            slot('2026-09-24', '10:00', '14:00', responder(telephone: $telephone)),
        ]);

        expect($schedule->rules)->toBe([])
            ->and($schedule->skipped[0])
            ->reason->toBe(ForwardingSchedule::NO_TELEPHONE)
            ->member->toBe('Steve C');
    })->with([
        'empty'        => [''],
        'blank'        => ['   '],
        'no digits'    => ['n/a'],
    ]);
});

it('makes an empty schedule from an empty week', function () {
    $schedule = (new RotaForwardingProjection())->project([]);

    expect($schedule->rules)->toBe([])
        ->and($schedule->targets)->toBe([])
        ->and($schedule->skipped)->toBe([]);
});
