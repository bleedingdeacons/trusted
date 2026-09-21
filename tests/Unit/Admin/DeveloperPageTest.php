<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Mockery;
use ReflectionMethod;
use Trusted\Admin\CalendarPage;
use Trusted\Admin\DeveloperPage;
use Trusted\Contracts\RotaRepositoryInterface;
use Unity\Core\Interfaces\Container;

/*
 * Tests for the Developer maintenance page.
 *
 * The page carries two destructive tools — delete a week's shifts, and empty
 * the rota entirely — so the guarding is the point. Three kinds of method,
 * three techniques:
 *
 *   - Registration (registerMenu) runs for real and is asserted against
 *     WpState, which records every submenu page.
 *   - The capability and `trusted_developer_tools` guards call wp_die(), which
 *     the shared stubs turn into a WpDieException, so each refusal is a plain
 *     ->throws().
 *   - render() and its notices are called inside an output buffer and the
 *     markup asserted on.
 *
 * The exit wall: both handle* methods end in wp_safe_redirect() followed by a
 * bare exit. wp_safe_redirect is recorded rather than thrown, so the exit runs
 * and would take PHPUnit with it — every path through those methods reaches
 * it, not just the happy one. Their guards are covered here directly; the work
 * behind them was split into deleteWeekFromRequest()/clearAllFromRequest() and
 * is driven through reflection, the same approach Amber and Integrity document
 * for their own redirect-and-exit handlers.
 */

covers(DeveloperPage::class);

/**
 * @param array<int, mixed> $args
 */
function callDeveloperPage(DeveloperPage $page, string $method, array $args = []): mixed
{
    return (new ReflectionMethod(DeveloperPage::class, $method))->invokeArgs($page, $args);
}

beforeEach(function () {
    $this->rota      = Mockery::mock(RotaRepositoryInterface::class);
    $this->container = Mockery::mock(Container::class);
    $this->page      = new DeveloperPage($this->container);

    $this->render = fn (): string => captureOutput(fn () => $this->page->render());

    // The container hands out the rota repository exactly once.
    $this->expectRepository = fn () => $this->container->shouldReceive('get')
        ->once()
        ->with(RotaRepositoryInterface::class)
        ->andReturn($this->rota);

    $_GET  = [];
    $_POST = [];
});

afterEach(function () {
    $_GET  = [];
    $_POST = [];
});

// ── menu registration ─────────────────────────────────────────────
describe('registerMenu', function () {
    it('registers a Developer submenu under the Trusted menu', function () {
        $this->page->registerMenu();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])
            ->type->toBe('submenu')
            ->parent->toBe(CalendarPage::SLUG)
            ->slug->toBe(DeveloperPage::SLUG)
            ->title->toBe('Developer')
            ->cap->toBe('manage_options');
    });

    // `trusted_developer_tools` is the production off-switch: returning false
    // hides the page entirely rather than merely tightening its capability.
    it('registers nothing when developer tools are switched off', function () {
        Filters\expectApplied('trusted_developer_tools')->andReturn(false);

        $this->page->registerMenu();

        expect(WpState::$menus)->toBe([]);
    });

    it('uses the filtered capability', function () {
        Filters\expectApplied('trusted_capability')->andReturn('edit_trusted_rota');

        $this->page->registerMenu();

        expect(WpState::$menus[0]['cap'])->toBe('edit_trusted_rota');
    });
});

// ── guards ────────────────────────────────────────────────────────
// Nothing trusts the menu to have hidden itself: the page and both actions
// re-check, since admin-post.php is reachable by URL whether or not a menu
// entry was ever drawn.
describe('guards', function () {
    dataset('guarded methods', [
        'the page'       => ['render'],
        'delete a week'  => ['handleDeleteWeek'],
        'clear the rota' => ['handleClearAll'],
    ]);

    it('refuses a user without the capability at every entry point', function (string $method) {
        WpState::$userCan = false;

        $this->page->{$method}();
    })->with('guarded methods')->throws(WpDieException::class);

    it('refuses at every entry point when developer tools are switched off', function (string $method) {
        Filters\expectApplied('trusted_developer_tools')->andReturn(false);

        $this->page->{$method}();
    })->with('guarded methods')->throws(WpDieException::class);

    it('says what was refused', function () {
        WpState::$userCan = false;

        $this->page->render();
    })->throws(WpDieException::class, 'You are not allowed to access this page.');

    it('deletes nothing on a refused action', function () {
        WpState::$userCan = false;
        // The container is never touched, so no `get` expectation is set: an
        // unexpected call to a Mockery mock fails the test.
        $this->container->shouldNotReceive('get');

        $this->page->handleDeleteWeek();
    })->throws(WpDieException::class);
});

// ── the page ──────────────────────────────────────────────────────
describe('the page', function () {
    it('offers both destructive tools', function () {
        expect(($this->render)())
            ->toContain('Delete all shifts for a week', 'Clear everything', 'Trusted — Developer Tools');
    });

    // Both forms post to admin-post.php with the action name their handler is
    // hooked on, and both carry a nonce — the pairing check_admin_referer()
    // depends on.
    it('posts both forms to a nonce-protected admin-post action', function () {
        $html = ($this->render)();

        expect(substr_count($html, 'admin-post.php'))->toBe(2);

        foreach (['trusted_delete_week', 'trusted_clear_all'] as $action) {
            expect($html)->toContain(
                'name="action" value="' . $action . '"',
                'value="nonce-' . $action . '"',
            );
        }
    });

    it('asks for confirmation before submitting either form', function () {
        $html = ($this->render)();

        expect(substr_count($html, 'onsubmit="return confirm('))->toBe(2)
            // The clear-everything form additionally requires the word typed out.
            ->and($html)->toContain('Type DELETE to confirm', 'name="confirm"');
    });

    // The week field is prefilled with the Monday of the current week, so the
    // common case is one click rather than a date-picker hunt.
    it('defaults the week field to the Monday of the current week', function () {
        $html = ($this->render)();
        $pattern = '/name="week" required value="(\d{4}-\d{2}-\d{2})"/';

        expect($html)->toMatch($pattern);

        preg_match($pattern, $html, $m);

        expect((new \DateTimeImmutable($m[1]))->format('l'))->toBe('Monday')
            ->and($m[1])->toBe(callDeveloperPage($this->page, 'mondayOf', [gmdate('Y-m-d')]));
    });
});

// ── notices ───────────────────────────────────────────────────────
describe('notices', function () {
    it('shows no notice on a plain page load', function () {
        expect(($this->render)())->not->toContain('notice-');
    });

    it('shows no notice for an unrecognised status', function () {
        $_GET = ['trusted_status' => 'something_else'];

        expect(($this->render)())->not->toContain('notice-');
    });

    it('reports the count and the week after a delete', function () {
        $_GET = ['trusted_status' => 'deleted', 'trusted_deleted' => '7', 'trusted_week' => '2026-08-03'];

        expect(($this->render)())
            ->toContain('notice-success', 'Deleted 7 shifts for the week of 2026-08-03.');
    });

    // The counts are pluralised through _n(), so one deleted slot must not
    // read "1 shifts".
    it('is singular for one deleted shift', function () {
        $_GET = ['trusted_status' => 'deleted', 'trusted_deleted' => '1', 'trusted_week' => '2026-08-03'];

        expect(($this->render)())->toContain('Deleted 1 shift for the week');
    });

    it('copes with missing query args after a delete', function () {
        $_GET = ['trusted_status' => 'deleted'];

        expect(($this->render)())->toContain('Deleted 0 shifts for the week of .');
    });

    it('reports the total after clearing', function () {
        $_GET = ['trusted_status' => 'cleared', 'trusted_deleted' => '42'];

        expect(($this->render)())
            ->toContain('notice-success', 'Cleared the entire rota: 42 shifts');
    });

    it('is singular for one cleared shift', function () {
        $_GET = ['trusted_status' => 'cleared', 'trusted_deleted' => '1'];

        expect(($this->render)())->toContain('Cleared the entire rota: 1 shift and');
    });

    it('warns rather than succeeds when not confirmed', function () {
        $_GET = ['trusted_status' => 'not_confirmed'];

        expect(($this->render)())
            ->toContain('notice-warning', 'You must type DELETE to confirm.')
            ->not->toContain('notice-success');
    });

    it('shows an error for an invalid date', function () {
        $_GET = ['trusted_status' => 'invalid'];

        expect(($this->render)())
            ->toContain('notice-error', 'That was not a valid date.');
    });
});

// ── delete-week (reflection: the live method exits) ────────────────
describe('deleteWeekFromRequest', function () {
    it('normalises the posted date and reports the count', function () {
        // A Thursday: the whole Monday–Sunday week containing it is cleared.
        $_POST = ['week' => '2026-08-06'];
        ($this->expectRepository)();
        $this->rota->shouldReceive('deleteWeek')->once()->with('2026-08-03')->andReturn(9);

        expect(callDeveloperPage($this->page, 'deleteWeekFromRequest'))->toBe(['deleted', 9, '2026-08-03']);
    });

    // `week` comes straight off a <input type="date">, which a hand-built
    // POST can trivially bypass. Anything that is not a real calendar date is
    // rejected before the repository is reached.
    it('deletes nothing for a week that is not a real date', function (mixed $posted) {
        $_POST = $posted === null ? [] : ['week' => $posted];
        $this->container->shouldNotReceive('get');

        expect(callDeveloperPage($this->page, 'deleteWeekFromRequest'))->toBe(['invalid', 0, '']);
    })->with([
        'absent'               => [null],
        'empty'                => [''],
        'not a date at all'    => ['nonsense'],
        'wrong separator'      => ['2026/08/06'],
        'unpadded'             => ['2026-8-6'],
        'month 13'             => ['2026-13-01'],
        'the 31st of February' => ['2026-02-31'],
        'a date with a time'   => ['2026-08-06T09:00'],
        'trailing rubbish'     => ['2026-08-06; DROP TABLE'],
    ]);

    // The Monday-of-week walk-back is the only arithmetic on the page, and
    // the ends of the week are where it goes wrong.
    it('normalises any day to the Monday of its week', function (string $date, string $monday) {
        expect(callDeveloperPage($this->page, 'mondayOf', [$date]))->toBe($monday);
    })->with([
        'Monday is itself'           => ['2026-08-03', '2026-08-03'],
        'Thursday walks back'        => ['2026-08-06', '2026-08-03'],
        'Sunday closes its own week' => ['2026-08-09', '2026-08-03'],
        'across a month boundary'    => ['2026-09-02', '2026-08-31'],
        'across a year boundary'     => ['2027-01-01', '2026-12-28'],
        'a leap day'                 => ['2028-02-29', '2028-02-28'],
    ]);
});

// ── clear-everything (reflection: the live method exits) ───────────
describe('clearAllFromRequest', function () {
    it('clears everything when the word DELETE is typed', function () {
        $_POST = ['confirm' => 'DELETE'];
        ($this->expectRepository)();
        $this->rota->shouldReceive('deleteAll')->once()->andReturn(140);

        expect(callDeveloperPage($this->page, 'clearAllFromRequest'))->toBe(['cleared', 140, '']);
    });

    // The field is uppercased in CSS only, so the typed value arrives in
    // whatever case it was entered; sanitize_text_field() trims it.
    it('trims the typed confirmation and ignores its case', function (string $posted) {
        $_POST = ['confirm' => $posted];
        ($this->expectRepository)();
        $this->rota->shouldReceive('deleteAll')->once()->andReturn(0);

        expect(callDeveloperPage($this->page, 'clearAllFromRequest'))->toBe(['cleared', 0, '']);
    })->with([
        'lower case' => ['delete'],
        'mixed case' => ['Delete'],
        'padded'     => ['  DELETE  '],
    ]);

    it('clears nothing for anything other than DELETE', function (mixed $posted) {
        $_POST = $posted === null ? [] : ['confirm' => $posted];
        $this->container->shouldNotReceive('get');

        expect(callDeveloperPage($this->page, 'clearAllFromRequest'))->toBe(['not_confirmed', 0, '']);
    })->with([
        'absent'           => [null],
        'empty'            => [''],
        'a near miss'      => ['DELET'],
        'the wrong word'   => ['YES'],
        'a different verb' => ['REMOVE'],
    ]);
});

// ── the redirect target ───────────────────────────────────────────
describe('redirectUrl', function () {
    // The status the handlers redirect with is the status maybeRenderNotice()
    // reads back, so the query-arg names are a contract between the two
    // halves of the round trip.
    it('carries the args the notice reads back', function () {
        $url = (string) callDeveloperPage($this->page, 'redirectUrl', ['deleted', 9, '2026-08-03']);

        expect($url)->toContain(
            'page=' . DeveloperPage::SLUG,
            'trusted_status=deleted',
            'trusted_deleted=9',
            'admin.php',
        );

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        expect(rawurldecode((string) $query['trusted_week']))->toBe('2026-08-03');
    });

    it('stays inside wp-admin', function () {
        expect((string) callDeveloperPage($this->page, 'redirectUrl', ['cleared', 0, '']))
            ->toStartWith('https://example.test/wp-admin/admin.php');
    });
});
