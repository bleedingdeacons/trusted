<?php

declare(strict_types=1);

namespace Trusted\Admin;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Forwarding\RotaForwardingProjection;
use Unity\Core\Interfaces\Container;

/**
 * "Forwarding" submenu: a week of the rota shown as the hunt group it would
 * make, laid out like Tamar's forwarding overview.
 *
 * Read-only, and it never talks to a forwarding driver — the page works
 * whether or not Tamar is active, and nothing on it sends anything upstream.
 * The week is chosen with `?week=` (any date in it); anything that is not a
 * real date falls back to the current week rather than erroring.
 *
 * Its week navigation is the Rota Calendar's: the same toolbar, label and
 * Previous / This week / Next buttons, styled by the calendar's own
 * stylesheet rather than a copy of it, so the two screens cannot drift apart.
 */
final class ForwardingPage
{
    public const SLUG = 'trusted-forwarding';

    public function __construct(private Container $container)
    {
    }

    private function capability(): string
    {
        return (string) apply_filters('trusted_capability', 'manage_options');
    }

    public function registerMenu(): void
    {
        $hook = add_submenu_page(
            CalendarPage::SLUG,
            __('Forwarding Preview', 'trusted'),
            __('Forwarding', 'trusted'),
            $this->capability(),
            self::SLUG,
            [$this, 'render']
        );

        // `load-{hook}` fires only when this screen is being loaded, early
        // enough for the stylesheet to go in the head. Keyed on the hook
        // WordPress returned rather than a spelled-out one, which would be
        // derived from the parent menu's (translatable) title.
        if (is_string($hook) && $hook !== '') {
            add_action('load-' . $hook, [$this, 'enqueueStyles']);
        }
    }

    /**
     * The Rota Calendar's stylesheet, for its week navigation toolbar. Same
     * handle as Assets uses, so it is never loaded twice.
     */
    public function enqueueStyles(): void
    {
        wp_enqueue_style(
            'trusted-calendar',
            \TRUSTED_URL . 'assets/css/calendar.css',
            [],
            \TRUSTED_VERSION
        );
    }

    public function render(): void
    {
        if (! current_user_can($this->capability())) {
            wp_die(esc_html__('You are not allowed to access this page.', 'trusted'));
        }

        $week = $this->requestedWeek();

        /** @var RotaRepositoryInterface $rota */
        $rota     = $this->container->get(RotaRepositoryInterface::class);
        $schedule = (new RotaForwardingProjection())->project($rota->findForWeek($week));

        echo '<div class="wrap trusted-wrap">';
        echo '<h1>' . esc_html__('Trusted — Forwarding preview', 'trusted') . '</h1>';
        echo '<p class="description">'
            . esc_html__('How this week\'s rota would be laid out as a call-forwarding hunt group. Each shift forwards to the responder assigned to it, and an unfilled shift forwards to voicemail; a shift that runs past midnight is split at 23:59. This is a preview only: nothing here is sent to Tamar.', 'trusted')
            . '</p>';

        $this->renderWeekNav($week);

        (new ForwardingPreview())->render($schedule, $week);

        echo '</div>';
    }

    /**
     * The Rota Calendar's week toolbar: the week label above Previous /
     * This week / Next, with the same wording and classes calendar.js uses.
     * Links rather than buttons, since this page is drawn on the server.
     */
    private function renderWeekNav(string $week): void
    {
        $monday = new \DateTimeImmutable($week);

        echo '<div class="trusted-toolbar">';
        echo '<div class="trusted-nav">';
        echo '<strong class="trusted-week-label">'
            . esc_html($week . ' – ' . $monday->modify('+6 days')->format('Y-m-d')) . '</strong>';
        echo '<div class="trusted-week-buttons">';
        echo '<a class="button" href="' . esc_url($this->weekUrl($monday->modify('-7 days')->format('Y-m-d'))) . '">'
            . esc_html__('← Previous', 'trusted') . '</a>';
        echo '<a class="button" href="' . esc_url($this->weekUrl(null)) . '">'
            . esc_html__('This week', 'trusted') . '</a>';
        echo '<a class="button" href="' . esc_url($this->weekUrl($monday->modify('+7 days')->format('Y-m-d'))) . '">'
            . esc_html__('Next →', 'trusted') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    /** The page for a week, or for the current week when $week is null. */
    private function weekUrl(?string $week): string
    {
        $args = ['page' => self::SLUG];

        if ($week !== null) {
            $args['week'] = $week;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    /**
     * The Monday of the week asked for in `?week=`, or of the current week
     * when none was asked for or the value is not a real date.
     */
    private function requestedWeek(): string
    {
        $asked = isset($_GET['week']) ? sanitize_text_field(wp_unslash((string) $_GET['week'])) : '';

        // wp_date() is false only for an unusable timestamp; UTC is near enough.
        $today = wp_date('Y-m-d');
        $today = is_string($today) ? $today : gmdate('Y-m-d');

        return $this->mondayOf($asked) ?? $this->mondayOf($today) ?? $today;
    }

    /**
     * Normalise any valid Y-m-d date to the Monday of its week. Returns null
     * for anything that is not a real calendar date.
     */
    private function mondayOf(string $date): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            return null;
        }

        $dow = (int) $dt->format('N'); // 1 (Mon) … 7 (Sun)

        return $dt->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }
}
