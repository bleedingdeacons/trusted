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

it('registers a read-only route under the calendar\'s namespace', function () {
    $registered = [];
    Functions\when('register_rest_route')->alias(
        function (string $ns, string $route, array $args) use (&$registered): bool {
            $registered[] = [$ns, $route, $args['methods']];
            return true;
        }
    );

    $this->controller->registerRoutes();

    expect($registered)->toBe([[RestController::NAMESPACE, '/forwarding-check', 'GET']]);
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
