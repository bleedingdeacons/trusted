<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Trusted\Admin\CalendarPage;
use Trusted\Tests\TestCase;

/**
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
 *
 * @covers \Trusted\Admin\CalendarPage
 */
final class CalendarPageTest extends TestCase
{
    private CalendarPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->page = new CalendarPage();
    }

    private function render(): string
    {
        ob_start();

        try {
            $this->page->render();
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    // ── menu registration ─────────────────────────────────────────────

    /** @test */
    public function it_registers_a_top_level_menu_and_a_submenu_on_the_same_slug(): void
    {
        $this->page->registerMenu();

        $this->assertCount(2, WpState::$menus);

        [$top, $sub] = WpState::$menus;

        $this->assertSame('menu', $top['type']);
        $this->assertSame(CalendarPage::SLUG, $top['slug']);

        // The submenu reuses the parent slug so "Rota Calendar" replaces the
        // duplicated "Telephone" entry WordPress would otherwise show first.
        $this->assertSame('submenu', $sub['type']);
        $this->assertSame(CalendarPage::SLUG, $sub['parent']);
        $this->assertSame(CalendarPage::SLUG, $sub['slug']);
        $this->assertSame('Rota Calendar', $sub['title']);
    }

    /** @test */
    public function the_menu_defaults_to_manage_options(): void
    {
        $this->page->registerMenu();

        foreach (WpState::$menus as $menu) {
            $this->assertSame('manage_options', $menu['cap']);
        }
    }

    /**
     * The capability is filterable so an intergroup can hand the rota to a
     * custom role without granting full admin.
     *
     * @test
     */
    public function the_capability_is_filterable(): void
    {
        Filters\expectApplied('trusted_capability')
            ->andReturn('edit_trusted_rota');

        $this->page->registerMenu();

        foreach (WpState::$menus as $menu) {
            $this->assertSame('edit_trusted_rota', $menu['cap']);
        }
    }

    // ── the calendar shell ────────────────────────────────────────────

    /**
     * calendar.js mounts into #trusted-calendar and hangs the refresh button
     * off #trusted-title-actions. Renaming either here silently empties the
     * screen at runtime, so both ids are asserted.
     *
     * @test
     */
    public function it_renders_the_mount_points_the_calendar_script_looks_for(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="trusted-calendar"', $html);
        $this->assertStringContainsString('id="trusted-title-actions"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
    }

    /** @test */
    public function it_renders_a_heading_and_a_loading_placeholder(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<h1>Telephone Rota</h1>', $html);
        $this->assertStringContainsString('Loading rota…', $html);
    }

    /** @test */
    public function the_wrapper_divs_are_balanced(): void
    {
        $html = $this->render();

        $this->assertSame(
            substr_count($html, '<div'),
            substr_count($html, '</div>'),
            'unbalanced divs would break the admin layout below the page'
        );
    }

    /**
     * ACF backs the weekly shift templates only; slot assignment works without
     * it, so its absence is a warning rather than a hard requirement.
     *
     * The complementary case — the warning appearing when ACF is missing —
     * cannot be reached in this suite: tests/wp-stubs.php defines
     * acf_add_local_field_group() for TemplateFields' own tests, and PHP has
     * no way to undefine a function once declared, so function_exists() is
     * permanently true in-process.
     *
     * @test
     */
    public function no_acf_warning_is_shown_when_acf_is_available(): void
    {
        $this->assertTrue(
            function_exists('acf_add_local_field_group'),
            'the stub layer should have declared ACF present'
        );

        $this->assertStringNotContainsString('notice-warning', $this->render());
    }
}
