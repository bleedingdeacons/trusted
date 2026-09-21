<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Service;

use InvalidArgumentException;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Service\ShiftSignup;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;

/*
 * Tests for the sign-up rules.
 *
 * ShiftSignup is the service sibling plugins resolve from Unity's container
 * to let a member claim a shift, so its guarantees are load-bearing outside
 * this plugin: responders only, one member per shift, and never leaking a
 * responder's contact details to another member.
 */

const DATE = '2026-07-20';

/**
 * @param array<int, Rota> $rotas
 */
function makeSignup(array $rotas, ?InMemoryAssignmentRepository $assignments = null): ShiftSignup
{
    return new ShiftSignup(
        new InMemoryRotaRepository($rotas),
        $assignments ?? new InMemoryAssignmentRepository(),
    );
}

function rota(int $id): Rota
{
    return new Rota(
        id: $id,
        slotDate: DATE,
        startTime: '09:00',
        endTime: '17:00',
        label: 'Day shift',
    );
}

describe('assignResponder', function () {
    it('refuses a member who is not a telephone responder', function () {
        makeSignup([1 => rota(1)])->assignResponder(new ResponderStub(id: 7, telephoneResponder: false), [1]);
    })->throws(InvalidArgumentException::class, 'Member is not a telephone responder.');

    it('assigns a responder to an open shift', function () {
        $result = makeSignup([1 => rota(1)])->assignResponder(new ResponderStub(id: 7), [1], 'Happy to cover');

        expect($result['assigned'])->toHaveCount(1)
            ->and($result['skipped'])->toBe([])
            ->and($result['assigned'][0]['member_id'])->toBe('7')
            ->and($result['assigned'][0]['notes'])->toBe('Happy to cover');
    });

    it('skips a shift that is already taken rather than failing', function () {
        // One member per shift. The second sign-up is reported, not thrown:
        // a caller needs to tell the member what was left out.
        $signup = makeSignup(
            [1 => rota(1)],
            new InMemoryAssignmentRepository([
                1 => new Assignment(id: 1, rotaId: 1, memberId: '99'),
            ])
        );

        $result = $signup->assignResponder(new ResponderStub(id: 7), [1]);

        expect($result['assigned'])->toBe([])
            ->and($result['skipped'])->toBe([['rota_id' => 1, 'reason' => 'full']]);
    });

    it('skips a shift that does not exist', function () {
        $result = makeSignup([1 => rota(1)])->assignResponder(new ResponderStub(id: 7), [1, 404]);

        expect($result['assigned'])->toHaveCount(1)
            ->and($result['skipped'])->toBe([['rota_id' => 404, 'reason' => 'not_found']]);
    });

    it('de-duplicates and discards non-positive ids', function () {
        $signup = makeSignup([1 => rota(1), 2 => rota(2)]);

        // 1 twice, plus a zero and a negative that must be dropped entirely —
        // not reported as not_found, since they were never real ids.
        $result = $signup->assignResponder(new ResponderStub(id: 7), [1, 1, 0, -3, 2]);

        expect($result['assigned'])->toHaveCount(2)
            ->and($result['skipped'])->toBe([]);
    });
});

describe('removeResponder', function () {
    it('refuses a member who is not a responder', function () {
        makeSignup([1 => rota(1)])->removeResponder(new ResponderStub(id: 7, telephoneResponder: false), 1);
    })->throws(InvalidArgumentException::class);

    it("removes only the member's own sign-up", function () {
        $assignments = new InMemoryAssignmentRepository([
            1 => new Assignment(id: 1, rotaId: 1, memberId: '99'),
        ]);
        $signup = makeSignup([1 => rota(1)], $assignments);

        // Member 7 has no assignment on this shift; 99's must survive.
        expect($signup->removeResponder(new ResponderStub(id: 7), 1))->toBeFalse()
            ->and($assignments->findByRota(1))->toHaveCount(1);

        // The owner can remove their own.
        expect($signup->removeResponder(new ResponderStub(id: 99), 1))->toBeTrue()
            ->and($assignments->findByRota(1))->toBe([]);
    });
});

describe('openShiftsForDate', function () {
    it('reports an open shift with no assignee', function () {
        $shifts = makeSignup([1 => rota(1)])->openShiftsForDate(DATE);

        expect($shifts)->toHaveCount(1)
            ->and($shifts[0]['is_open'])->toBeTrue()
            ->and($shifts[0]['assignee'])->toBe('')
            ->and($shifts[0]['is_mine'])->toBeFalse();
    });

    it('names the assignee of a filled shift but never their contact details', function () {
        $member = new Member(id: '99', name: 'Jane S', email: 'jane@example.test', telephone: '07700 900999');
        $rota = rota(1)->withAssignments([
            (new Assignment(id: 1, rotaId: 1, memberId: '99'))->withMember($member),
        ]);

        $shifts = makeSignup([1 => $rota])->openShiftsForDate(DATE);

        expect($shifts[0]['is_open'])->toBeFalse()
            ->and($shifts[0]['assignee'])->toBe('Jane S');

        // The whole point of the projection: a member browsing the day sees who
        // is covering, never how to contact them.
        expect(json_encode($shifts))
            ->toBeString()
            ->not->toContain('jane@example.test')
            ->not->toContain('07700 900999');
    });

    it("flags the member's own shift", function () {
        $member = new Member(id: '99', name: 'Jane S', email: '', telephone: '');
        $rota = rota(1)->withAssignments([
            (new Assignment(id: 1, rotaId: 1, memberId: '99'))->withMember($member),
        ]);
        $signup = makeSignup([1 => $rota]);

        expect($signup->openShiftsForDate(DATE, '99')[0]['is_mine'])->toBeTrue()
            ->and($signup->openShiftsForDate(DATE, '7')[0]['is_mine'])->toBeFalse()
            ->and($signup->openShiftsForDate(DATE)[0]['is_mine'])->toBeFalse('With no member in context nothing is "mine".');
    });

    it('returns no shifts for a date with none', function () {
        expect(makeSignup([1 => rota(1)])->openShiftsForDate('2026-12-25'))->toBe([]);
    });
});
