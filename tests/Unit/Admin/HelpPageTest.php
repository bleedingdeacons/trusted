<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Trusted\Admin\CalendarPage;
use Trusted\Admin\HelpPage;

/*
 * Tests for the Help submenu and the footer script that hijacks its click.
 *
 * register() runs for real against WpState's menu recorder and Brain Monkey's
 * hook store. Both render paths emit markup and are captured in an output
 * buffer: render() is the no-JavaScript fallback, and enqueueHelpTabScript()
 * prints an inline <script> whose selectors and window names are the contract
 * that lets the guide's back button refocus the admin tab instead of
 * reloading it.
 */

covers(HelpPage::class);

beforeEach(function () {
    $this->page = new HelpPage();
});

// ── registration ──────────────────────────────────────────────────
describe('register', function () {
    it('registers a Help submenu under the Trusted menu', function () {
        $this->page->register();

        expect(WpState::$menus)->toHaveCount(1)
            ->and(WpState::$menus[0])
            ->type->toBe('submenu')
            ->parent->toBe(CalendarPage::SLUG)
            ->slug->toBe(HelpPage::SLUG)
            ->title->toBe('Help')
            ->cap->toBe('manage_options');
    });

    it('uses the filtered capability', function () {
        Filters\expectApplied('trusted_capability')->andReturn('edit_trusted_rota');

        $this->page->register();

        expect(WpState::$menus[0]['cap'])->toBe('edit_trusted_rota');
    });

    // The click interceptor has to be printed on every admin screen, not just
    // this one — the Help link lives in the sidebar and is clicked from
    // wherever the user happens to be.
    it('also hooks the footer script', function () {
        $this->page->register();

        $this->assertActionAdded(
            'admin_footer',
            [$this->page, 'enqueueHelpTabScript'],
            'the click interceptor must be printed in the admin footer'
        );
    });
});

// ── the no-JavaScript fallback ────────────────────────────────────
describe('render', function () {
    it('links straight to the bundled guide', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('<h1>Trusted Help</h1>', 'assets/docs/trusted.html', 'Open the guide');
    });

    // The fallback opens a new tab, so it needs rel="noopener" — without it
    // the guide gets a handle on wp-admin through window.opener.
    it('opens the guide safely in a new tab', function () {
        expect(captureOutput(fn () => $this->page->render()))
            ->toContain('target="_blank"', 'rel="noopener"');
    });
});

// ── the click interceptor ─────────────────────────────────────────
describe('enqueueHelpTabScript', function () {
    beforeEach(function () {
        $this->script = captureOutput(fn () => $this->page->enqueueHelpTabScript());
    });

    it('emits an inline script block', function () {
        expect($this->script)->toContain('<script>', '</script>');
    });

    // The script finds the Help link by its exact admin URL and falls back to
    // a slug match if WordPress rendered the href differently — both
    // selectors are load-bearing.
    it('matches the Help link by URL and by slug', function () {
        expect($this->script)->toContain(
            'a[href="https://example.test/wp-admin/admin.php?page=' . HelpPage::SLUG . '"]',
            'a[href*="page=' . HelpPage::SLUG . '"]',
        );
    });

    // The two window names are how the guide gets back: the admin tab is
    // named so the guide can refocus it, and the guide tab is named so a
    // second click reuses it rather than piling up tabs.
    it('names both tabs and passes the admin URL back', function () {
        expect($this->script)->toContain(
            "window.name = 'trusted-admin'",
            "window.open('', 'trusted-help')",
            "'?back=' + encodeURIComponent(window.location.href)",
            'assets/docs/trusted.html',
        );
    });

    // window.open() returns null when a popup blocker or an extension refuses
    // the window. preventDefault() has already run by then, so without an
    // explicit fallback the Help link would be inert — and the next line
    // would throw on the null handle rather than failing quietly.
    it('falls back to the current tab when the window is blocked', function () {
        expect($this->script)->toContain('if (!existing) {', 'window.location.href = helpUrl;');
    });

    // preventDefault() is what stops WordPress navigating to the fallback
    // page; without it the named-tab trick never runs.
    it('suppresses the default navigation', function () {
        expect($this->script)->toContain('e.preventDefault()', "addEventListener('click'");
    });
});
