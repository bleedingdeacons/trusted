<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Service;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Service\ShiftSignup;
use Trusted\Tests\Fixtures\InMemoryAssignmentRepository;
use Trusted\Tests\Fixtures\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;

/**
 * Tests for the sign-up rules.
 *
 * ShiftSignup is the service sibling plugins resolve from Unity's container
 * to let a member claim a shift, so its guarantees are load-bearing outside
 * this plugin: responders only, one member per shift, and never leaking a
 * responder's contact details to another member.
 */
final class ShiftSignupTest extends TestCase
{
    private const DATE = '2026-07-20';

    /**
     * @test
     */
    public function it_refuses_to_assign_a_member_who_is_not_a_telephone_responder(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Member is not a telephone responder.');

        $signup->assignResponder(new ResponderStub(id: 7, telephoneResponder: false), [1]);
    }

    /**
     * @test
     */
    public function it_refuses_to_remove_a_sign_up_for_a_member_who_is_not_a_responder(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        $this->expectException(InvalidArgumentException::class);

        $signup->removeResponder(new ResponderStub(id: 7, telephoneResponder: false), 1);
    }

    /**
     * @test
     */
    public function it_assigns_a_responder_to_an_open_shift(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        $result = $signup->assignResponder(new ResponderStub(id: 7), [1], 'Happy to cover');

        self::assertCount(1, $result['assigned']);
        self::assertSame([], $result['skipped']);
        self::assertSame('7', $result['assigned'][0]['member_id']);
        self::assertSame('Happy to cover', $result['assigned'][0]['notes']);
    }

    /**
     * @test
     */
    public function it_skips_a_shift_that_is_already_taken_rather_than_failing(): void
    {
        // One member per shift. The second sign-up is reported, not thrown:
        // a caller needs to tell the member what was left out.
        $signup = $this->makeSignup(
            [1 => $this->rota(1)],
            new InMemoryAssignmentRepository([
                1 => new Assignment(id: 1, rotaId: 1, memberId: '99'),
            ])
        );

        $result = $signup->assignResponder(new ResponderStub(id: 7), [1]);

        self::assertSame([], $result['assigned']);
        self::assertSame([['rota_id' => 1, 'reason' => 'full']], $result['skipped']);
    }

    /**
     * @test
     */
    public function it_skips_a_shift_that_does_not_exist(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        $result = $signup->assignResponder(new ResponderStub(id: 7), [1, 404]);

        self::assertCount(1, $result['assigned']);
        self::assertSame([['rota_id' => 404, 'reason' => 'not_found']], $result['skipped']);
    }

    /**
     * @test
     */
    public function it_de_duplicates_and_discards_non_positive_ids(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1), 2 => $this->rota(2)]);

        // 1 twice, plus a zero and a negative that must be dropped entirely —
        // not reported as not_found, since they were never real ids.
        $result = $signup->assignResponder(new ResponderStub(id: 7), [1, 1, 0, -3, 2]);

        self::assertCount(2, $result['assigned']);
        self::assertSame([], $result['skipped']);
    }

    /**
     * @test
     */
    public function it_removes_only_the_members_own_sign_up(): void
    {
        $assignments = new InMemoryAssignmentRepository([
            1 => new Assignment(id: 1, rotaId: 1, memberId: '99'),
        ]);
        $signup = $this->makeSignup([1 => $this->rota(1)], $assignments);

        // Member 7 has no assignment on this shift; 99's must survive.
        self::assertFalse($signup->removeResponder(new ResponderStub(id: 7), 1));
        self::assertCount(1, $assignments->findByRota(1));

        // The owner can remove their own.
        self::assertTrue($signup->removeResponder(new ResponderStub(id: 99), 1));
        self::assertSame([], $assignments->findByRota(1));
    }

    /**
     * @test
     */
    public function it_reports_an_open_shift_with_no_assignee(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        $shifts = $signup->openShiftsForDate(self::DATE);

        self::assertCount(1, $shifts);
        self::assertTrue($shifts[0]['is_open']);
        self::assertSame('', $shifts[0]['assignee']);
        self::assertFalse($shifts[0]['is_mine']);
    }

    /**
     * @test
     */
    public function it_names_the_assignee_of_a_filled_shift_but_never_their_contact_details(): void
    {
        $member = new Member(id: '99', name: 'Jane S', email: 'jane@example.test', telephone: '07700 900999');
        $rota = $this->rota(1)->withAssignments([
            (new Assignment(id: 1, rotaId: 1, memberId: '99'))->withMember($member),
        ]);

        $shifts = $this->makeSignup([1 => $rota])->openShiftsForDate(self::DATE);

        self::assertFalse($shifts[0]['is_open']);
        self::assertSame('Jane S', $shifts[0]['assignee']);

        // The whole point of the projection: a member browsing the day sees who
        // is covering, never how to contact them.
        $encoded = json_encode($shifts);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('jane@example.test', $encoded);
        self::assertStringNotContainsString('07700 900999', $encoded);
    }

    /**
     * @test
     */
    public function it_flags_the_members_own_shift(): void
    {
        $member = new Member(id: '99', name: 'Jane S', email: '', telephone: '');
        $rota = $this->rota(1)->withAssignments([
            (new Assignment(id: 1, rotaId: 1, memberId: '99'))->withMember($member),
        ]);
        $signup = $this->makeSignup([1 => $rota]);

        self::assertTrue($signup->openShiftsForDate(self::DATE, '99')[0]['is_mine']);
        self::assertFalse($signup->openShiftsForDate(self::DATE, '7')[0]['is_mine']);
        self::assertFalse(
            $signup->openShiftsForDate(self::DATE)[0]['is_mine'],
            'With no member in context nothing is "mine".'
        );
    }

    /**
     * @test
     */
    public function it_returns_no_shifts_for_a_date_with_none(): void
    {
        $signup = $this->makeSignup([1 => $this->rota(1)]);

        self::assertSame([], $signup->openShiftsForDate('2026-12-25'));
    }

    /**
     * @param array<int, Rota> $rotas
     */
    private function makeSignup(array $rotas, ?InMemoryAssignmentRepository $assignments = null): ShiftSignup
    {
        return new ShiftSignup(
            new InMemoryRotaRepository($rotas),
            $assignments ?? new InMemoryAssignmentRepository(),
        );
    }

    private function rota(int $id): Rota
    {
        return new Rota(
            id: $id,
            slotDate: self::DATE,
            startTime: '09:00',
            endTime: '17:00',
            label: 'Day shift',
        );
    }
}
