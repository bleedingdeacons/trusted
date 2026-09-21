<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Domain;

use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Domain\Shift;
use Trusted\Support\MemberPresenter;
use Trusted\Tests\Fixtures\ResponderStub;

/*
 * Tests for the value objects and the Unity-to-Trusted member mapping.
 *
 * These objects are serialised straight onto the REST boundary, so their
 * array shape is a contract with the calendar UI, not an implementation
 * detail.
 */

describe('Shift', function () {
    it('exposes its parts and serialises to the UI shape', function () {
        $shift = new Shift('09:00', '17:00', 'Day shift', 'John D');

        expect($shift->startTime())->toBe('09:00')
            ->and($shift->member())->toBe('John D')
            ->and($shift->toArray())->toBe(['start' => '09:00', 'end' => '17:00', 'label' => 'Day shift', 'member' => 'John D'])
            ->and($shift->jsonSerialize())->toBe($shift->toArray());
    });

    it('defaults its optional parts to empty strings', function () {
        $shift = new Shift('09:00', '17:00');

        expect($shift->label())->toBe('')
            ->and($shift->member())->toBe('', 'Empty means no member to pre-assign, not null.');
    });
});

describe('Rota', function () {
    it('returns a new instance from withId and leaves the original alone', function () {
        $rota = new Rota(null, '2026-07-20', '09:00', '17:00', 'Day shift');
        $saved = $rota->withId(12);

        expect($rota->id())->toBeNull('The original is untouched.')
            ->and($saved->id())->toBe(12)
            ->and($saved)->not->toBe($rota)
            ->and($saved->label())->toBe('Day shift', 'Everything else carries over.');
    });

    it('returns a new instance from withAssignments', function () {
        $rota = new Rota(1, '2026-07-20', '09:00', '17:00');
        $filled = $rota->withAssignments([new Assignment(1, 1, '99')]);

        expect($rota->assignments())->toBe([])
            ->and($filled->assignments())->toHaveCount(1);
    });
});

describe('Assignment', function () {
    it('attaches a member without mutating', function () {
        $assignment = new Assignment(1, 12, '99');
        $withMember = $assignment->withMember(new Member('99', 'Jane S', 'jane@example.test', '07700 900999'));

        expect($assignment->member())->toBeNull()
            ->and($withMember->member())->not->toBeNull()
            ->and($withMember->member()?->name())->toBe('Jane S');
    });
});

describe('Member', function () {
    it('serialises every field', function () {
        $member = new Member('99', 'Jane S', 'jane@example.test', '07700 900999');

        expect($member->id())->toBe('99')
            ->and($member->jsonSerialize())->toBe($member->toArray())
            ->and($member->toArray()['email'])->toBe('jane@example.test');
    });
});

describe('MemberPresenter', function () {
    it('maps Unity fields onto Trusted ones', function () {
        // The single mapping point between Unity's domain and Trusted's REST
        // boundary: anonymous name -> name, personal email -> email,
        // mobile number -> telephone.
        $member = MemberPresenter::toMember(new ResponderStub(
            id: 42,
            anonymousName: 'John D',
            personalEmail: 'john@example.test',
            mobileNumber: '07700 900123',
        ));

        expect($member->id())->toBe('42', 'Unity ids are ints; Trusted keys members by string.')
            ->and($member->name())->toBe('John D')
            ->and($member->email())->toBe('john@example.test')
            ->and($member->telephone())->toBe('07700 900123');
    });
});
