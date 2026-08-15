<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;
use Trusted\Http\RestController;
use Trusted\Service\ShiftSignup;
use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplateParser;
use Trusted\Testing\Doubles\InMemoryAssignmentRepository;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Trusted\Tests\TestCase;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Exercises the trusted/v1 REST endpoints end to end against the in-memory
 * repositories, the real RotaFactory/ShiftSignup and a real TemplateApplicator
 * (final, so it cannot be mocked — its WordPress calls are stubbed instead).
 *
 * @covers \Trusted\Http\RestController
 */
final class RestControllerTest extends TestCase
{
    private InMemoryRotaRepository $rota;
    private InMemoryAssignmentRepository $assignments;
    private RotaFactory $factory;
    private RestController $controller;

    protected function setUp(): void
    {
        parent::setUp();
        $this->build();
    }

    /**
     * (Re)build the controller and its collaborators, optionally seeding the
     * member repository (which is constructor-only).
     *
     * @param ResponderStub[] $members
     */
    private function build(array $members = []): void
    {
        $this->factory     = new RotaFactory();
        $this->rota        = new InMemoryRotaRepository();
        $this->assignments = new InMemoryAssignmentRepository();
        $memberRepo        = new InMemoryMemberRepository($members);

        $applicator = new TemplateApplicator(
            $this->rota,
            $this->factory,
            $this->assignments,
            new AssignmentFactory(),
            new ResponderDirectory($memberRepo),
            new TemplateParser(),
        );

        $signup = new ShiftSignup($this->rota, $this->assignments, $memberRepo);

        $this->controller = new RestController(
            $this->rota,
            $this->assignments,
            $memberRepo,
            $applicator,
            $this->factory,
            $signup,
        );
    }

    private function seedSlot(string $date, string $start = '09:00', string $end = '12:00', string $label = 'AM'): int
    {
        return (int) $this->rota->save($this->factory->create($date, $start, $end, $label))->id();
    }

    private function request(array $params): WP_REST_Request
    {
        return new WP_REST_Request($params);
    }

    // --- registration / permission / validation ----------------------------

    public function testRegisterRoutesRegistersEndpoints(): void
    {
        $GLOBALS['trusted_rest_routes'] = [];
        $this->controller->registerRoutes();
        self::assertContains('/rota', $GLOBALS['trusted_rest_routes']);
        self::assertContains('/members', $GLOBALS['trusted_rest_routes']);
    }

    public function testCanChecksTheCapability(): void
    {
        Filters\expectApplied('trusted_capability')->with('manage_options')->andReturn('manage_options');
        self::assertTrue($this->controller->can());
    }

    public function testIsDateAcceptsRealDatesAndRejectsOverflow(): void
    {
        self::assertTrue($this->controller->isDate('2026-07-20'));
        self::assertFalse($this->controller->isDate('2026-02-31')); // overflow
        self::assertFalse($this->controller->isDate('nope'));
        self::assertFalse($this->controller->isDate(123));
    }

    // --- getWeek ------------------------------------------------------------

    public function testGetWeekReturnsSevenDaysWithSlots(): void
    {
        $this->seedSlot('2026-07-20'); // a Monday
        $data = $this->controller->getWeek($this->request(['start' => '2026-07-22']))->get_data();

        self::assertSame('2026-07-20', $data['week_start']);
        self::assertCount(7, $data['days']);
        self::assertNotEmpty($data['days'][0]['slots']);
    }

    // --- clearWeek ----------------------------------------------------------

    public function testClearWeekDeletesAnEmptyWeek(): void
    {
        $this->seedSlot('2026-07-20');
        $response = $this->controller->clearWeek($this->request(['start' => '2026-07-20']));
        self::assertInstanceOf(WP_REST_Response::class, $response);
        self::assertSame(1, $response->get_data()['deleted']);
    }

    public function testClearWeekRefusesWhenAssignmentsExist(): void
    {
        $rotaId = $this->seedSlot('2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '7', '');
        $slots = $this->rota->findForWeek('2026-07-20');
        $this->rota->save($slots[0]->withAssignments($this->assignments->findByRota($rotaId)));

        $response = $this->controller->clearWeek($this->request(['start' => '2026-07-20']));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame('trusted_week_not_empty', $response->get_error_code());
    }

    public function testClearWeekAssignmentsRemovesAssignments(): void
    {
        $rotaId = $this->seedSlot('2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '7', '');
        $slots = $this->rota->findForWeek('2026-07-20');
        $this->rota->save($slots[0]->withAssignments($this->assignments->findByRota($rotaId)));

        $data = $this->controller->clearWeekAssignments($this->request(['start' => '2026-07-20']))->get_data();
        self::assertSame(1, $data['deleted']);
    }

    // --- createSlot / updateSlot / deleteSlot -------------------------------

    public function testCreateSlotValidatesRequiredFields(): void
    {
        $response = $this->controller->createSlot($this->request(['date' => 'bad', 'start' => '', 'end' => '', 'label' => '']));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(400, $response->get_error_data()['status']);
    }

    public function testCreateSlotSavesAndReturns201(): void
    {
        $response = $this->controller->createSlot($this->request([
            'date' => '2026-07-20', 'start' => '09:00', 'end' => '12:00', 'label' => 'Morning',
        ]));
        self::assertSame(201, $response->get_status());
    }

    public function testUpdateSlotReturns404WhenMissing(): void
    {
        $response = $this->controller->updateSlot($this->request(['id' => 999]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(404, $response->get_error_data()['status']);
    }

    public function testUpdateSlotRejectsAnEmptyLabel(): void
    {
        $id = $this->seedSlot('2026-07-20');
        $response = $this->controller->updateSlot($this->request(['id' => $id, 'label' => '']));
        self::assertInstanceOf(WP_Error::class, $response);
    }

    public function testUpdateSlotUpdatesTimes(): void
    {
        $id = $this->seedSlot('2026-07-20', '09:00', '12:00', 'AM');
        $data = $this->controller->updateSlot($this->request(['id' => $id, 'start' => '10:00', 'label' => 'Late']))->get_data();
        self::assertSame('10:00', $data['start']);
    }

    public function testDeleteSlot(): void
    {
        $id = $this->seedSlot('2026-07-20');
        self::assertTrue($this->controller->deleteSlot($this->request(['id' => $id]))->get_data()['deleted']);
    }

    // --- assignments --------------------------------------------------------

    public function testCreateAssignmentRejectsMissingParams(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->controller->createAssignment($this->request(['rota_id' => 0])));
    }

    public function testCreateAssignmentRejectsUnknownMember(): void
    {
        $rotaId = $this->seedSlot('2026-07-20');
        self::assertInstanceOf(
            WP_Error::class,
            $this->controller->createAssignment($this->request(['rota_id' => $rotaId, 'member_id' => '999']))
        );
    }

    public function testCreateAssignmentSucceeds(): void
    {
        $this->build([new ResponderStub(id: 7, telephoneResponder: true)]);
        $rotaId = $this->seedSlot('2026-07-20');

        $response = $this->controller->createAssignment($this->request(['rota_id' => $rotaId, 'member_id' => '7']));
        self::assertSame(201, $response->get_status());
    }

    public function testCreateAssignmentReportsSlotFull(): void
    {
        $this->build([new ResponderStub(id: 7, telephoneResponder: true)]);
        $rotaId = $this->seedSlot('2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '99', ''); // already taken

        $response = $this->controller->createAssignment($this->request(['rota_id' => $rotaId, 'member_id' => '7']));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(409, $response->get_error_data()['status']);
    }

    public function testBulkAssignRejectsMissingParams(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->controller->bulkAssign($this->request(['member_id' => ''])));
    }

    public function testBulkAssignSucceeds(): void
    {
        $this->build([new ResponderStub(id: 7, telephoneResponder: true)]);
        $a = $this->seedSlot('2026-07-20');
        $b = $this->seedSlot('2026-07-21');

        $response = $this->controller->bulkAssign($this->request([
            'member_id' => '7', 'rota_ids' => [$a, $b, $a, 0], // dupes/invalid dropped
        ]));
        self::assertSame(201, $response->get_status());
        self::assertCount(2, $response->get_data()['created']);
    }

    public function testBulkAssignRejectsUnknownMember(): void
    {
        $a = $this->seedSlot('2026-07-20');
        self::assertInstanceOf(
            WP_Error::class,
            $this->controller->bulkAssign($this->request(['member_id' => '999', 'rota_ids' => [$a]]))
        );
    }

    public function testDeleteAssignment(): void
    {
        $rotaId = $this->seedSlot('2026-07-20');
        $assignment = $this->assignments->assignIfOpen($rotaId, '7', '');
        self::assertTrue($this->controller->deleteAssignment($this->request(['id' => (int) $assignment->id()]))->get_data()['deleted']);
    }

    // --- members ------------------------------------------------------------

    public function testGetMembersReturnsRespondersAndFilters(): void
    {
        $this->build([
            new ResponderStub(id: 7, telephoneResponder: true, anonymousName: 'Alice'),
            new ResponderStub(id: 8, telephoneResponder: true, anonymousName: 'Bob'),
        ]);

        self::assertCount(2, $this->controller->getMembers($this->request([]))->get_data());
        self::assertCount(1, $this->controller->getMembers($this->request(['search' => 'alice']))->get_data());
    }

    // --- templates ----------------------------------------------------------

    public function testGetTemplates(): void
    {
        Functions\expect('get_posts')->andReturn([(object) ['ID' => 3]]);
        Functions\expect('get_the_title')->andReturn('Weekday');

        $data = $this->controller->getTemplates()->get_data();
        self::assertSame(['id' => 3, 'title' => 'Weekday'], $data[0]);
    }

    public function testApplyTemplateValidates(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->controller->applyTemplate($this->request(['template_id' => 0])));
    }

    public function testApplyTemplateCreatesSlots(): void
    {
        // An empty template (no shift fields) applies cleanly, creating nothing.
        Functions\expect('get_post_meta')->andReturn('');

        $data = $this->controller->applyTemplate($this->request([
            'template_id' => 3, 'week_start' => '2026-07-22', 'replace' => true,
        ]))->get_data();

        self::assertSame(0, $data['created']);
        self::assertSame('2026-07-20', $data['week_start']);
    }

    public function testCreateTemplateFromWeekValidates(): void
    {
        self::assertInstanceOf(WP_Error::class, $this->controller->createTemplateFromWeek($this->request(['week_start' => 'bad'])));
        self::assertInstanceOf(WP_Error::class, $this->controller->createTemplateFromWeek($this->request(['week_start' => '2026-07-20', 'title' => ''])));
    }

    public function testCreateTemplateFromWeekSucceeds(): void
    {
        Functions\expect('wp_insert_post')->andReturn(42);
        Functions\expect('update_post_meta')->andReturn(true);

        $response = $this->controller->createTemplateFromWeek($this->request([
            'week_start' => '2026-07-20', 'title' => 'My Template', 'include_members' => false,
        ]));
        self::assertSame(201, $response->get_status());
        self::assertSame(42, $response->get_data()['id']);
    }

    public function testCreateTemplateFromWeekReportsFailure(): void
    {
        Functions\expect('wp_insert_post')->andReturn(0);
        Functions\expect('update_post_meta')->andReturn(true);

        $response = $this->controller->createTemplateFromWeek($this->request([
            'week_start' => '2026-07-20', 'title' => 'My Template',
        ]));
        self::assertInstanceOf(WP_Error::class, $response);
        self::assertSame(500, $response->get_error_data()['status']);
    }
}
