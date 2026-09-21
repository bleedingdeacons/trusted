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

/*
 * Tests for the sign-up endpoints' permission gate.
 *
 * These routes are member-facing, so `can()` is the security boundary: it is
 * the permission_callback WordPress consults before any handler runs. It
 * trusts nothing but the `trusted_signup_member` filter, and re-checks what
 * that filter returns rather than taking a sibling plugin's word for it.
 */

function signupController(): SignupController
{
    return new SignupController(new ShiftSignup(
        new InMemoryRotaRepository(),
        new InMemoryAssignmentRepository(),
    ));
}

function mockRequest(): \WP_REST_Request
{
    return Mockery::mock('WP_REST_Request');
}

describe('can', function () {
    it('denies access when no member is signed in', function () {
        // The filter's default. No sibling plugin has resolved a member, so
        // nobody is signed in.
        Filters\expectApplied('trusted_signup_member')->with(null)->andReturn(null);

        expect(signupController()->can())->toBeFalse();
    });

    it('denies access to a member who is not a telephone responder', function () {
        // The filter returned a real Unity member, but not a responder. The
        // controller re-checks rather than trusting the caller — this is the
        // whole reason the check is repeated here.
        Filters\expectApplied('trusted_signup_member')
            ->with(null)
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: false));

        expect(signupController()->can())->toBeFalse();
    });

    it('denies access when the filter returns something that is not a member', function () {
        // A misbehaving filter must not open the door.
        Filters\expectApplied('trusted_signup_member')->with(null)->andReturn('not-a-member');

        expect(signupController()->can())->toBeFalse();
    });

    it('allows a verified telephone responder', function () {
        Filters\expectApplied('trusted_signup_member')
            ->with(null)
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));

        expect(signupController()->can())->toBeTrue();
    });
});

// ── canWrite(): the anti-CSRF gate on the state-changing routes ────
describe('canWrite', function () {
    it('refuses a write when nothing vouches for the request', function () {
        // A signed-in responder, but no sibling answered the verify filter.
        // Its default is false, so the write is refused: this is the case a
        // cross-site POST arrives in, carrying the session cookie the browser
        // attached by itself but no token it could not have read.
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn(false);

        expect(signupController()->canWrite(mockRequest()))->toBeFalse();
    });

    it('allows a write when a sibling vouches for the request', function () {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn(true);

        expect(signupController()->canWrite(mockRequest()))->toBeTrue();
    });

    it('still refuses a verified request from someone not signed in', function () {
        // The token gate is in addition to the member gate, never instead of
        // it. A sibling wrongly returning true must not admit a stranger.
        Filters\expectApplied('trusted_signup_member')->andReturn(null);

        expect(signupController()->canWrite(mockRequest()))->toBeFalse();
    });

    it('refuses a member who is not a responder however well verified', function () {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: false));

        expect(signupController()->canWrite(mockRequest()))->toBeFalse();
    });

    it('opens only for a real yes', function (mixed $answer, bool $expected, string $why) {
        Filters\expectApplied('trusted_signup_member')
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
        Filters\expectApplied('trusted_signup_verify_request')->andReturn($answer);

        expect(signupController()->canWrite(mockRequest()))->toBe($expected, $why);
    })->with([
        'true'         => [true, true, 'The one answer that means verified.'],
        'false'        => [false, false, 'The default.'],
        'null'         => [null, false, 'A filter that returns nothing must not open the gate.'],
        'empty string' => ['', false, 'Nor an empty answer.'],
        'zero'         => [0, false, 'Nor a falsy number.'],
    ]);
});

it('gates the write routes by canWrite and the read by can', function () {
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

    signupController()->registerRoutes();

    expect($routes['/signup/shifts/(?P<date>\d{4}-\d{2}-\d{2})'])->toBe('can', 'The read is not a state change.')
        ->and($routes['/signup'])->toBe('canWrite', 'POST /signup changes helpline coverage.')
        ->and($routes['/signup/(?P<rota>\d+)'])->toBe('canWrite', 'DELETE takes a responder off a shift.');
});

it('validates the date parameter', function (mixed $value, bool $expected, string $why) {
    expect(signupController()->isDate($value))->toBe($expected, $why);
})->with([
    'iso date'     => ['2026-07-20', true, 'The documented format.'],
    'leap day'     => ['2028-02-29', true, '2028 is a leap year.'],
    'non leap day' => ['2027-02-29', false, '2027 is not, so this date does not exist.'],
    'month 13'     => ['2026-13-01', false, 'Out of range.'],
    'slashes'      => ['2026/07/20', false, 'Wrong separator.'],
    'uk order'     => ['20-07-2026', false, 'Day-first is not accepted.'],
    'datetime'     => ['2026-07-20 09:00', false, 'The ! anchor rejects trailing input.'],
    'empty'        => ['', false, 'Nothing to parse.'],
    'not a string' => [20260720, false, 'Only strings are dates here.'],
    'null'         => [null, false, 'A missing parameter is not a date.'],
]);
