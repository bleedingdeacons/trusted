<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;
use Trusted\Http\SignupController;
use Trusted\Service\ShiftSignup;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;

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
        Filters\expectApplied('trusted_signup_member')->with(null)->andReturn(null);

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
        Filters\expectApplied('trusted_signup_member')
            ->with(null)
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: false));

        self::assertFalse($this->makeController()->can());
    }

    /**
     * @test
     */
    public function it_denies_access_when_the_filter_returns_something_that_is_not_a_member(): void
    {
        // A misbehaving filter must not open the door.
        Filters\expectApplied('trusted_signup_member')->with(null)->andReturn('not-a-member');

        self::assertFalse($this->makeController()->can());
    }

    /**
     * @test
     */
    public function it_allows_a_verified_telephone_responder(): void
    {
        Filters\expectApplied('trusted_signup_member')
            ->with(null)
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));

        self::assertTrue($this->makeController()->can());
    }

    // ── canWrite(): the anti-CSRF gate on the state-changing routes ────

    /**
     * @test
     */
    public function a_write_is_refused_when_nothing_vouches_for_the_request(): void
    {
        // A signed-in responder, but no sibling answered the verify filter.
        // Its default is false, so the write is refused: this is the case a
        // cross-site POST arrives in, carrying the session cookie the browser
        // attached by itself but no token it could not have read.
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn(false);

        self::assertFalse($this->makeController()->canWrite($this->request()));
    }

    /**
     * @test
     */
    public function a_write_is_allowed_when_a_sibling_vouches_for_the_request(): void
    {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn(true);

        self::assertTrue($this->makeController()->canWrite($this->request()));
    }

    /**
     * @test
     */
    public function a_verified_request_from_someone_not_signed_in_is_still_refused(): void
    {
        // The token gate is in addition to the member gate, never instead of
        // it. A sibling wrongly returning true must not admit a stranger.
        Filters\expectApplied('trusted_signup_member')->andReturn(null);

        self::assertFalse($this->makeController()->canWrite($this->request()));
    }

    /**
     * @test
     */
    public function a_write_is_refused_for_a_member_who_is_not_a_responder_however_well_verified(): void
    {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: false));

        self::assertFalse($this->makeController()->canWrite($this->request()));
    }

    /**
     * @test
     * @dataProvider truthyProvider
     */
    public function only_a_real_yes_opens_the_write_gate(mixed $answer, bool $expected, string $why): void
    {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn($answer);

        self::assertSame($expected, $this->makeController()->canWrite($this->request()), $why);
    }

    /**
     * @return array<string, array{0:mixed,1:bool,2:string}>
     */
    public static function truthyProvider(): array
    {
        return [
            'true'         => [true, true, 'The one answer that means verified.'],
            'false'        => [false, false, 'The default.'],
            'null'         => [null, false, 'A filter that returns nothing must not open the gate.'],
            'empty string' => ['', false, 'Nor an empty answer.'],
            'zero'         => [0, false, 'Nor a falsy number.'],
        ];
    }

    /**
     * @test
     */
    public function the_write_routes_are_gated_by_canWrite_and_the_read_by_can(): void
    {
        // Guards the wiring rather than the gate: a route registered against
        // can() instead of canWrite() would pass every test above and still
        // be forgeable.
        $routes = [];
        Functions\when('register_rest_route')->alias(
            static function ($namespace, $route, $args) use (&$routes): bool {
                $routes[$route] = $args['permission_callback'][1];

                return true;
            }
        );
        Functions\when('add_filter')->justReturn(true);

        $this->makeController()->registerRoutes();

        self::assertSame('can', $routes['/signup/shifts/(?P<date>\d{4}-\d{2}-\d{2})'], 'The read is not a state change.');
        self::assertSame('canWrite', $routes['/signup'], 'POST /signup changes helpline coverage.');
        self::assertSame('canWrite', $routes['/signup/(?P<rota>\d+)'], 'DELETE takes a responder off a shift.');
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

    private function request(): \WP_REST_Request
    {
        return Mockery::mock('WP_REST_Request');
    }

    private function makeController(): SignupController
    {
        return new SignupController(new ShiftSignup(
            new InMemoryRotaRepository(),
            new InMemoryAssignmentRepository(),
        ));
    }
}
