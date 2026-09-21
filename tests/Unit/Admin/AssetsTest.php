<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use Trusted\Admin\Assets;
use Trusted\Admin\CalendarPage;
use Trusted\Http\RestController;

/*
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
 */

covers(Assets::class);

const CALENDAR_HOOK = 'toplevel_page_' . CalendarPage::SLUG;

function freezeNow(string $when): void
{
    Functions\when('current_datetime')->justReturn(new DateTimeImmutable($when));
}

/**
 * @return array<string, mixed>
 */
function localizedData(): array
{
    expect(WpState::$localized)->toHaveKey(
        'TrustedData',
        message: 'calendar.js reads its configuration from window.TrustedData'
    );

    return WpState::$localized['TrustedData'];
}

beforeEach(function () {
    $this->assets = new Assets();

    // Not in the shared stub layer. Fixed so "this week" is deterministic;
    // 2026-08-06 is a Thursday.
    freezeNow('2026-08-06 09:30:00');
});

// ── the screen gate ───────────────────────────────────────────────
it('enqueues nothing on another admin screen', function (string $hook) {
    $this->assets->enqueue($hook);

    expect(WpState::$enqueued)->toBe([])
        ->and(WpState::$localized)->toBe([]);
})->with([
    'the dashboard'         => ['index.php'],
    'the posts list'        => ['edit.php'],
    'another plugin'        => ['toplevel_page_amber'],
    // The Developer and Help submenus are Trusted's, but the calendar is not
    // mounted there and its assets are dead weight.
    'the developer submenu' => ['trusted_page_trusted-developer'],
    'the help submenu'      => ['trusted_page_trusted-help'],
]);

it('enqueues the calendar stylesheet and script on the calendar screen', function () {
    $this->assets->enqueue(CALENDAR_HOOK);

    expect(WpState::$enqueued)->toBe([
        ['fn' => 'wp_enqueue_style', 'handle' => 'trusted-calendar'],
        ['fn' => 'wp_enqueue_script', 'handle' => 'trusted-calendar'],
    ]);
});

// ── the localised payload ─────────────────────────────────────────
describe('the localised payload', function () {
    it("points the script at the plugin's own REST namespace", function () {
        $this->assets->enqueue(CALENDAR_HOOK);

        expect(localizedData())
            ->restRoot->toEndWith(RestController::NAMESPACE)
            ->nonce->toBe('nonce-wp_rest', 'the REST nonce action must be wp_rest');
    });

    // The calendar renders Monday-first or Sunday-first from the site's own
    // Settings → General value, defaulting to Monday when it is unset.
    it('takes the first day of the week from the site setting', function () {
        WpState::$options['start_of_week'] = '0';

        $this->assets->enqueue(CALENDAR_HOOK);

        expect(localizedData()['startDow'])->toBe(0, 'startDow should be an int');
    });

    it('defaults the first day of the week to Monday', function () {
        $this->assets->enqueue(CALENDAR_HOOK);

        expect(localizedData()['startDow'])->toBe(1);
    });

    // "This week" is anchored to the site's timezone via current_datetime()
    // rather than PHP's default. On a site running ahead of UTC, a plain
    // `new DateTimeImmutable('today')` can still read as yesterday and open
    // the calendar on the previous week.
    it('opens the calendar on the Monday of the current week', function (string $now, string $expectedMonday) {
        freezeNow($now);

        $this->assets->enqueue(CALENDAR_HOOK);

        expect(localizedData()['weekStart'])->toBe($expectedMonday);
    })->with([
        'Monday stays put'               => ['2026-08-03 00:00:00', '2026-08-03'],
        'midweek walks back'             => ['2026-08-06 09:30:00', '2026-08-03'],
        'Sunday belongs to its own week' => ['2026-08-09 23:59:59', '2026-08-03'],
        'the next Monday moves on'       => ['2026-08-10 00:00:01', '2026-08-10'],
        'across a month boundary'        => ['2026-09-02 12:00:00', '2026-08-31'],
        'across a year boundary'         => ['2027-01-01 12:00:00', '2026-12-28'],
    ]);

    // Every string calendar.js reads out of TrustedData.i18n. Kept as an
    // explicit list because the failure mode of a dropped key is a button
    // labelled "undefined" in wp-admin, not an error anywhere in PHP.
    it('hands the full i18n table to the script', function () {
        $this->assets->enqueue(CALENDAR_HOOK);

        $i18n = localizedData()['i18n'];

        expect(array_keys($i18n))->toBe([
            'assign', 'selectMember', 'addShift', 'applyTemplate', 'selectTemplate',
            'replace', 'prevWeek', 'nextWeek', 'today', 'remove', 'confirmRemove',
            'bulkAssign', 'bulkHint', 'oneSelected', 'manySelected', 'bulkSkipped',
            'noTemplates', 'unassigned', 'gap', 'gapAddHint', 'gapLocked', 'saveAsTemplate',
            'templateName', 'includeMembers', 'templateNameRequired', 'templateSaved',
            'clearWeek', 'confirmClearWeek', 'clearAssignments', 'confirmClearAssignments',
            'delete', 'addingShift', 'memberOptional', 'newSlotStart', 'newSlotEnd',
            'newSlotLabel', 'nameRequired', 'invalidTime', 'save', 'cancel',
        ]);

        expect($i18n)->each(
            fn ($string, $key) => $string->not->toBe('', $key . ' should have a translatable string')
        );
    });

    // Three of the strings are sprintf templates filled in by the script, so
    // their placeholders have to survive translation.
    it('keeps the placeholders in the countable strings', function () {
        $this->assets->enqueue(CALENDAR_HOOK);

        expect(localizedData()['i18n'])
            ->manySelected->toContain('%d')
            ->bulkSkipped->toContain('%d')
            ->templateSaved->toContain('%s');
    });
});
