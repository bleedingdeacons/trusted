<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Domain\Shift;
use Trusted\Support\MemberPresenter;
use Trusted\Tests\Fixtures\ResponderStub;

/**
 * Tests for the value objects and the Unity-to-Trusted member mapping.
 *
 * These objects are serialised straight onto the REST boundary, so their
 * array shape is a contract with the calendar UI, not an implementation
 * detail.
 */
final class DomainTest extends TestCase
{
    /**
     * @test
     */
    public function shift_exposes_its_parts_and_serialises_to_the_ui_shape(): void
    {
        $shift = new Shift('09:00', '17:00', 'Day shift', 'John D');

        self::assertSame('09:00', $shift->startTime());
        self::assertSame('John D', $shift->member());
        self::assertSame(
            ['start' => '09:00', 'end' => '17:00', 'label' => 'Day shift', 'member' => 'John D'],
            $shift->toArray()
        );
        self::assertSame($shift->toArray(), $shift->jsonSerialize());
    }

    /**
     * @test
     */
    public function shift_defaults_its_optional_parts_to_empty_strings(): void
    {
        $shift = new Shift('09:00', '17:00');

        self::assertSame('', $shift->label());
        self::assertSame('', $shift->member(), 'Empty means no member to pre-assign, not null.');
    }

    /**
     * @test
     */
    public function rota_with_id_returns_a_new_instance_and_leaves_the_original_alone(): void
    {
        $rota = new Rota(null, '2026-07-20', '09:00', '17:00', 'Day shift');
        $saved = $rota->withId(12);

        self::assertNull($rota->id(), 'The original is untouched.');
        self::assertSame(12, $saved->id());
        self::assertNotSame($rota, $saved);
        self::assertSame('Day shift', $saved->label(), 'Everything else carries over.');
    }

    /**
     * @test
     */
    public function rota_with_assignments_returns_a_new_instance(): void
    {
        $rota = new Rota(1, '2026-07-20', '09:00', '17:00');
        $filled = $rota->withAssignments([new Assignment(1, 1, '99')]);

        self::assertSame([], $rota->assignments());
        self::assertCount(1, $filled->assignments());
    }

    /**
     * @test
     */
    public function assignment_with_member_attaches_without_mutating(): void
    {
        $assignment = new Assignment(1, 12, '99');
        $withMember = $assignment->withMember(new Member('99', 'Jane S', 'jane@example.test', '07700 900999'));

        self::assertNull($assignment->member());
        self::assertNotNull($withMember->member());
        self::assertSame('Jane S', $withMember->member()?->name());
    }

    /**
     * @test
     */
    public function member_serialises_every_field(): void
    {
        $member = new Member('99', 'Jane S', 'jane@example.test', '07700 900999');

        self::assertSame('99', $member->id());
        self::assertSame($member->toArray(), $member->jsonSerialize());
        self::assertSame('jane@example.test', $member->toArray()['email']);
    }

    /**
     * @test
     */
    public function presenter_maps_unity_fields_onto_trusted_ones(): void
    {
        // The single mapping point between Unity's domain and Trusted's REST
        // boundary: anonymous name -> name, personal email -> email,
        // mobile number -> telephone.
        $member = MemberPresenter::toMember(new ResponderStub(
            id: 42,
            anonymousName: 'John D',
            personalEmail: 'john@example.test',
            mobileNumber: '07700 900123',
        ));

        self::assertSame('42', $member->id(), 'Unity ids are ints; Trusted keys members by string.');
        self::assertSame('John D', $member->name());
        self::assertSame('john@example.test', $member->email());
        self::assertSame('07700 900123', $member->telephone());
    }
}
