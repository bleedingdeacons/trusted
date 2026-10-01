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
 * The preview itself never talks to a forwarding driver, so the page works
 * whether or not Tamar is active. When Tamar is active it registers the
 * `tamar/publish_huntgroup` filter, and the page then offers one button that
 * does send something upstream: publishing the week to the Tamar control
 * panel as a hunt group named "Forward Week N" (the ISO week number). Tamar
 * creates the group if need be, replaces its rows, and makes it the group its
 * Overview shows. The week is chosen with `?week=` (any date in it); anything
 * that is not a real date falls back to the current week rather than erroring.
 *
 * Its week navigation is the Rota Calendar's: the same toolbar, label and
 * Previous / This week / Next buttons, styled by the calendar's own
 * stylesheet rather than a copy of it, so the two screens cannot drift apart.
 */
final class ForwardingPage
{
    public const SLUG = 'trusted-forwarding';

    /** The admin-post action, and nonce action, of the Publish button. */
    public const PUBLISH_ACTION = 'trusted_publish_forwarding';

    /** The filter Tamar publishes a hunt group through. */
    public const PUBLISH_HOOK = 'tamar/publish_huntgroup';

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
            . esc_html__('How this week\'s rota would be laid out as a call-forwarding hunt group. Each shift forwards to the responder assigned to it, and an unfilled shift forwards to voicemail; a shift that runs past midnight is split at 23:59.', 'trusted')
            . ' '
            . ($this->canPublish()
                ? esc_html__('Nothing is sent to Tamar until you press Publish.', 'trusted')
                : esc_html__('This is a preview only: nothing here is sent to Tamar.', 'trusted'))
            . '</p>';

        $this->renderPublishNotice();
        $this->renderWeekNav($week);

        if ($this->canPublish() && $schedule->rules !== []) {
            $this->renderPublishForm($week);
        }

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
            . esc_html(
                $week . ' – ' . $monday->modify('+6 days')->format('Y-m-d') . ' · '
                /* translators: %d: ISO-8601 week number, 1–53. */
                . sprintf(__('Week %d', 'trusted'), (int) $monday->format('W'))
            ) . '</strong>';
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

    /** Whether Tamar is there to publish to. */
    private function canPublish(): bool
    {
        return has_filter(self::PUBLISH_HOOK) !== false;
    }

    /**
     * The hunt group a week is published as: "Forward Week 5". Not
     * translated — it is a name in the Tamar panel, and publishing the same
     * week again finds the group by it.
     */
    public static function huntgroupName(string $monday): string
    {
        return sprintf('Forward Week %d', (int) (new \DateTimeImmutable($monday))->format('W'));
    }

    private function renderPublishForm(string $week): void
    {
        $name = self::huntgroupName($week);
        $confirm = sprintf(
            /* translators: %s: hunt group name, e.g. "Forward Week 5". */
            __('Publish this week to Tamar as "%s"? A hunt group of that name is created if there is none, and every row in it is replaced.', 'trusted'),
            $name
        );

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="trusted-publish">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::PUBLISH_ACTION) . '">';
        echo '<input type="hidden" name="week" value="' . esc_attr($week) . '">';
        wp_nonce_field(self::PUBLISH_ACTION);
        echo '<p><button type="submit" class="button button-primary" onclick="'
            . esc_attr('return window.confirm(' . (string) wp_json_encode($confirm) . ');')
            . '">'
            . esc_html(sprintf(
                /* translators: %s: hunt group name, e.g. "Forward Week 5". */
                __('Publish to Tamar as "%s"', 'trusted'),
                $name
            ))
            . '</button> <span class="description">'
            . esc_html__('Tamar then shows this group on its Overview. Callers reach it only once the phone number points at it in the Tamar panel.', 'trusted')
            . '</span></p>';
        echo '</form>';
    }

    /**
     * Handle the Publish button (hooked to `admin_post_trusted_publish_forwarding`).
     */
    public function handlePublish(): void
    {
        if (! current_user_can($this->capability())) {
            wp_die(esc_html__('You are not allowed to do this.', 'trusted'), '', ['response' => 403]);
        }

        check_admin_referer(self::PUBLISH_ACTION);

        $notice = $this->publishFromRequest();
        set_transient($this->noticeKey(), $notice, 60);

        wp_safe_redirect($this->weekUrl($notice['week'] !== '' ? $notice['week'] : null));
        exit;
    }

    /**
     * The body of handlePublish(), minus the guards and the redirect-and-exit,
     * so it can be driven directly.
     *
     * @return array{type: string, message: string, week: string}
     */
    private function publishFromRequest(): array
    {
        $asked  = isset($_POST['week']) ? sanitize_text_field(wp_unslash((string) $_POST['week'])) : '';
        $monday = $this->mondayOf($asked);

        if ($monday === null) {
            return ['type' => 'error', 'message' => __('That is not a week that can be published.', 'trusted'), 'week' => ''];
        }

        if (! $this->canPublish()) {
            return ['type' => 'error', 'message' => __('Tamar is not active, so there is nowhere to publish to.', 'trusted'), 'week' => $monday];
        }

        /** @var RotaRepositoryInterface $rota */
        $rota     = $this->container->get(RotaRepositoryInterface::class);
        $schedule = (new RotaForwardingProjection())->project($rota->findForWeek($monday));
        $name     = self::huntgroupName($monday);

        if ($schedule->rules === []) {
            return ['type' => 'error', 'message' => __('This week has no shifts, so there is nothing to publish.', 'trusted'), 'week' => $monday];
        }

        try {
            $published = apply_filters(self::PUBLISH_HOOK, null, $name, $schedule->rules);
        } catch (\Throwable $e) {
            return [
                'type'    => 'error',
                /* translators: 1: hunt group name, 2: the reason Tamar gave. */
                'message' => sprintf(__('Could not publish "%1$s" to Tamar: %2$s', 'trusted'), $name, $e->getMessage()),
                'week'    => $monday,
            ];
        }

        if (! is_array($published) || ! isset($published['id'])) {
            return [
                'type'    => 'error',
                /* translators: %s: hunt group name. */
                'message' => sprintf(__('Tamar did not confirm that "%s" was published.', 'trusted'), $name),
                'week'    => $monday,
            ];
        }

        $steps = count($schedule->rules);

        return [
            'type'    => 'success',
            'message' => sprintf(
                /* translators: 1: hunt group name, 2: number of forwarding steps. */
                _n(
                    'Published "%1$s" to Tamar with %2$d forwarding step. Tamar\'s Overview now shows it.',
                    'Published "%1$s" to Tamar with %2$d forwarding steps. Tamar\'s Overview now shows it.',
                    $steps,
                    'trusted'
                ),
                $name,
                $steps
            ),
            'week'    => $monday,
        ];
    }

    /** Show, once, the outcome of this user's last Publish. */
    private function renderPublishNotice(): void
    {
        $notice = get_transient($this->noticeKey());

        if (! is_array($notice) || ! isset($notice['type'], $notice['message'])) {
            return;
        }

        delete_transient($this->noticeKey());

        $type = $notice['type'] === 'success' ? 'success' : 'error';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>'
            . esc_html((string) $notice['message']) . '</p></div>';
    }

    private function noticeKey(): string
    {
        return 'trusted_publish_notice_' . get_current_user_id();
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
