<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Admin;

use BleedingDeacons\WpMocks\Exceptions\WpDieException;
use BleedingDeacons\WpMocks\WpState;
use Brain\Monkey\Filters;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use Trusted\Admin\CalendarPage;
use Trusted\Admin\DeveloperPage;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Tests\TestCase;
use Unity\Core\Interfaces\Container;

/**
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
 *     expectException.
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
 *
 * @covers \Trusted\Admin\DeveloperPage
 */
final class DeveloperPageTest extends TestCase
{
    private Container&MockInterface $container;
    private RotaRepositoryInterface&MockInterface $rota;
    private DeveloperPage $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rota      = Mockery::mock(RotaRepositoryInterface::class);
        $this->container = Mockery::mock(Container::class);
        $this->page      = new DeveloperPage($this->container);

        $_GET  = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_GET  = [];
        $_POST = [];

        parent::tearDown();
    }

    private function expectRepository(): void
    {
        $this->container->shouldReceive('get')
            ->once()
            ->with(RotaRepositoryInterface::class)
            ->andReturn($this->rota);
    }

    /** @param array<int, mixed> $args */
    private function callPrivate(string $method, array $args = []): mixed
    {
        return (new ReflectionMethod(DeveloperPage::class, $method))->invokeArgs($this->page, $args);
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
    public function it_registers_a_developer_submenu_under_the_trusted_menu(): void
    {
        $this->page->registerMenu();

        $this->assertCount(1, WpState::$menus);

        $menu = WpState::$menus[0];

        $this->assertSame('submenu', $menu['type']);
        $this->assertSame(CalendarPage::SLUG, $menu['parent']);
        $this->assertSame(DeveloperPage::SLUG, $menu['slug']);
        $this->assertSame('Developer', $menu['title']);
        $this->assertSame('manage_options', $menu['cap']);
    }

    /**
     * `trusted_developer_tools` is the production off-switch: returning false
     * hides the page entirely rather than merely tightening its capability.
     *
     * @test
     */
    public function the_submenu_is_not_registered_when_developer_tools_are_switched_off(): void
    {
        Filters\expectApplied('trusted_developer_tools')->andReturn(false);

        $this->page->registerMenu();

        $this->assertSame([], WpState::$menus);
    }

    /** @test */
    public function the_submenu_capability_is_filterable(): void
    {
        Filters\expectApplied('trusted_capability')->andReturn('edit_trusted_rota');

        $this->page->registerMenu();

        $this->assertSame('edit_trusted_rota', WpState::$menus[0]['cap']);
    }

    // ── guards ────────────────────────────────────────────────────────

    /**
     * Nothing trusts the menu to have hidden itself: the page and both actions
     * re-check, since admin-post.php is reachable by URL whether or not a menu
     * entry was ever drawn.
     *
     * @test
     * @dataProvider guardedMethods
     */
    public function every_entry_point_refuses_a_user_without_the_capability(string $method): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->page->{$method}();
    }

    /**
     * @test
     * @dataProvider guardedMethods
     */
    public function every_entry_point_refuses_when_developer_tools_are_switched_off(string $method): void
    {
        Filters\expectApplied('trusted_developer_tools')->andReturn(false);

        $this->expectException(WpDieException::class);
        $this->page->{$method}();
    }

    /** @return array<string, array{0: string}> */
    public static function guardedMethods(): array
    {
        return [
            'the page'        => ['render'],
            'delete a week'   => ['handleDeleteWeek'],
            'clear the rota'  => ['handleClearAll'],
        ];
    }

    /** @test */
    public function the_refusal_says_what_was_refused(): void
    {
        WpState::$userCan = false;

        $this->expectException(WpDieException::class);
        $this->expectExceptionMessage('You are not allowed to access this page.');
        $this->page->render();
    }

    /** @test */
    public function a_refused_action_deletes_nothing(): void
    {
        WpState::$userCan = false;
        // The container is never touched, so no `get` expectation is set: an
        // unexpected call to a Mockery mock fails the test.
        $this->container->shouldNotReceive('get');

        $this->expectException(WpDieException::class);
        $this->page->handleDeleteWeek();
    }

    // ── the page ──────────────────────────────────────────────────────

    /** @test */
    public function the_page_offers_both_destructive_tools(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('Delete all shifts for a week', $html);
        $this->assertStringContainsString('Clear everything', $html);
        $this->assertStringContainsString('Trusted — Developer Tools', $html);
    }

    /**
     * Both forms post to admin-post.php with the action name their handler is
     * hooked on, and both carry a nonce — the pairing check_admin_referer()
     * depends on.
     *
     * @test
     */
    public function both_forms_post_to_a_nonce_protected_admin_post_action(): void
    {
        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'admin-post.php'));

        foreach (['trusted_delete_week', 'trusted_clear_all'] as $action) {
            $this->assertStringContainsString('name="action" value="' . $action . '"', $html);
            $this->assertStringContainsString('value="nonce-' . $action . '"', $html);
        }
    }

    /** @test */
    public function both_forms_ask_for_confirmation_before_submitting(): void
    {
        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'onsubmit="return confirm('));
        // The clear-everything form additionally requires the word typed out.
        $this->assertStringContainsString('Type DELETE to confirm', $html);
        $this->assertStringContainsString('name="confirm"', $html);
    }

    /**
     * The week field is prefilled with the Monday of the current week, so the
     * common case is one click rather than a date-picker hunt.
     *
     * @test
     */
    public function the_week_field_defaults_to_the_monday_of_the_current_week(): void
    {
        $html = $this->render();

        $this->assertMatchesRegularExpression(
            '/name="week" required value="(\d{4}-\d{2}-\d{2})"/',
            $html
        );

        preg_match('/name="week" required value="(\d{4}-\d{2}-\d{2})"/', $html, $m);

        $this->assertSame('Monday', (new \DateTimeImmutable($m[1]))->format('l'));
        $this->assertSame($this->callPrivate('mondayOf', [gmdate('Y-m-d')]), $m[1]);
    }

    // ── notices ───────────────────────────────────────────────────────

    /** @test */
    public function no_notice_is_shown_on_a_plain_page_load(): void
    {
        $this->assertStringNotContainsString('notice-', $this->render());
    }

    /** @test */
    public function an_unrecognised_status_shows_no_notice(): void
    {
        $_GET = ['trusted_status' => 'something_else'];

        $this->assertStringNotContainsString('notice-', $this->render());
    }

    /** @test */
    public function the_delete_notice_reports_the_count_and_the_week(): void
    {
        $_GET = ['trusted_status' => 'deleted', 'trusted_deleted' => '7', 'trusted_week' => '2026-08-03'];

        $html = $this->render();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Deleted 7 shifts for the week of 2026-08-03.', $html);
    }

    /**
     * The counts are pluralised through _n(), so one deleted slot must not
     * read "1 shifts".
     *
     * @test
     */
    public function the_delete_notice_is_singular_for_one_shift(): void
    {
        $_GET = ['trusted_status' => 'deleted', 'trusted_deleted' => '1', 'trusted_week' => '2026-08-03'];

        $this->assertStringContainsString('Deleted 1 shift for the week', $this->render());
    }

    /** @test */
    public function the_delete_notice_copes_with_missing_query_args(): void
    {
        $_GET = ['trusted_status' => 'deleted'];

        $this->assertStringContainsString('Deleted 0 shifts for the week of .', $this->render());
    }

    /** @test */
    public function the_cleared_notice_reports_the_total(): void
    {
        $_GET = ['trusted_status' => 'cleared', 'trusted_deleted' => '42'];

        $html = $this->render();

        $this->assertStringContainsString('notice-success', $html);
        $this->assertStringContainsString('Cleared the entire rota: 42 shifts', $html);
    }

    /** @test */
    public function the_cleared_notice_is_singular_for_one_shift(): void
    {
        $_GET = ['trusted_status' => 'cleared', 'trusted_deleted' => '1'];

        $this->assertStringContainsString('Cleared the entire rota: 1 shift and', $this->render());
    }

    /** @test */
    public function the_unconfirmed_notice_is_a_warning_not_a_success(): void
    {
        $_GET = ['trusted_status' => 'not_confirmed'];

        $html = $this->render();

        $this->assertStringContainsString('notice-warning', $html);
        $this->assertStringContainsString('You must type DELETE to confirm.', $html);
        $this->assertStringNotContainsString('notice-success', $html);
    }

    /** @test */
    public function the_invalid_date_notice_is_an_error(): void
    {
        $_GET = ['trusted_status' => 'invalid'];

        $html = $this->render();

        $this->assertStringContainsString('notice-error', $html);
        $this->assertStringContainsString('That was not a valid date.', $html);
    }

    // ── delete-week (reflection: the live method exits) ────────────────

    /** @test */
    public function deleting_a_week_normalises_the_posted_date_and_reports_the_count(): void
    {
        // A Thursday: the whole Monday–Sunday week containing it is cleared.
        $_POST = ['week' => '2026-08-06'];
        $this->expectRepository();
        $this->rota->shouldReceive('deleteWeek')->once()->with('2026-08-03')->andReturn(9);

        $this->assertSame(['deleted', 9, '2026-08-03'], $this->callPrivate('deleteWeekFromRequest'));
    }

    /**
     * `week` comes straight off a <input type="date">, which a hand-built POST
     * can trivially bypass. Anything that is not a real calendar date is
     * rejected before the repository is reached.
     *
     * @test
     * @dataProvider rejectedWeeks
     */
    public function a_week_that_is_not_a_real_date_deletes_nothing(mixed $posted): void
    {
        $_POST = $posted === null ? [] : ['week' => $posted];
        $this->container->shouldNotReceive('get');

        $this->assertSame(['invalid', 0, ''], $this->callPrivate('deleteWeekFromRequest'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejectedWeeks(): array
    {
        return [
            'absent'               => [null],
            'empty'                => [''],
            'not a date at all'    => ['nonsense'],
            'wrong separator'      => ['2026/08/06'],
            'unpadded'             => ['2026-8-6'],
            'month 13'             => ['2026-13-01'],
            'the 31st of February' => ['2026-02-31'],
            'a date with a time'   => ['2026-08-06T09:00'],
            'trailing rubbish'     => ['2026-08-06; DROP TABLE'],
        ];
    }

    /**
     * The Monday-of-week walk-back is the only arithmetic on the page, and the
     * ends of the week are where it goes wrong.
     *
     * @test
     * @dataProvider mondays
     */
    public function any_day_normalises_to_the_monday_of_its_week(string $date, string $monday): void
    {
        $this->assertSame($monday, $this->callPrivate('mondayOf', [$date]));
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function mondays(): array
    {
        return [
            'Monday is itself'          => ['2026-08-03', '2026-08-03'],
            'Thursday walks back'       => ['2026-08-06', '2026-08-03'],
            'Sunday closes its own week' => ['2026-08-09', '2026-08-03'],
            'across a month boundary'   => ['2026-09-02', '2026-08-31'],
            'across a year boundary'    => ['2027-01-01', '2026-12-28'],
            'a leap day'                => ['2028-02-29', '2028-02-28'],
        ];
    }

    // ── clear-everything (reflection: the live method exits) ───────────

    /** @test */
    public function clearing_everything_requires_the_word_delete(): void
    {
        $_POST = ['confirm' => 'DELETE'];
        $this->expectRepository();
        $this->rota->shouldReceive('deleteAll')->once()->andReturn(140);

        $this->assertSame(['cleared', 140, ''], $this->callPrivate('clearAllFromRequest'));
    }

    /**
     * The field is uppercased in CSS only, so the typed value arrives in
     * whatever case it was entered; sanitize_text_field() trims it.
     *
     * @test
     * @dataProvider acceptedConfirmations
     */
    public function the_typed_confirmation_is_trimmed_and_case_insensitive(string $posted): void
    {
        $_POST = ['confirm' => $posted];
        $this->expectRepository();
        $this->rota->shouldReceive('deleteAll')->once()->andReturn(0);

        $this->assertSame(['cleared', 0, ''], $this->callPrivate('clearAllFromRequest'));
    }

    /** @return array<string, array{0: string}> */
    public static function acceptedConfirmations(): array
    {
        return [
            'lower case' => ['delete'],
            'mixed case' => ['Delete'],
            'padded'     => ['  DELETE  '],
        ];
    }

    /**
     * @test
     * @dataProvider rejectedConfirmations
     */
    public function anything_other_than_delete_clears_nothing(mixed $posted): void
    {
        $_POST = $posted === null ? [] : ['confirm' => $posted];
        $this->container->shouldNotReceive('get');

        $this->assertSame(['not_confirmed', 0, ''], $this->callPrivate('clearAllFromRequest'));
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejectedConfirmations(): array
    {
        return [
            'absent'          => [null],
            'empty'           => [''],
            'a near miss'     => ['DELET'],
            'the wrong word'  => ['YES'],
            'a different verb' => ['REMOVE'],
        ];
    }

    // ── the redirect target ───────────────────────────────────────────

    /**
     * The status the handlers redirect with is the status maybeRenderNotice()
     * reads back, so the query-arg names are a contract between the two halves
     * of the round trip.
     *
     * @test
     */
    public function the_redirect_carries_the_args_the_notice_reads_back(): void
    {
        $url = (string) $this->callPrivate('redirectUrl', ['deleted', 9, '2026-08-03']);

        $this->assertStringContainsString('page=' . DeveloperPage::SLUG, $url);
        $this->assertStringContainsString('trusted_status=deleted', $url);
        $this->assertStringContainsString('trusted_deleted=9', $url);
        $this->assertStringContainsString('admin.php', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('2026-08-03', rawurldecode((string) $query['trusted_week']));
    }

    /** @test */
    public function the_redirect_target_stays_inside_wp_admin(): void
    {
        $url = (string) $this->callPrivate('redirectUrl', ['cleared', 0, '']);

        $this->assertStringStartsWith('https://example.test/wp-admin/admin.php', $url);
    }
}
