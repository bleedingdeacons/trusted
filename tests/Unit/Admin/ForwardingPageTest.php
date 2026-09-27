<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Mockery;
use Trusted\Admin\CalendarPage;
use Trusted\Admin\ForwardingPage;
use Trusted\Admin\ForwardingPreview;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Assignment;
use Trusted\Domain\Member;
use Trusted\Domain\Rota;
use Trusted\Factory\RotaFactory;

/*
 * Tests for the Forwarding preview page and the call-flow view it draws.
 *
 * Registration is asserted against WpState, the capability guards against the
 * WpDieException the shared wp_die() throws, and render() runs for real inside
 * an output buffer against a mocked rota repository. The Publish handler ends
 * in redirect-and-exit, so its body, publishFromRequest(), is driven directly.
 *
 * WpState::$now is Friday 2026-07-24, so the current week is Monday 20 July.
 */

covers(ForwardingPage::class, ForwardingPreview::class);

function forwardingSlot(string $date, string $start, string $end, ?string $name = null, string $telephone = '07700 900123', int $id = 1): Rota
{
    $rota = (new RotaFactory())->create($date, $start, $end, 'Shift')->withId($id);

    if ($name === null) {
        return $rota;
    }

    return $rota->withAssignments([
        (new Assignment(1, $id, '7'))->withMember(new Member('7', $name, 'x@example.org', $telephone)),
    ]);
}

beforeEach(function () {
    $this->rota      = Mockery::mock(RotaRepositoryInterface::class);
    $this->container = Mockery::mock(\Unity\Core\Interfaces\Container::class);
    $this->container->shouldReceive('get')->with(RotaRepositoryInterface::class)->andReturn($this->rota);
    $this->page = new ForwardingPage($this->container);

    // The week the repository is asked for, and the slots it hands back.
    $this->weekOf = function (string $monday, array $slots): void {
        $this->rota->shouldReceive('findForWeek')->once()->with($monday)->andReturn($slots);
    };

    $this->render = fn (): string => captureOutput(fn () => $this->page->render());

    $_GET = [];
});

afterEach(function () {
    $_GET = [];
});

describe('registerMenu', function () {
    it('registers a Forwarding submenu under the Trusted menu', function () {
        $this->page->registerMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])
            ->type->toBe('submenu')
            ->parent->toBe(CalendarPage::SLUG)
            ->slug->toBe(ForwardingPage::SLUG)
            ->title->toBe('Forwarding')
            ->cap->toBe('manage_options');
    });

    it('uses the filtered capability', function () {
        Filters\expectApplied('trusted_capability')->andReturn('edit_trusted_rota');

        $this->page->registerMenu();

        expect(WpState::$menus[0]['cap'])->toBe('edit_trusted_rota');
    });

    // The week toolbar is styled by the calendar's stylesheet, loaded only
    // when this screen is, on the hook WordPress returned for it.
    it('loads the calendar stylesheet on its own screen only', function () {
        $this->page->registerMenu();

        expect(has_action('load-' . CalendarPage::SLUG . '_page_' . ForwardingPage::SLUG, [$this->page, 'enqueueStyles']))
            ->not->toBeFalse();
    });

    it('enqueues the same stylesheet handle as the calendar', function () {
        $this->page->enqueueStyles();

        expect(WpState::$enqueued)->toBe([['fn' => 'wp_enqueue_style', 'handle' => 'trusted-calendar']]);
    });
});

describe('guard', function () {
    it('refuses a user without the capability, before reading the rota', function () {
        WpState::$userCan = false;
        $this->rota->shouldNotReceive('findForWeek');

        $this->page->render();
    })->throws(WpDieException::class, 'You are not allowed to access this page.');
});

describe('choosing the week', function () {
    it('shows the current week by default', function () {
        ($this->weekOf)('2026-07-20', []);

        expect(($this->render)())->toContain('<strong class="trusted-week-label">2026-07-20 – 2026-07-26 · Week 30</strong>');
    });

    it('shows the week containing any date asked for', function () {
        $_GET = ['week' => '2026-09-24'];
        ($this->weekOf)('2026-09-21', []);

        expect(($this->render)())->toContain('2026-09-21 – 2026-09-27');
    });

    // ISO-8601 numbering: 2026 has 53 weeks, and the week holding 1 January
    // 2027 is still 2026's last because its Thursday falls in 2026.
    it('ends the label with the ISO week number', function (string $monday, string $expected) {
        $_GET = ['week' => $monday];
        ($this->weekOf)($monday, []);

        expect(($this->render)())->toContain($expected . '</strong>');
    })->with([
        'first week of 2026'  => ['2025-12-29', '2025-12-29 – 2026-01-04 · Week 1'],
        'week 53 of 2026'     => ['2026-12-28', '2026-12-28 – 2027-01-03 · Week 53'],
        'first week of 2027'  => ['2027-01-04', '2027-01-04 – 2027-01-10 · Week 1'],
    ]);

    it('falls back to the current week for something that is not a date', function (string $week) {
        $_GET = ['week' => $week];
        ($this->weekOf)('2026-07-20', []);

        ($this->render)();
    })->with(['nonsense' => ['next tuesday'], 'impossible' => ['2026-02-30']]);

    // The same toolbar, classes and wording as the Rota Calendar.
    it('offers the calendar Previous / This week / Next navigation', function () {
        $_GET = ['week' => '2026-09-24'];
        ($this->weekOf)('2026-09-21', []);

        $html = ($this->render)();

        expect($html)->toContain(
            '<div class="trusted-toolbar"><div class="trusted-nav">',
            '<div class="trusted-week-buttons">',
            'week=2026-09-14">← Previous</a>',
            'page=trusted-forwarding">This week</a>',
            'week=2026-09-28">Next →</a>',
        )->and($html)->not->toContain('type="date"');
    });
});

describe('the call flow', function () {
    it('draws every day of the week, even with nothing on the rota', function () {
        ($this->weekOf)('2026-07-20', []);

        $html = ($this->render)();

        expect($html)->toContain('No shifts on the rota this week', 'Trusted — Forwarding preview', 'nothing here is sent to Tamar', 'split at 23:59')
            ->and($html)->not->toContain('Monday 20 July');
    });

    it('draws an assigned shift as an active step forwarding to the responder', function () {
        ($this->weekOf)('2026-07-20', [forwardingSlot('2026-07-22', '10:00', '24:00', 'Steve C')]);

        $html = ($this->render)();

        expect($html)->toContain(
            'Wednesday 22 July',
            '10:00–23:59',
            '<strong>Steve C</strong>',
            'Active',
            '07700 900123',
            '1 forwarding step',
            'Forwarded to',
        )->and(substr_count($html, 'Nothing forwarded on this day.'))->toBe(6);
    });

    it('draws an unfilled shift as a voicemail step, with the reason, in its place', function () {
        ($this->weekOf)('2026-07-20', [
            forwardingSlot('2026-07-20', '14:00', '18:00', 'Late', id: 2),
            forwardingSlot('2026-07-20', '09:00', '12:00', null, id: 1),
            forwardingSlot('2026-07-20', '12:00', '14:00', 'No Phone', telephone: '', id: 3),
        ]);

        $html = ($this->render)();

        expect($html)->toContain(
            'Monday 20 July <span>(3)</span>',
            '3 forwarding steps',
            '2 unfilled, to voicemail',
            'Unfilled',
            'dashicons-microphone',
            'Nobody is assigned to this shift.',
            'No Phone is assigned but has no telephone number.',
            'Responders <span>(1)</span>',
            'Voicemail <span>(1)</span>',
        );

        // In time order within the day, whichever kind of step each is.
        expect(strpos($html, '09:00–12:00'))->toBeLessThan(strpos($html, '12:00–14:00'))
            ->and(strpos($html, '12:00–14:00'))->toBeLessThan(strpos($html, '14:00–18:00'));
    });

    it('draws an overnight shift across both days, to the same person', function () {
        ($this->weekOf)('2026-07-20', [forwardingSlot('2026-07-26', '22:00', '06:00', 'Night Owl')]);

        $html = ($this->render)();

        expect($html)->toContain('22:00–23:59', '00:00–06:00', '2 forwarding steps')
            ->and(substr_count($html, '<li class="trusted-step">'))->toBe(2)
            ->and($html)->not->toContain('Unfilled')
            ->and(strpos($html, '00:00–06:00'))->toBeLessThan(strpos($html, 'Sunday 26 July'));
    });
});

// Tamar registers tamar/publish_huntgroup when it is active; Brain Monkey
// records the add_filter() for has_filter(), and expectApplied() stands in
// for Tamar's callback, which apply_filters() does not run under it.
describe('publishing to Tamar', function () {
    beforeEach(function () {
        $this->tamarActive = function (): void {
            add_filter('tamar/publish_huntgroup', fn () => null, 10, 3);
        };

        $this->publish = fn (): array
            => (new \ReflectionMethod($this->page, 'publishFromRequest'))->invoke($this->page);

        $_POST = [];
    });

    afterEach(function () {
        $_POST = [];
    });

    it('names the hunt group after the ISO week number', function (string $monday, string $name) {
        expect(ForwardingPage::huntgroupName($monday))->toBe($name);
    })->with([
        ['2026-07-20', 'Forward Week 30'],
        ['2026-02-02', 'Forward Week 6'],
        ['2026-12-28', 'Forward Week 53'],
        ['2027-01-04', 'Forward Week 1'],
    ]);

    it('offers no Publish button without Tamar, and says nothing is sent', function () {
        ($this->weekOf)('2026-07-20', [forwardingSlot('2026-07-20', '10:00', '14:00', 'Steve C')]);

        $html = ($this->render)();

        expect($html)->toContain('This is a preview only: nothing here is sent to Tamar.')
            ->and($html)->not->toContain('trusted_publish_forwarding');
    });

    it('offers to publish the week shown as Forward Week N when Tamar is active', function () {
        ($this->tamarActive)();
        $_GET = ['week' => '2026-09-24'];
        ($this->weekOf)('2026-09-21', [forwardingSlot('2026-09-21', '10:00', '14:00', 'Steve C')]);

        $html = ($this->render)();

        expect($html)->toContain(
            'Nothing is sent to Tamar until you press Publish.',
            'name="action" value="trusted_publish_forwarding"',
            'name="week" value="2026-09-21"',
            'Publish to Tamar as &quot;Forward Week 39&quot;',
            'window.confirm(',
        )->and($html)->not->toContain('This is a preview only');
    });

    it('offers nothing to publish for a week with no shifts', function () {
        ($this->tamarActive)();
        ($this->weekOf)('2026-07-20', []);

        expect(($this->render)())->not->toContain('trusted_publish_forwarding');
    });

    it('hands the week\'s rules to Tamar under the week\'s name', function () {
        ($this->tamarActive)();
        $_POST = ['week' => '2026-07-22'];
        ($this->weekOf)('2026-07-20', [
            forwardingSlot('2026-07-20', '10:00', '14:00', 'Steve C', id: 1),
            forwardingSlot('2026-07-21', '10:00', '14:00', id: 2),
        ]);

        $sent = null;
        Filters\expectApplied('tamar/publish_huntgroup')->once()
            ->andReturnUsing(function ($published, string $name, array $rules) use (&$sent) {
                $sent = [$name, $rules];
                return ['id' => '200001', 'name' => $name];
            });

        $notice = ($this->publish)();

        expect($notice)->toBe([
            'type' => 'success',
            'message' => 'Published "Forward Week 30" to Tamar with 2 forwarding steps. Tamar\'s Overview now shows it.',
            'week' => '2026-07-20',
        ])->and($sent[0])->toBe('Forward Week 30')
            ->and($sent[1])->toHaveCount(2)
            ->and($sent[1][0]->getTargetId())->toBe('num:07700900123')
            ->and($sent[1][1]->getTargetId())->toBe('vm:default');
    });

    it('reports the reason Tamar gives for a failure', function () {
        ($this->tamarActive)();
        $_POST = ['week' => '2026-07-20'];
        ($this->weekOf)('2026-07-20', [forwardingSlot('2026-07-20', '10:00', '14:00', 'Steve C')]);
        Filters\expectApplied('tamar/publish_huntgroup')->once()
            ->andReturnUsing(fn () => throw new \RuntimeException('Upstream returned status 500.'));

        expect(($this->publish)())->toBe([
            'type' => 'error',
            'message' => 'Could not publish "Forward Week 30" to Tamar: Upstream returned status 500.',
            'week' => '2026-07-20',
        ]);
    });

    it('does not take an unconfirmed publish for a success', function () {
        ($this->tamarActive)();
        $_POST = ['week' => '2026-07-20'];
        ($this->weekOf)('2026-07-20', [forwardingSlot('2026-07-20', '10:00', '14:00', 'Steve C')]);
        Filters\expectApplied('tamar/publish_huntgroup')->once()->andReturn(null);

        expect(($this->publish)())->type->toBe('error')
            ->message->toBe('Tamar did not confirm that "Forward Week 30" was published.');
    });

    it('refuses before reading the rota', function (array $post, bool $tamar, string $message) {
        if ($tamar) {
            ($this->tamarActive)();
        }
        $_POST = $post;
        $this->rota->shouldNotReceive('findForWeek');

        expect(($this->publish)())->type->toBe('error')->message->toBe($message);
    })->with([
        'not a date' => [['week' => 'nonsense'], true, 'That is not a week that can be published.'],
        'no week' => [[], true, 'That is not a week that can be published.'],
        'no Tamar' => [['week' => '2026-07-20'], false, 'Tamar is not active, so there is nowhere to publish to.'],
    ]);

    it('refuses an empty week without troubling Tamar', function () {
        ($this->tamarActive)();
        $_POST = ['week' => '2026-07-20'];
        ($this->weekOf)('2026-07-20', []);
        Filters\expectApplied('tamar/publish_huntgroup')->never();

        expect(($this->publish)())->message->toBe('This week has no shifts, so there is nothing to publish.');
    });

    it('refuses a user without the capability', function () {
        WpState::$userCan = false;
        $this->rota->shouldNotReceive('findForWeek');

        $this->page->handlePublish();
    })->throws(WpDieException::class, 'You are not allowed to do this.');

    it('shows the outcome once, on the next view of the page', function () {
        set_transient('trusted_publish_notice_' . get_current_user_id(), ['type' => 'success', 'message' => 'Published it.', 'week' => '2026-07-20']);
        $this->rota->shouldReceive('findForWeek')->twice()->andReturn([]);

        expect(($this->render)())->toContain('<div class="notice notice-success is-dismissible"><p>Published it.</p></div>')
            ->and(($this->render)())->not->toContain('Published it.');
    });
});
