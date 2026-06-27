<?php

declare(strict_types=1);

namespace Trusted\Admin;

/**
 * Adds a "Help" submenu under the Trusted menu that opens the standalone
 * Trusted user guide (assets/docs/trusted.html).
 *
 * Mirrors Amber's HelpPage: clicking Help is intercepted and the guide is
 * opened in a named browser tab, with the current admin URL passed as
 * `?back=`. The guide's back button then refocuses that same tab via its
 * window name rather than reloading it, so the admin page keeps its scroll
 * position.
 */
final class HelpPage
{
    /** Submenu page slug. */
    public const SLUG = 'trusted-help';

    /** Window name given to the admin tab so the guide can refocus it. */
    private const ADMIN_WINDOW = 'trusted-admin';

    /** Window name the guide tab opens under, so repeat clicks reuse it. */
    private const HELP_WINDOW = 'trusted-help';

    private function capability(): string
    {
        return (string) apply_filters('trusted_capability', 'manage_options');
    }

    private function helpUrl(): string
    {
        return plugins_url('assets/docs/trusted.html', \TRUSTED_FILE);
    }

    /**
     * Register the Help submenu and the footer script that intercepts its
     * click. Hooked on `admin_menu` at a late priority so Help always sits
     * last in the Trusted submenu.
     */
    public function register(): void
    {
        add_submenu_page(
            CalendarPage::SLUG,
            __('Help', 'trusted'),
            __('Help', 'trusted'),
            $this->capability(),
            self::SLUG,
            [$this, 'render']
        );

        add_action('admin_footer', [$this, 'enqueueHelpTabScript']);
    }

    /**
     * Fallback page, shown only if the footer script does not intercept the
     * click (e.g. JavaScript disabled). Offers a direct link to the guide.
     */
    public function render(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Trusted Help', 'trusted') . '</h1>';
        echo '<p>' . esc_html__('Open the telephone rota user guide:', 'trusted') . '</p>';
        echo '<p><a class="button button-primary" target="_blank" rel="noopener" href="'
            . esc_url($this->helpUrl()) . '">' . esc_html__('Open the guide', 'trusted') . '</a></p>';
        echo '</div>';
    }

    /**
     * Intercept the Help submenu click and open the guide in a named tab,
     * passing the current admin URL as `?back=`. Naming the admin tab lets the
     * guide refocus it on "back" without a reload. window.open() inside a click
     * handler is a user gesture, so browsers don't treat it as a popup.
     */
    public function enqueueHelpTabScript(): void
    {
        $adminUrl = admin_url('admin.php?page=' . self::SLUG);
        ?>
        <script>
            (function () {
                var link = document.querySelector('a[href="<?php echo esc_js($adminUrl); ?>"]');
                if (!link) {
                    link = document.querySelector('a[href*="page=<?php echo esc_js(self::SLUG); ?>"]');
                }
                if (!link) return;
                link.addEventListener('click', function (e) {
                    e.preventDefault();
                    window.name = '<?php echo esc_js(self::ADMIN_WINDOW); ?>';
                    var helpUrl = '<?php echo esc_js($this->helpUrl()); ?>' + '?back=' + encodeURIComponent(window.location.href);
                    var existing = window.open('', '<?php echo esc_js(self::HELP_WINDOW); ?>');
                    try {
                        if (existing && existing.location && existing.location.href && existing.location.href !== 'about:blank') {
                            existing.focus();
                            return;
                        }
                    } catch (ex) {}
                    existing.location.href = helpUrl;
                });
            })();
        </script>
        <?php
    }
}
