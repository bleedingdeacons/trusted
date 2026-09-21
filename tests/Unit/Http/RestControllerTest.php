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
use Trusted\Testing\Doubles\InMemoryRotaRepository;
use Trusted\Tests\Fixtures\ResponderStub;
use Unity\Testing\Doubles\InMemoryMemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/*
 * Exercises the trusted/v1 REST endpoints end to end against the in-memory
 * repositories, the real RotaFactory/ShiftSignup and a real TemplateApplicator
 * (final, so it cannot be mocked — its WordPress calls are stubbed instead).
 */

covers(RestController::class);

/**
 * Builds the controller and its collaborators over the given repositories,
 * optionally seeding the member repository (which is constructor-only).
 *
 * @param ResponderStub[] $members
 */
function restController(
    InMemoryRotaRepository $rota,
    InMemoryAssignmentRepository $assignments,
    array $members = [],
): RestController {
    $factory    = new RotaFactory();
    $memberRepo = new InMemoryMemberRepository($members);

    $applicator = new TemplateApplicator(
        $rota,
        $factory,
        $assignments,
        new AssignmentFactory(),
        new ResponderDirectory($memberRepo),
        new TemplateParser(),
    );

    return new RestController(
        $rota,
        $assignments,
        $memberRepo,
        $applicator,
        $factory,
        new ShiftSignup($rota, $assignments, $memberRepo),
    );
}

function seedSlot(
    InMemoryRotaRepository $rota,
    string $date,
    string $start = '09:00',
    string $end = '12:00',
    string $label = 'AM',
): int {
    return (int) $rota->save((new RotaFactory())->create($date, $start, $end, $label))->id();
}

/**
 * @param array<string, mixed> $params
 */
function restRequest(array $params = []): WP_REST_Request
{
    return new WP_REST_Request($params);
}

beforeEach(function () {
    $this->rota        = new InMemoryRotaRepository();
    $this->assignments = new InMemoryAssignmentRepository();
    $this->controller  = restController($this->rota, $this->assignments);

    // Gives a controller whose member repository knows a telephone responder
    // with id 7, over the same rota and assignment stores.
    $this->withResponder = fn (): RestController => restController(
        $this->rota,
        $this->assignments,
        [new ResponderStub(id: 7, telephoneResponder: true)],
    );

    // A slot on 2026-07-20 whose rota carries a real assignment for member 7.
    $this->seedAssignedSlot = function (): int {
        $rotaId = seedSlot($this->rota, '2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '7', '');
        $slots = $this->rota->findForWeek('2026-07-20');
        $this->rota->save($slots[0]->withAssignments($this->assignments->findByRota($rotaId)));

        return $rotaId;
    };
});

describe('registration, permission and validation', function () {
    it('registers its endpoints', function () {
        $GLOBALS['trusted_rest_routes'] = [];

        $this->controller->registerRoutes();

        expect($GLOBALS['trusted_rest_routes'])->toContain('/rota', '/members');
    });

    it('checks the filtered capability', function () {
        Filters\expectApplied('trusted_capability')->with('manage_options')->andReturn('manage_options');

        expect($this->controller->can())->toBeTrue();
    });

    it('accepts real dates and rejects overflow', function (mixed $value, bool $expected) {
        expect($this->controller->isDate($value))->toBe($expected);
    })->with([
        'real date' => ['2026-07-20', true],
        'overflow'  => ['2026-02-31', false],
        'not dated' => ['nope', false],
        'not text'  => [123, false],
    ]);
});

describe('getWeek', function () {
    it('returns seven days with their slots', function () {
        seedSlot($this->rota, '2026-07-20'); // a Monday

        $data = $this->controller->getWeek(restRequest(['start' => '2026-07-22']))->get_data();

        expect($data['week_start'])->toBe('2026-07-20')
            ->and($data['days'])->toHaveCount(7)
            ->and($data['days'][0]['slots'])->not->toBeEmpty();
    });
});

describe('getWeek gaps', function () {
    it("returns each day's gaps alongside its slots", function () {
        seedSlot($this->rota, '2026-07-21', '09:00', '17:00'); // Tuesday

        $days = $this->controller->getWeek(restRequest(['start' => '2026-07-20']))->get_data()['days'];

        // Monday is empty, so nothing runs into Tuesday: its opening gap is
        // before the rota starts and is locked.
        expect($days[1]['gaps'])->toBe([
            ['start' => '00:00', 'end' => '09:00', 'locked' => true],
            ['start' => '17:00', 'end' => '24:00', 'locked' => false],
        ])->and($days[0]['gaps'])->toBe([['start' => '00:00', 'end' => '24:00', 'locked' => false]]);
    });

    it("starts a day's first gap when the previous night's shift ends", function () {
        seedSlot($this->rota, '2026-07-20', '22:00', '06:00'); // Monday night

        $days = $this->controller->getWeek(restRequest(['start' => '2026-07-20']))->get_data()['days'];

        expect($days[0]['gaps'])->toBe([['start' => '00:00', 'end' => '22:00', 'locked' => true]])
            ->and($days[1]['gaps'])->toBe([['start' => '06:00', 'end' => '24:00', 'locked' => false]]);
    });

    it("carries the previous week's Sunday night into Monday", function () {
        // Sunday is outside the week being shown, but its overnight shift
        // still covers the start of Monday.
        seedSlot($this->rota, '2026-07-19', '22:00', '07:00');

        $days = $this->controller->getWeek(restRequest(['start' => '2026-07-20']))->get_data()['days'];

        expect($days[0]['gaps'])->toBe([['start' => '07:00', 'end' => '24:00', 'locked' => false]]);
    });

    it('does not list the previous Sunday among the week\'s slots', function () {
        seedSlot($this->rota, '2026-07-19', '22:00', '07:00');

        $days = $this->controller->getWeek(restRequest(['start' => '2026-07-20']))->get_data()['days'];

        expect($days[0]['slots'])->toBe([]);
    });
});

describe('clearWeek', function () {
    it('deletes an empty week', function () {
        seedSlot($this->rota, '2026-07-20');

        $response = $this->controller->clearWeek(restRequest(['start' => '2026-07-20']));

        expect($response)->toBeInstanceOf(WP_REST_Response::class)
            ->and($response->get_data()['deleted'])->toBe(1);
    });

    it('refuses when assignments exist', function () {
        ($this->seedAssignedSlot)();

        $response = $this->controller->clearWeek(restRequest(['start' => '2026-07-20']));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_code())->toBe('trusted_week_not_empty');
    });

    it('removes the assignments when clearing them', function () {
        ($this->seedAssignedSlot)();

        $data = $this->controller->clearWeekAssignments(restRequest(['start' => '2026-07-20']))->get_data();

        expect($data['deleted'])->toBe(1);
    });
});

describe('slots', function () {
    it('validates the required fields on create', function () {
        $response = $this->controller->createSlot(restRequest(['date' => 'bad', 'start' => '', 'end' => '', 'label' => '']));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(400);
    });

    it('saves a new slot and returns 201', function () {
        $response = $this->controller->createSlot(restRequest([
            'date' => '2026-07-20', 'start' => '09:00', 'end' => '12:00', 'label' => 'Morning',
        ]));

        expect($response->get_status())->toBe(201);
    });

    it('stores an entered 24:00 as 23:59 and shows it back as 24:00', function () {
        $response = $this->controller->createSlot(restRequest([
            'date' => '2026-07-20', 'start' => '18:00', 'end' => '24:00', 'label' => 'Late',
        ]));

        expect($response->get_data()['end'])->toBe('24:00')
            ->and($this->rota->findForDate('2026-07-20')[0]->endTime())->toBe('23:59');
    });

    it('returns 404 when updating a missing slot', function () {
        $response = $this->controller->updateSlot(restRequest(['id' => 999]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(404);
    });

    it('rejects an empty label on update', function () {
        $id = seedSlot($this->rota, '2026-07-20');

        expect($this->controller->updateSlot(restRequest(['id' => $id, 'label' => ''])))->toBeInstanceOf(WP_Error::class);
    });

    it('updates the times', function () {
        $id = seedSlot($this->rota, '2026-07-20', '09:00', '12:00', 'AM');

        $data = $this->controller->updateSlot(restRequest(['id' => $id, 'start' => '10:00', 'label' => 'Late']))->get_data();

        expect($data['start'])->toBe('10:00');
    });

    it('deletes a slot', function () {
        $id = seedSlot($this->rota, '2026-07-20');

        expect($this->controller->deleteSlot(restRequest(['id' => $id]))->get_data()['deleted'])->toBeTrue();
    });
});

describe('assignments', function () {
    it('rejects a create with missing parameters', function () {
        expect($this->controller->createAssignment(restRequest(['rota_id' => 0])))->toBeInstanceOf(WP_Error::class);
    });

    it('rejects an unknown member', function () {
        $rotaId = seedSlot($this->rota, '2026-07-20');

        expect($this->controller->createAssignment(restRequest(['rota_id' => $rotaId, 'member_id' => '999'])))
            ->toBeInstanceOf(WP_Error::class);
    });

    it('creates an assignment', function () {
        $rotaId = seedSlot($this->rota, '2026-07-20');

        $response = ($this->withResponder)()->createAssignment(restRequest(['rota_id' => $rotaId, 'member_id' => '7']));

        expect($response->get_status())->toBe(201);
    });

    it('reports a full slot', function () {
        $rotaId = seedSlot($this->rota, '2026-07-20');
        $this->assignments->assignIfOpen($rotaId, '99', ''); // already taken

        $response = ($this->withResponder)()->createAssignment(restRequest(['rota_id' => $rotaId, 'member_id' => '7']));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(409);
    });

    it('deletes an assignment', function () {
        $rotaId = seedSlot($this->rota, '2026-07-20');
        $assignment = $this->assignments->assignIfOpen($rotaId, '7', '');

        expect($this->controller->deleteAssignment(restRequest(['id' => (int) $assignment->id()]))->get_data()['deleted'])
            ->toBeTrue();
    });
});

describe('bulkAssign', function () {
    it('rejects missing parameters', function () {
        expect($this->controller->bulkAssign(restRequest(['member_id' => ''])))->toBeInstanceOf(WP_Error::class);
    });

    it('assigns every distinct valid slot', function () {
        $a = seedSlot($this->rota, '2026-07-20');
        $b = seedSlot($this->rota, '2026-07-21');

        $response = ($this->withResponder)()->bulkAssign(restRequest([
            'member_id' => '7', 'rota_ids' => [$a, $b, $a, 0], // dupes/invalid dropped
        ]));

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['created'])->toHaveCount(2);
    });

    it('rejects an unknown member', function () {
        $a = seedSlot($this->rota, '2026-07-20');

        expect($this->controller->bulkAssign(restRequest(['member_id' => '999', 'rota_ids' => [$a]])))
            ->toBeInstanceOf(WP_Error::class);
    });
});

describe('getMembers', function () {
    it('returns responders and filters them by search', function () {
        $controller = restController($this->rota, $this->assignments, [
            new ResponderStub(id: 7, telephoneResponder: true, anonymousName: 'Alice'),
            new ResponderStub(id: 8, telephoneResponder: true, anonymousName: 'Bob'),
        ]);

        expect($controller->getMembers(restRequest())->get_data())->toHaveCount(2)
            ->and($controller->getMembers(restRequest(['search' => 'alice']))->get_data())->toHaveCount(1);
    });
});

describe('templates', function () {
    it('lists templates', function () {
        Functions\expect('get_posts')->andReturn([(object) ['ID' => 3]]);
        Functions\expect('get_the_title')->andReturn('Weekday');

        expect($this->controller->getTemplates()->get_data()[0])->toBe(['id' => 3, 'title' => 'Weekday']);
    });

    it('validates an apply', function () {
        expect($this->controller->applyTemplate(restRequest(['template_id' => 0])))->toBeInstanceOf(WP_Error::class);
    });

    it('applies a template to the snapped week', function () {
        // An empty template (no shift fields) applies cleanly, creating nothing.
        Functions\expect('get_post_meta')->andReturn('');

        $data = $this->controller->applyTemplate(restRequest([
            'template_id' => 3, 'week_start' => '2026-07-22', 'replace' => true,
        ]))->get_data();

        expect($data['created'])->toBe(0)
            ->and($data['week_start'])->toBe('2026-07-20');
    });

    it('validates creating a template from a week', function (array $params) {
        expect($this->controller->createTemplateFromWeek(restRequest($params)))->toBeInstanceOf(WP_Error::class);
    })->with([
        'bad week start' => [['week_start' => 'bad']],
        'empty title'    => [['week_start' => '2026-07-20', 'title' => '']],
    ]);

    it('creates a template from a week', function () {
        Functions\expect('wp_insert_post')->andReturn(42);
        Functions\expect('update_post_meta')->andReturn(true);

        $response = $this->controller->createTemplateFromWeek(restRequest([
            'week_start' => '2026-07-20', 'title' => 'My Template', 'include_members' => false,
        ]));

        expect($response->get_status())->toBe(201)
            ->and($response->get_data()['id'])->toBe(42);
    });

    it('reports a failure to create a template', function () {
        Functions\expect('wp_insert_post')->andReturn(0);
        Functions\expect('update_post_meta')->andReturn(true);

        $response = $this->controller->createTemplateFromWeek(restRequest([
            'week_start' => '2026-07-20', 'title' => 'My Template',
        ]));

        expect($response)->toBeInstanceOf(WP_Error::class)
            ->and($response->get_error_data()['status'])->toBe(500);
    });
});
