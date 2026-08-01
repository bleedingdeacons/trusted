<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Brain\Monkey\Filters;
use Trusted\Factory\RotaFactory;
use Trusted\Http\SignupController;
use Trusted\Service\ShiftSignup;
use Trusted\Tests\Fixtures\InMemoryAssignmentRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Trusted\Tests\Fixtures\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Covers the member-facing sign-up endpoints beyond the permission gate that
 * SignupControllerTest already pins.
 *
 * @covers \Trusted\Http\SignupController
 */
final class SignupControllerEndpointsTest extends TestCase
{
    private InMemoryRotaRepository $rota;
    private InMemoryAssignmentRepository $assignments;
    private RotaFactory $factory;
    private SignupController $controller;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory     = new RotaFactory();
        $this->rota        = new InMemoryRotaRepository();
        $this->assignments = new InMemoryAssignmentRepository();
        $members           = new InMemoryMemberRepository([new ResponderStub(id: 7, telephoneResponder: true)]);

        $this->controller = new SignupController(
            new ShiftSignup($this->rota, $this->assignments, $members)
        );
    }

    private function actingResponder(): void
    {
        Filters\expectApplied('trusted_signup_member')->with(null)
            ->andReturn(new ResponderStub(id: 7, telephoneResponder: true));
    }

    private function noMember(): void
    {
        Filters\expectApplied('trusted_signup_member')->with(null)->andReturn(null);
    }

    private function seedSlot(string $date): int
    {
        return (int) $this->rota->save($this->factory->create($date, '09:00', '12:00', 'AM'))->id();
    }

    public function testRegisterRoutes(): void
    {
        $GLOBALS['trusted_rest_routes'] = [];
        $this->controller->registerRoutes();
        self::assertContains('/signup', $GLOBALS['trusted_rest_routes']);
    }

    public function testSendNoCacheHeadersOnSignupRoutes(): void
    {
        $response = new WP_REST_Response(['ok' => true]);
        $request  = new WP_REST_Request([], '/trusted/v1/signup');

        $out = $this->controller->sendNoCacheHeaders($response, new WP_REST_Server(), $request);
        self::assertArrayHasKey('Cache-Control', $out->headers);
    }

    public function testSendNoCacheHeadersLeavesOtherRoutesAlone(): void
    {
        $response = new WP_REST_Response(['ok' => true]);
        $request  = new WP_REST_Request([], '/trusted/v1/week/2026-07-20');

        $out = $this->controller->sendNoCacheHeaders($response, new WP_REST_Server(), $request);
        self::assertSame([], $out->headers);
    }

    public function testShiftsListsOpenShifts(): void
    {
        $this->actingResponder();
        $this->seedSlot('2026-07-20');

        $response = $this->controller->shifts(new WP_REST_Request(['date' => '2026-07-20']));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertNotEmpty($response->get_data());
    }

    public function testSignUpRejectsWhenNotSignedIn(): void
    {
        $this->noMember();
        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => [1]]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(401, $response->get_error_data()['status']);
    }

    public function testSignUpRejectsWithoutShifts(): void
    {
        $this->actingResponder();
        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => []]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(400, $response->get_error_data()['status']);
    }

    public function testSignUpAssignsTheResponder(): void
    {
        $this->actingResponder();
        $rotaId = $this->seedSlot('2026-07-20');

        $response = $this->controller->signUp(new WP_REST_Request(['rota_ids' => [$rotaId, $rotaId, 0]]));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(201, $response->get_status());
    }

    public function testRemoveSignUpRejectsWhenNotSignedIn(): void
    {
        $this->noMember();
        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => 1]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(401, $response->get_error_data()['status']);
    }

    public function testRemoveSignUpReports404WhenNotAssigned(): void
    {
        $this->actingResponder();
        $rotaId = $this->seedSlot('2026-07-20');

        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => $rotaId]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(404, $response->get_error_data()['status']);
    }

    public function testRemoveSignUpRemovesOwnAssignment(): void
    {
        $this->actingResponder();
        $rotaId = $this->seedSlot('2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '7', ''); // member 7 signed up

        $response = $this->controller->removeSignUp(new WP_REST_Request(['rota' => $rotaId]));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertTrue($response->get_data()['removed']);
    }
}
