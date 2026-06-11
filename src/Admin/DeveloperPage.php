<?php

declare(strict_types=1);

namespace Trusted\Admin;

use Trusted\Contracts\RotaRepositoryInterface;
use Unity\Core\Interfaces\Container;

/**
 * A small developer / maintenance page nested under the Trusted menu.
 *
 * Currently it offers one destructive tool: delete every shift slot (and its
 * assignments) for a chosen week. The actual delete runs through a nonce-
 * protected admin-post handler, never on a GET, and is cascaded by
 * RotaRepository::deleteWeek().
 *
 * Visibility can be switched off in production with the `trusted_developer_tools`
 * filter (return false). The Trusted capability still gates access regardless.
 */
final class DeveloperPage
{
    public const SLUG = 'trusted-developer';

    /** admin-post action name for the delete-week handler. */
    private const ACTION = 'trusted_delete_week';

    /** admin-post action name for the clear-everything handler. */
    private const ACTION_ALL = 'trusted_clear_all';

    public function __construct(private Container $container)
    {
    }

    private function capability(): string
    {
        return (string) apply_filters('trusted_capability', 'manage_options');
    }

    private function enabled(): bool
    {
        return (bool) apply_filters('trusted_developer_tools', true);
    }

    public function registerMenu(): void
    {
        if (! $this->enabled()) {
            return;
        }

        add_submenu_page(
            CalendarPage::SLUG,
            __('Developer Tools', 'trusted'),
            __('Developer', 'trusted'),
            $this->capability(),
            self::SLUG,
            [$this, 'render']
        );
    }

    /**
     * Handle the delete-week form submission (hooked to
     * `admin_post_trusted_delete_week`). Verifies capability + nonce, deletes
     * the week, then redirects back to the page with a status for the notice.
     */
    public function handleDeleteWeek(): void
    {
        if (! $this->enabled() || ! current_user_can($this->capability())) {
            wp_die(
                esc_html__('You are not allowed to do this.', 'trusted'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION);

        $week   = isset($_POST['week']) ? sanitize_text_field(wp_unslash((string) $_POST['week'])) : '';
        $monday = $this->mondayOf($week);

        if ($monday === null) {
            $this->redirectBack('invalid', 0, '');

            return;
        }

        /** @var RotaRepositoryInterface $rota */
        $rota    = $this->container->get(RotaRepositoryInterface::class);
        $deleted = $rota->deleteWeek($monday);

        $this->redirectBack('deleted', $deleted, $monday);
    }

    /**
     * Handle the clear-everything form submission (hooked to
     * `admin_post_trusted_clear_all`). Deletes every shift and assignment in
     * every week.
     */
    public function handleClearAll(): void
    {
        if (! $this->enabled() || ! current_user_can($this->capability())) {
            wp_die(
                esc_html__('You are not allowed to do this.', 'trusted'),
                '',
                ['response' => 403]
            );
        }

        check_admin_referer(self::ACTION_ALL);

        // Require a typed confirmation so this can't fire by accident.
        $confirm = isset($_POST['confirm']) ? sanitize_text_field(wp_unslash((string) $_POST['confirm'])) : '';

        if (strtoupper($confirm) !== 'DELETE') {
            $this->redirectBack('not_confirmed', 0, '');

            return;
        }

        /** @var RotaRepositoryInterface $rota */
        $rota    = $this->container->get(RotaRepositoryInterface::class);
        $deleted = $rota->deleteAll();

        $this->redirectBack('cleared', $deleted, '');
    }

    public function render(): void
    {
        if (! $this->enabled() || ! current_user_can($this->capability())) {
            wp_die(esc_html__('You are not allowed to access this page.', 'trusted'));
        }

        $defaultWeek = $this->mondayOf(gmdate('Y-m-d')) ?? gmdate('Y-m-d');

        echo '<div class="wrap trusted-wrap">';
        echo '<h1>' . esc_html__('Trusted — Developer Tools', 'trusted') . '</h1>';

        $this->maybeRenderNotice();

        echo '<div class="card" style="max-width:520px;padding:8px 20px 20px;">';
        echo '<h2>' . esc_html__('Delete all shifts for a week', 'trusted') . '</h2>';
        echo '<p>' . wp_kses_post(__('Permanently deletes <strong>every shift slot and its assignments</strong> for the chosen week. This cannot be undone.', 'trusted')) . '</p>';

        $confirm = esc_js(__('Delete ALL shifts for this week? This cannot be undone.', 'trusted'));

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" '
            . 'onsubmit="return confirm(\'' . $confirm . '\');">';

        wp_nonce_field(self::ACTION);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '" />';

        echo '<p>';
        echo '<label for="trusted-week" style="display:block;margin-bottom:4px;font-weight:600;">'
            . esc_html__('Week (any day in the week)', 'trusted') . '</label>';
        echo '<input type="date" id="trusted-week" name="week" required value="' . esc_attr($defaultWeek) . '" />';
        echo '<br /><span class="description">' . esc_html__('Any date works — the whole Monday–Sunday week containing it is cleared.', 'trusted') . '</span>';
        echo '</p>';

        echo '<p><button type="submit" class="button button-primary trusted-danger" '
            . 'style="background:#b32d2e;border-color:#8a1f1f;box-shadow:none;text-shadow:none;">'
            . esc_html__('Delete all shifts for this week', 'trusted')
            . '</button></p>';

        echo '</form>';
        echo '</div>'; // .card

        $this->renderClearAllCard();

        echo '</div>'; // .wrap
    }

    private function renderClearAllCard(): void
    {
        $confirm = esc_js(__('Delete EVERY shift and assignment across all weeks? This cannot be undone.', 'trusted'));

        echo '<div class="card" style="max-width:520px;padding:8px 20px 20px;margin-top:20px;border-left:4px solid #b32d2e;">';
        echo '<h2>' . esc_html__('Clear everything', 'trusted') . '</h2>';
        echo '<p>' . wp_kses_post(__('Permanently deletes <strong>all shifts and all assignments in every week</strong>, emptying the rota entirely. The shift <em>templates</em> are left alone. This cannot be undone.', 'trusted')) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" '
            . 'onsubmit="return confirm(\'' . $confirm . '\');">';

        wp_nonce_field(self::ACTION_ALL);
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION_ALL) . '" />';

        echo '<p>';
        echo '<label for="trusted-confirm" style="display:block;margin-bottom:4px;font-weight:600;">'
            . esc_html__('Type DELETE to confirm', 'trusted') . '</label>';
        echo '<input type="text" id="trusted-confirm" name="confirm" required autocomplete="off" '
            . 'placeholder="DELETE" style="text-transform:uppercase;" />';
        echo '</p>';

        echo '<p><button type="submit" class="button button-primary trusted-danger" '
            . 'style="background:#b32d2e;border-color:#8a1f1f;box-shadow:none;text-shadow:none;">'
            . esc_html__('Clear all shifts and assignments', 'trusted')
            . '</button></p>';

        echo '</form>';
        echo '</div>'; // .card
    }

    private function maybeRenderNotice(): void
    {
        $status = isset($_GET['trusted_status']) ? sanitize_key((string) $_GET['trusted_status']) : '';

        if ($status === 'deleted') {
            $deleted = isset($_GET['trusted_deleted']) ? (int) $_GET['trusted_deleted'] : 0;
            $week    = isset($_GET['trusted_week']) ? sanitize_text_field(wp_unslash((string) $_GET['trusted_week'])) : '';

            $message = sprintf(
                /* translators: 1: number of slots deleted, 2: week-start date */
                _n(
                    'Deleted %1$d shift for the week of %2$s.',
                    'Deleted %1$d shifts for the week of %2$s.',
                    $deleted,
                    'trusted'
                ),
                $deleted,
                $week
            );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';

            return;
        }

        if ($status === 'cleared') {
            $deleted = isset($_GET['trusted_deleted']) ? (int) $_GET['trusted_deleted'] : 0;

            $message = sprintf(
                /* translators: %d: number of slots deleted */
                _n(
                    'Cleared the entire rota: %d shift and all its assignments removed.',
                    'Cleared the entire rota: %d shifts and all their assignments removed.',
                    $deleted,
                    'trusted'
                ),
                $deleted
            );

            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';

            return;
        }

        if ($status === 'not_confirmed') {
            echo '<div class="notice notice-warning is-dismissible"><p>'
                . esc_html__('You must type DELETE to confirm. Nothing was deleted.', 'trusted')
                . '</p></div>';

            return;
        }
        if ($status === 'invalid') {
            echo '<div class="notice notice-error is-dismissible"><p>'
                . esc_html__('That was not a valid date. Nothing was deleted.', 'trusted')
                . '</p></div>';
        }
    }

    private function redirectBack(string $status, int $deleted, string $week): void
    {
        wp_safe_redirect(add_query_arg(
            [
                'page'            => self::SLUG,
                'trusted_status'  => $status,
                'trusted_deleted' => $deleted,
                'trusted_week'    => rawurlencode($week),
            ],
            admin_url('admin.php')
        ));
        exit;
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
