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
 * The page is read-only, so there is no exit wall here: registration is
 * asserted against WpState, the capability guard against the WpDieException
 * the shared wp_die() throws, and render() runs for real inside an output
 * buffer against a mocked rota repository.
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

        expect(($this->render)())->toContain('<strong class="trusted-week-label">2026-07-20 – 2026-07-26</strong>');
    });

    it('shows the week containing any date asked for', function () {
        $_GET = ['week' => '2026-09-24'];
        ($this->weekOf)('2026-09-21', []);

        expect(($this->render)())->toContain('2026-09-21 – 2026-09-27');
    });

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
