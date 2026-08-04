<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Trusted\Admin\Assets;
use Trusted\Admin\CalendarPage;
use Trusted\Http\RestController;
use Trusted\Tests\TestCase;

/**
 * Tests for the calendar screen's asset enqueuing.
 *
 * enqueue() is driven for real; the shared stubs record every handle in
 * WpState::$enqueued and the wp_localize_script() payload in
 * WpState::$localized, so the whole method can be asserted without a screen.
 *
 * The payload is the interesting part. calendar.js is handed the REST root,
 * a nonce, the week to open on and the full i18n table — a missing key there
 * shows up in the browser as `undefined` in a button label, which no PHP test
 * would otherwise catch.
 *
 * @covers \Trusted\Admin\Assets
 */
final class AssetsTest extends TestCase
{
    private const HOOK = 'toplevel_page_' . CalendarPage::SLUG;

    private Assets $assets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = new Assets();

        // Not in the shared stub layer. Fixed so "this week" is deterministic;
        // 2026-08-06 is a Thursday.
        $this->freezeNow('2026-08-06 09:30:00');
    }

    private function freezeNow(string $when): void
    {
        Functions\when('current_datetime')->justReturn(new DateTimeImmutable($when));
    }

    /** @return array<string, mixed> */
    private function localizedData(): array
    {
        $this->assertArrayHasKey(
            'TrustedData',
            WpState::$localized,
            'calendar.js reads its configuration from window.TrustedData'
        );

        return WpState::$localized['TrustedData'];
    }

    // ── the screen gate ───────────────────────────────────────────────

    /**
     * @test
     * @dataProvider foreignHooks
     */
    public function nothing_is_enqueued_on_another_admin_screen(string $hook): void
    {
        $this->assets->enqueue($hook);

        $this->assertSame([], WpState::$enqueued);
        $this->assertSame([], WpState::$localized);
    }

    /** @return array<string, array{0: string}> */
    public static function foreignHooks(): array
    {
        return [
            'the dashboard'          => ['index.php'],
            'the posts list'         => ['edit.php'],
            'another plugin'         => ['toplevel_page_amber'],
            // The Developer and Help submenus are Trusted's, but the calendar
            // is not mounted there and its assets are dead weight.
            'the developer submenu'  => ['trusted_page_trusted-developer'],
            'the help submenu'       => ['trusted_page_trusted-help'],
        ];
    }

    /** @test */
    public function the_calendar_stylesheet_and_script_are_enqueued_on_the_calendar_screen(): void
    {
        $this->assets->enqueue(self::HOOK);

        $this->assertSame(
            [
                ['fn' => 'wp_enqueue_style', 'handle' => 'trusted-calendar'],
                ['fn' => 'wp_enqueue_script', 'handle' => 'trusted-calendar'],
            ],
            WpState::$enqueued
        );
    }

    // ── the localised payload ─────────────────────────────────────────

    /** @test */
    public function the_script_is_pointed_at_the_plugins_own_rest_namespace(): void
    {
        $this->assets->enqueue(self::HOOK);

        $data = $this->localizedData();

        $this->assertStringEndsWith(RestController::NAMESPACE, $data['restRoot']);
        $this->assertSame('nonce-wp_rest', $data['nonce'], 'the REST nonce action must be wp_rest');
    }

    /**
     * The calendar renders Monday-first or Sunday-first from the site's own
     * Settings → General value, defaulting to Monday when it is unset.
     *
     * @test
     */
    public function the_first_day_of_the_week_comes_from_the_site_setting(): void
    {
        WpState::$options['start_of_week'] = '0';

        $this->assets->enqueue(self::HOOK);

        $this->assertSame(0, $this->localizedData()['startDow'], 'startDow should be an int');
    }

    /** @test */
    public function the_first_day_of_the_week_defaults_to_monday(): void
    {
        $this->assets->enqueue(self::HOOK);

        $this->assertSame(1, $this->localizedData()['startDow']);
    }

    /**
     * "This week" is anchored to the site's timezone via current_datetime()
     * rather than PHP's default. On a site running ahead of UTC, a plain
     * `new DateTimeImmutable('today')` can still read as yesterday and open
     * the calendar on the previous week.
     *
     * @test
     * @dataProvider weekAnchors
     */
    public function the_calendar_opens_on_the_monday_of_the_current_week(
        string $now,
        string $expectedMonday
    ): void {
        $this->freezeNow($now);

        $this->assets->enqueue(self::HOOK);

        $this->assertSame($expectedMonday, $this->localizedData()['weekStart']);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function weekAnchors(): array
    {
        return [
            'Monday stays put'            => ['2026-08-03 00:00:00', '2026-08-03'],
            'midweek walks back'          => ['2026-08-06 09:30:00', '2026-08-03'],
            'Sunday belongs to its own week' => ['2026-08-09 23:59:59', '2026-08-03'],
            'the next Monday moves on'    => ['2026-08-10 00:00:01', '2026-08-10'],
            'across a month boundary'     => ['2026-09-02 12:00:00', '2026-08-31'],
            'across a year boundary'      => ['2027-01-01 12:00:00', '2026-12-28'],
        ];
    }

    /**
     * Every string calendar.js reads out of TrustedData.i18n. Kept as an
     * explicit list because the failure mode of a dropped key is a button
     * labelled "undefined" in wp-admin, not an error anywhere in PHP.
     *
     * @test
     */
    public function the_full_i18n_table_is_handed_to_the_script(): void
    {
        $this->assets->enqueue(self::HOOK);

        $i18n = $this->localizedData()['i18n'];

        $expected = [
            'assign', 'selectMember', 'addShift', 'applyTemplate', 'selectTemplate',
            'replace', 'prevWeek', 'nextWeek', 'today', 'remove', 'confirmRemove',
            'bulkAssign', 'bulkHint', 'oneSelected', 'manySelected', 'bulkSkipped',
            'noTemplates', 'unassigned', 'gap', 'gapAddHint', 'saveAsTemplate',
            'templateName', 'includeMembers', 'templateNameRequired', 'templateSaved',
            'clearWeek', 'confirmClearWeek', 'clearAssignments', 'confirmClearAssignments',
            'delete', 'addingShift', 'memberOptional', 'newSlotStart', 'newSlotEnd',
            'newSlotLabel', 'nameRequired', 'invalidTime', 'save', 'cancel',
        ];

        $this->assertSame($expected, array_keys($i18n));

        foreach ($i18n as $key => $string) {
            $this->assertNotSame('', $string, $key . ' should have a translatable string');
        }
    }

    /**
     * Three of the strings are sprintf templates filled in by the script, so
     * their placeholders have to survive translation.
     *
     * @test
     */
    public function the_countable_strings_keep_their_placeholders(): void
    {
        $this->assets->enqueue(self::HOOK);

        $i18n = $this->localizedData()['i18n'];

        $this->assertStringContainsString('%d', $i18n['manySelected']);
        $this->assertStringContainsString('%d', $i18n['bulkSkipped']);
        $this->assertStringContainsString('%s', $i18n['templateSaved']);
    }
}
