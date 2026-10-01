<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Http;

use Beacon\Forwarding\Models\ForwardingRule;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Mockery;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Factory\RotaFactory;
use Trusted\Http\ForwardingCheckController;
use Trusted\Http\RestController;
use WP_Error;
use WP_REST_Response;

/*
 * The Rota Calendar's "Check Tamar forwarding": the current week's rota
 * against Tamar's hunt group for it, read through tamar/find_huntgroup.
 *
 * Tamar registers the filter when it is active; Brain Monkey records the
 * add_filter() for has_filter(), and expectApplied() stands in for Tamar's
 * callback. Now is fixed at Wednesday 30 September 2026, ISO week 40.
 */

covers(ForwardingCheckController::class);

const CHECK_MONDAY = '2026-09-28';

function checkSlot(string $date, string $start, string $end, ?string $name = null, string $telephone = '07700 900123', int $id = 1): Rota
{
    $rota = (new RotaFactory())->create($date, $start, $end, 'Shift')->withId($id);

    if ($name === null) {
        return $rota;
    }

    return $rota->withAssignments([
        (new Assignment(1, $id, '7'))->withMember(new Member('7', $name, 'x@example.org', $telephone)),
    ]);
}

/** @param list<string> $days */
function heldRule(int $priority, string $targetId, array $days, string $from, string $to, string $label = ''): ForwardingRule
{
    return new ForwardingRule([
        'id'        => (string) $priority,
        'priority'  => $priority,
        'label'     => $label,
        'match'     => ['type' => 'time_window', 'value' => ['days' => $days, 'from' => $from, 'to' => $to]],
        'target_id' => $targetId,
        'enabled'   => true,
    ]);
}

beforeEach(function () {
    Functions\when('current_datetime')->justReturn(new DateTimeImmutable('2026-09-30 11:00:00'));

    $this->rota       = Mockery::mock(RotaRepositoryInterface::class);
    $this->controller = new ForwardingCheckController($this->rota);

    $this->tamarActive = function (): void {
        add_filter('tamar/find_huntgroup', fn () => null, 10, 2);
    };

    // The rota's current week: a filled Monday morning, an unfilled afternoon.
    $this->week = function (): void {
        $this->rota->shouldReceive('findForWeek')->once()->with(CHECK_MONDAY)->andReturn([
            checkSlot(CHECK_MONDAY, '10:00', '14:00', 'Anon A', id: 1),
            checkSlot(CHECK_MONDAY, '14:00', '18:00', id: 2),
        ]);
    };

    /** @param array<string, mixed>|null $group */
    $this->tamarHolds = function (?array $group): void {
        Filters\expectApplied('tamar/find_huntgroup')->once()
            ->with(null, 'Forward Week 40')
            ->andReturn($group);
    };
});

it('registers the check and sync routes under the calendar\'s namespace', function () {
    $registered = [];
    Functions\when('register_rest_route')->alias(
        function (string $ns, string $route, array $args) use (&$registered): bool {
            $registered[] = [$ns, $route, $args['methods']];
            return true;
        }
    );

    $this->controller->registerRoutes();

    expect($registered)->toBe([
        [RestController::NAMESPACE, '/forwarding-check', 'GET'],
        [RestController::NAMESPACE, '/forwarding-sync', 'POST'],
    ]);
});

it('lets in only users with the Trusted capability', function () {
    expect($this->controller->can())->toBeTrue();

    WpState::$userCan = false;
    expect($this->controller->can())->toBeFalse();
});

it('is available only while Tamar has registered its lookup', function () {
    expect(ForwardingCheckController::available())->toBeFalse();

    ($this->tamarActive)();
    expect(ForwardingCheckController::available())->toBeTrue();
});

it('reports a match when Tamar forwards the week as the rota does', function () {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)(['id' => '180000', 'name' => 'Forward Week 40', 'rules' => [
        heldRule(1, 'num:07700900123', ['mon'], '10:00', '14:00', 'Anon A'),
        heldRule(2, 'vm:20042', ['mon'], '14:00', '18:00'),
    ]]);

    $response = $this->controller->check();

    expect($response)->toBeInstanceOf(WP_REST_Response::class)
        ->and($response->get_data())->toBe([
            'week_start'  => CHECK_MONDAY,
            'name'        => 'Forward Week 40',
            'status'      => 'match',
            'message'     => 'Tamar\'s "Forward Week 40" matches this week\'s rota.',
            'differences' => [],
            'can_sync'    => false,
        ]);
});

it('warns when Tamar has no hunt group named for the week', function () {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)(null);

    expect($this->controller->check()->get_data())->toMatchArray([
        'status'  => 'missing',
        'message' => 'Tamar has no hunt group named "Forward Week 40" for this week.',
    ]);
});

it('warns with each difference when the hunt group does not match', function () {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)(['id' => '180000', 'name' => 'Forward Week 40', 'rules' => [
        heldRule(1, 'num:01454898476', ['mon'], '10:00', '14:00', 'Steve C'),
        heldRule(2, 'num:07700900456', ['tue'], '09:00', '12:00', 'Anon B'),
    ]]);

    $data = $this->controller->check()->get_data();

    expect($data['status'])->toBe('mismatch')
        ->and($data['message'])->toBe('Tamar\'s "Forward Week 40" does not match this week\'s rota: 3 differences.')
        ->and(array_column($data['differences'], 'message'))->toBe([
            'Mon 10:00–14:00: the rota forwards to Anon A (07700900123), but Tamar forwards to Steve C (01454898476).',
            'Mon 14:00–18:00: the rota forwards to voicemail, but Tamar has no row for this time.',
            'Tue 09:00–12:00: Tamar forwards to Anon B (07700900456), but the rota has no shift at this time.',
        ]);
});

it('never asks Tamar to publish anything', function () {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)(null);
    Filters\expectApplied('tamar/publish_huntgroup')->never();

    $this->controller->check();
});

it('is an error, not a missing group, when Tamar is not active', function () {
    $this->rota->shouldNotReceive('findForWeek');

    $error = $this->controller->check();

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_code())->toBe('trusted_forwarding_unavailable')
        ->and($error->get_error_data())->toBe(['status' => 503]);
});

it('passes on the reason Tamar gives when it cannot read the panel', function () {
    ($this->tamarActive)();
    ($this->week)();
    Filters\expectApplied('tamar/find_huntgroup')->once()
        ->andReturnUsing(fn () => throw new \RuntimeException('Upstream returned status 503.'));

    $error = $this->controller->check();

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_message())->toBe('Could not read "Forward Week 40" from Tamar: Upstream returned status 503.')
        ->and($error->get_error_data())->toBe(['status' => 502]);
});

it('does not take an answer without rules for a hunt group', function (mixed $group) {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)($group);

    $error = $this->controller->check();

    expect($error)->toBeInstanceOf(WP_Error::class)
        ->and($error->get_error_message())->toBe('Tamar did not return the rules of "Forward Week 40".');
})->with([
    'no rules'        => [['id' => '1', 'name' => 'Forward Week 40']],
    'not rule models' => [['id' => '1', 'name' => 'Forward Week 40', 'rules' => [['id' => '1']]]],
]);

// -- offering a sync ----------------------------------------------------------

it('offers a sync when the group is missing or different and Tamar can publish', function (?array $group) {
    ($this->tamarActive)();
    add_filter('tamar/publish_huntgroup', fn () => null, 10, 3);
    ($this->week)();
    ($this->tamarHolds)($group);

    expect($this->controller->check()->get_data()['can_sync'])->toBeTrue();
})->with([
    'missing'   => [null],
    'different' => [['id' => '1', 'name' => 'Forward Week 40', 'rules' => []]],
]);

it('offers no sync when Tamar cannot publish', function () {
    ($this->tamarActive)();
    ($this->week)();
    ($this->tamarHolds)(null);

    expect($this->controller->check()->get_data()['can_sync'])->toBeFalse();
});

it('offers no sync for a week with no shifts', function () {
    ($this->tamarActive)();
    add_filter('tamar/publish_huntgroup', fn () => null, 10, 3);
    $this->rota->shouldReceive('findForWeek')->once()->with(CHECK_MONDAY)->andReturn([]);
    ($this->tamarHolds)(null);

    expect($this->controller->check()->get_data()['can_sync'])->toBeFalse();
});

// -- syncing ------------------------------------------------------------------

describe('syncing to Tamar', function () {
    beforeEach(function () {
        ($this->tamarActive)();
        add_filter('tamar/publish_huntgroup', fn () => null, 10, 3);
    });

    it('writes the current week under its name, then reads it back', function () {
        ($this->week)();

        $sent = null;
        Filters\expectApplied('tamar/publish_huntgroup')->once()
            ->andReturnUsing(function ($published, string $name, array $rules) use (&$sent) {
                $sent = [$name, $rules];
                return ['id' => '180000', 'name' => $name];
            });
        ($this->tamarHolds)(['id' => '180000', 'name' => 'Forward Week 40', 'rules' => [
            heldRule(1, 'num:07700900123', ['mon'], '10:00', '14:00', 'Anon A'),
            heldRule(2, 'vm:20042', ['mon'], '14:00', '18:00'),
        ]]);

        $data = $this->controller->sync()->get_data();

        expect($sent[0])->toBe('Forward Week 40')
            ->and($sent[1])->toHaveCount(2)
            ->and($sent[1][0]->getTargetId())->toBe('num:07700900123')
            ->and($data)->toMatchArray([
                'status'   => 'match',
                'synced'   => true,
                'can_sync' => false,
                'message'  => 'Synced this week\'s rota to Tamar\'s "Forward Week 40" (2 forwarding steps). It now matches, and Tamar\'s Overview shows it.',
            ]);
    });

    it('does not report success when the group still differs afterwards', function () {
        ($this->week)();
        Filters\expectApplied('tamar/publish_huntgroup')->once()->andReturn(['id' => '180000', 'name' => 'Forward Week 40']);
        ($this->tamarHolds)(['id' => '180000', 'name' => 'Forward Week 40', 'rules' => []]);

        $data = $this->controller->sync()->get_data();

        expect($data['status'])->toBe('mismatch')
            ->and($data['message'])->toStartWith('Tamar accepted the sync of "Forward Week 40", but reading it back still shows a difference.')
            ->and($data['differences'])->toHaveCount(2);
    });

    it('passes on the reason Tamar gives for refusing the write', function () {
        ($this->week)();
        Filters\expectApplied('tamar/publish_huntgroup')->once()
            ->andReturnUsing(fn () => throw new \RuntimeException('You do not have permission to publish forwarding to Tamar.'));
        Filters\expectApplied('tamar/find_huntgroup')->never();

        $error = $this->controller->sync();

        expect($error)->toBeInstanceOf(WP_Error::class)
            ->and($error->get_error_message())->toBe('Could not sync "Forward Week 40" to Tamar: You do not have permission to publish forwarding to Tamar.')
            ->and($error->get_error_data())->toBe(['status' => 502]);
    });

    it('does not take an unconfirmed write for a sync', function () {
        ($this->week)();
        Filters\expectApplied('tamar/publish_huntgroup')->once()->andReturn(null);

        expect($this->controller->sync()->get_error_message())->toBe('Tamar did not confirm that "Forward Week 40" was synced.');
    });

    it('refuses an empty week without troubling Tamar', function () {
        $this->rota->shouldReceive('findForWeek')->once()->andReturn([]);
        Filters\expectApplied('tamar/publish_huntgroup')->never();

        $error = $this->controller->sync();

        expect($error->get_error_code())->toBe('trusted_forwarding_empty')
            ->and($error->get_error_data())->toBe(['status' => 409]);
    });
});

it('refuses to sync when Tamar cannot publish, before reading the rota', function () {
    ($this->tamarActive)();
    $this->rota->shouldNotReceive('findForWeek');

    $error = $this->controller->sync();

    expect($error->get_error_code())->toBe('trusted_forwarding_unavailable')
        ->and($error->get_error_message())->toBe('Tamar is not active, so there is nowhere to sync to.');
});
