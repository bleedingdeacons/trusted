<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Trusted\Http\SignupController;
use Trusted\Service\ShiftSignup;
use Trusted\Tests\Fixtures\InMemoryAssignmentRepository;
use Trusted\Tests\Fixtures\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;
use WP_Mock;

/**
 * Tests for the sign-up endpoints' permission gate.
 *
 * These routes are member-facing, so `can()` is the security boundary: it is
 * the permission_callback WordPress consults before any handler runs. It
 * trusts nothing but the `trusted_signup_member` filter, and re-checks what
 * that filter returns rather than taking a sibling plugin's word for it.
 */
final class SignupControllerTest extends TestCase
{
    /**
     * @test
     */
    public function it_denies_access_when_no_member_is_signed_in(): void
    {
        // The filter's default. No sibling plugin has resolved a member, so
        // nobody is signed in.
        WP_Mock::onFilter('trusted_signup_member')->with(null)->reply(null);

        self::assertFalse($this->makeController()->can());
    }

    /**
     * @test
     */
    public function it_denies_access_to_a_member_who_is_not_a_telephone_responder(): void
    {
        // The filter returned a real Unity member, but not a responder. The
        // controller re-checks rather than trusting the caller — this is the
        // whole reason the check is repeated here.
        WP_Mock::onFilter('trusted_signup_member')
            ->with(null)
            ->reply(new ResponderStub(id: 7, telephoneResponder: false));

        self::assertFalse($this->makeController()->can());
    }

    /**
     * @test
     */
    public function it_denies_access_when_the_filter_returns_something_that_is_not_a_member(): void
    {
        // A misbehaving filter must not open the door.
        WP_Mock::onFilter('trusted_signup_member')->with(null)->reply('not-a-member');

        self::assertFalse($this->makeController()->can());
    }

    /**
     * @test
     */
    public function it_allows_a_verified_telephone_responder(): void
    {
        WP_Mock::onFilter('trusted_signup_member')
            ->with(null)
            ->reply(new ResponderStub(id: 7, telephoneResponder: true));

        self::assertTrue($this->makeController()->can());
    }

    /**
     * @test
     * @dataProvider dateProvider
     */
    public function it_validates_the_date_parameter(mixed $value, bool $expected, string $why): void
    {
        self::assertSame($expected, $this->makeController()->isDate($value), $why);
    }

    /**
     * @return array<string, array{0:mixed,1:bool,2:string}>
     */
    public static function dateProvider(): array
    {
        return [
            'iso date'          => ['2026-07-20', true, 'The documented format.'],
            'leap day'          => ['2028-02-29', true, '2028 is a leap year.'],
            'non leap day'      => ['2027-02-29', false, '2027 is not, so this date does not exist.'],
            'month 13'          => ['2026-13-01', false, 'Out of range.'],
            'slashes'           => ['2026/07/20', false, 'Wrong separator.'],
            'uk order'          => ['20-07-2026', false, 'Day-first is not accepted.'],
            'datetime'          => ['2026-07-20 09:00', false, 'The ! anchor rejects trailing input.'],
            'empty'             => ['', false, 'Nothing to parse.'],
            'not a string'      => [20260720, false, 'Only strings are dates here.'],
            'null'              => [null, false, 'A missing parameter is not a date.'],
        ];
    }

    private function makeController(): SignupController
    {
        return new SignupController(new ShiftSignup(
            new InMemoryRotaRepository(),
            new InMemoryAssignmentRepository(),
        ));
    }
}
