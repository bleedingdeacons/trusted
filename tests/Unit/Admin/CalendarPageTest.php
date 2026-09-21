<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Trusted\Admin\CalendarPage;

/*
 * Tests for the top-level Trusted menu and the calendar shell.
 *
 * src/Admin was excluded from the coverage source set until now, on the
 * grounds that admin screens are "render/menu/enqueue glue exercised through
 * the admin UI at runtime". Amber covers its whole src/Admin on the same
 * tooling and Integrity followed, so the exclusion was habit rather than
 * necessity.
 *
 * Registration runs for real and is asserted against WpState, which records
 * every add_menu_page()/add_submenu_page() call. render() is called inside an
 * output buffer and the markup asserted on — the mount point calendar.js
 * looks for is a contract with the JavaScript, not decoration.
 */

covers(CalendarPage::class);

beforeEach(function () {
    $this->page = new CalendarPage();
});

// ── menu registration ─────────────────────────────────────────────
describe('registerMenu', function () {
    it('registers a top-level menu and a submenu on the same slug', function () {
        $this->page->registerMenu();

        expect(WpState::$menus)->toHaveCount(2);

        [$top, $sub] = WpState::$menus;

        expect($top['type'])->toBe('menu')
            ->and($top['slug'])->toBe(CalendarPage::SLUG);

        // The submenu reuses the parent slug so "Rota Calendar" replaces the
        // duplicated "Telephone" entry WordPress would otherwise show first.
        expect($sub['type'])->toBe('submenu')
            ->and($sub['parent'])->toBe(CalendarPage::SLUG)
            ->and($sub['slug'])->toBe(CalendarPage::SLUG)
            ->and($sub['title'])->toBe('Rota Calendar');
    });

    it('defaults to manage_options', function () {
        $this->page->registerMenu();

        expect(WpState::$menus)->each(fn ($menu) => $menu->cap->toBe('manage_options'));
    });

    // The capability is filterable so an intergroup can hand the rota to a
    // custom role without granting full admin.
    it('uses the filtered capability', function () {
        Filters\expectApplied('trusted_capability')
            ->andReturn('edit_trusted_rota');

        $this->page->registerMenu();

        expect(WpState::$menus)->each(fn ($menu) => $menu->cap->toBe('edit_trusted_rota'));
    });
});

// ── the calendar shell ────────────────────────────────────────────
describe('render', function () {
    // calendar.js mounts into #trusted-calendar and hangs the refresh button
    // off #trusted-title-actions. Renaming either here silently empties the
    // screen at runtime, so both ids are asserted.
    it('renders the mount points the calendar script looks for', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('id="trusted-calendar"', 'id="trusted-title-actions"', 'aria-live="polite"');
    });

    it('renders a heading and a loading placeholder', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('<h1>Telephone Rota</h1>', 'Loading rota…');
    });

    it('balances its wrapper divs', function () {
        $html = captureOutput(fn () => $this->page->render());

        expect(substr_count($html, '</div>'))->toBe(
            substr_count($html, '<div'),
            'unbalanced divs would break the admin layout below the page'
        );
    });

    // ACF backs the weekly shift templates only; slot assignment works
    // without it, so its absence is a warning rather than a hard requirement.
    //
    // The complementary case — the warning appearing when ACF is missing —
    // cannot be reached in this suite: tests/wp-stubs.php defines
    // acf_add_local_field_group() for TemplateFields' own tests, and PHP has
    // no way to undefine a function once declared, so function_exists() is
    // permanently true in-process.
    it('shows no ACF warning when ACF is available', function () {
        expect(function_exists('acf_add_local_field_group'))
            ->toBeTrue('the stub layer should have declared ACF present')
            ->and(captureOutput(fn () => $this->page->render()))->not->toContain('notice-warning');
    });
});
