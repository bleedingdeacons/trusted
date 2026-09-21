<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Brain\Monkey\Filters;
use Trusted\Factory\RotaFactory;
use Trusted\Http\SignupController;
use Trusted\Service\ShiftSignup;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/*
 * Covers the member-facing sign-up endpoints beyond the permission gate that
 * SignupControllerTest already pins.
 */

covers(SignupController::class);

function actingResponder(): void
{
    Filters\expectApplied('trusted_signup_member')->with(null)
        ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
}

function noMemberSignedIn(): void
{
    Filters\expectApplied('trusted_signup_member')->with(null)->andReturn(null);
}

function openSlot(InMemoryRotaRepository $rota, string $date): int
{
    return (int) $rota->save((new RotaFactory())->create($date, '09:00', '12:00', 'AM'))->id();
}

beforeEach(function () {
    $this->rota        = new InMemoryRotaRepository();
    $this->assignments = new InMemoryAssignmentRepository();
    $members           = new InMemoryMemberRepository([new ResponderStub(id: 7, telephoneResponder: true)]);

    $this->controller = new SignupController(
        new ShiftSignup($this->rota, $this->assignments, $members)
    );
});

it('registers its routes', function () {
    $GLOBALS['trusted_rest_routes'] = [];

    $this->controller->registerRoutes();

    expect($GLOBALS['trusted_rest_routes'])->toContain('/signup');
});

describe('sendNoCacheHeaders', function () {
    it('sends no-cache headers on sign-up routes', function () {
        $out = $this->controller->sendNoCacheHeaders(
            new WP_REST_Response(['ok' => true]),
            new WP_REST_Server(),
            new WP_REST_Request([], '/trusted/v1/signup'),
        );

        expect($out->headers)->toHaveKey('Cache-Control');
    });

    it('leaves other routes alone', function () {
        $out = $this->controller->sendNoCacheHeaders(
            new WP_REST_Response(['ok' => true]),
            new WP_REST_Server(),
            new WP_REST_Request([], '/trusted/v1/week/2026-07-20'),
        );

        expect($out->headers)->toBe([]);
    });
});

describe('shifts', function () {
    it('lists the open shifts', function () {
        actingResponder();
        openSlot($this->rota, '2026-07-20');

        $response = $this->controller->shifts(new WP_REST_Request(['date' => '2026-07-20']));

        expect($response)->toBeInstanceOf(WP_REST_Response::class)
            ->and($response->get_data())->not->toBeEmpty();
    });
});

describe('signUp', function () {
    it('rejects a request when nobody is signed in', function () {
        noMemberSignedIn();

        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => [1]]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(401);
    });

    it('rejects a request without shifts', function () {
        actingResponder();

        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => []]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(400);
    });

    it('assigns the responder', function () {
        actingResponder();
        $rotaId = openSlot($this->rota, '2026-07-20');

        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => [$rotaId, $rotaId, 0]]));

        expect($response)->toBeInstanceOf(WP_REST_Response::class)
            ->and($response->get_status())->toBe(201);
    });
});

describe('removeSignUp', function () {
    it('rejects a request when nobody is signed in', function () {
        noMemberSignedIn();

        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => 1]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(401);
    });

    it('reports 404 when the member is not assigned', function () {
        actingResponder();
        $rotaId = openSlot($this->rota, '2026-07-20');

        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => $rotaId]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(404);
    });

    it("removes the member's own assignment", function () {
        actingResponder();
        $rotaId = openSlot($this->rota, '2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '7', ''); // member 7 signed up

        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => $rotaId]));

        expect($response)->toBeInstanceOf(WP_REST_Response::class)
            ->and($response->get_data()['removed'])->toBeTrue();
    });
});
