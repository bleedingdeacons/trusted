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
        add_submenu_page(
            CalendarPage::SLUG,
            __('Forwarding Preview', 'trusted'),
            __('Forwarding', 'trusted'),
            $this->capability(),
            self::SLUG,
            [$this, 'render']
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
            . esc_html__('How this week\'s rota would be laid out as a call-forwarding hunt group, one step per assigned shift. This is a preview only: nothing here is sent to Tamar.', 'trusted')
            . '</p>';

        $this->renderWeekNav($week);

        (new ForwardingPreview())->render($schedule, $week);

        echo '</div>';
    }

    private function renderWeekNav(string $week): void
    {
        $monday = new \DateTimeImmutable($week);
        $sunday = $monday->modify('+6 days');

        echo '<form method="get" class="trusted-forwarding-nav" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:1em 0 1.5em;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';

        echo '<a class="button" href="' . esc_url($this->weekUrl($monday->modify('-7 days')->format('Y-m-d'))) . '">'
            . '&larr; ' . esc_html__('Previous week', 'trusted') . '</a>';

        echo '<strong style="padding:0 4px;">' . esc_html(sprintf(
            /* translators: 1: Monday of the week, 2: Sunday of the week */
            __('%1$s – %2$s', 'trusted'),
            $monday->format('j M Y'),
            $sunday->format('j M Y')
        )) . '</strong>';

        echo '<a class="button" href="' . esc_url($this->weekUrl($monday->modify('+7 days')->format('Y-m-d'))) . '">'
            . esc_html__('Next week', 'trusted') . ' &rarr;</a>';

        echo '<label for="trusted-forwarding-week" class="screen-reader-text">' . esc_html__('Week (any day in the week)', 'trusted') . '</label>';
        echo '<input type="date" lang="en-GB" id="trusted-forwarding-week" name="week" value="' . esc_attr($week) . '" />';
        echo '<button type="submit" class="button">' . esc_html__('Show week', 'trusted') . '</button>';

        echo '</form>';
    }

    private function weekUrl(string $week): string
    {
        return add_query_arg(['page' => self::SLUG, 'week' => $week], admin_url('admin.php'));
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
