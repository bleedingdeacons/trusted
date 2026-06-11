<?php

declare(strict_types=1);

namespace Trusted\Admin;

/**
 * Registers the top-level "Trusted" admin menu and renders the calendar shell.
 * The actual calendar is rendered client-side (assets/js/calendar.js) against
 * the REST API; this page just provides the mount point.
 */
final class CalendarPage
{
    public const SLUG = 'trusted';

    public function registerMenu(): void
    {
        $capability = (string) apply_filters('trusted_capability', 'manage_options');

        add_menu_page(
            __('Trusted', 'trusted'),
            __('Trusted', 'trusted'),
            $capability,
            self::SLUG,
            [$this, 'render'],
            'dashicons-phone',
            26
        );

        add_submenu_page(
            self::SLUG,
            __('Rota Calendar', 'trusted'),
            __('Rota Calendar', 'trusted'),
            $capability,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        echo '<div class="wrap trusted-wrap">';
        echo '<h1>' . esc_html__('Telephone Rota', 'trusted') . '</h1>';

        if (! function_exists('acf_add_local_field_group')) {
            echo '<div class="notice notice-warning"><p>'
                . wp_kses_post(__('Advanced Custom Fields (free) is not active. You can still assign slots manually, but weekly shift <strong>templates</strong> require ACF.', 'trusted'))
                . '</p></div>';
        }

        // Mount point — calendar.js takes over from here.
        echo '<div id="trusted-calendar" class="trusted-calendar" aria-live="polite">';
        echo '<p class="trusted-loading">' . esc_html__('Loading rota…', 'trusted') . '</p>';
        echo '</div>';
        echo '</div>';
    }
}
