<?php

/**
 * Plugin Name:       Trusted
 * Description:       A 7-day telephone shift rota manager built on the Unity plugin. Build weekly shift templates, apply them to a week, and assign Unity telephone responders from a calendar view. Uses custom database tables behind an interface/factory/repository layer registered in Unity's container.
 * Version:           1.6.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Requires Plugins:  unity
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/trusted
 * GitHub Branch:     main
 * Author:            The Bleeding Deacons
 * Contact:           thebleedingdeacons@gmail.com
 * License:           MIT (Modified)
 * Text Domain:       trusted
 *
 * @package Trusted
 */

declare(strict_types=1);

namespace Trusted;

if (! defined('ABSPATH')) {
    exit;
}

// Derive the version from the plugin header so it never drifts from the
// `Version:` line above (the single source of truth read by build.php).
if (! function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$trusted_plugin_data = get_plugin_data(__FILE__, false, false);
define('TRUSTED_VERSION', $trusted_plugin_data['Version']);
define('TRUSTED_FILE', __FILE__);
define('TRUSTED_DIR', plugin_dir_path(__FILE__));
define('TRUSTED_URL', plugin_dir_url(__FILE__));
define('TRUSTED_TEMPLATE_POST_TYPE', 'trusted_template');

/*
 * Autoloading.
 *
 * Prefer Composer's optimised autoloader when the package has been installed.
 * Fall back to a minimal PSR-4 autoloader so the plugin runs as a plain
 * download without a `composer install` step.
 */
if (is_readable(TRUSTED_DIR . 'vendor/autoload.php')) {
    require_once TRUSTED_DIR . 'vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix  = 'Trusted\\';
        $baseDir = TRUSTED_DIR . 'src/';

        if (! str_starts_with($class, $prefix)) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_readable($file)) {
            require_once $file;
        }
    });
}

register_activation_hook(__FILE__, [Activator::class, 'activate']);
register_deactivation_hook(__FILE__, [Deactivator::class, 'deactivate']);

/*
 * Trusted is a Unity-dependent plugin (like Amber): it registers its services
 * into Unity's container and boots once Unity is ready.
 *
 * These listeners are added at include time — before Unity fires the actions
 * during `plugins_loaded` — so wiring works regardless of plugin load order.
 */
add_action('unity/register_services', static function ($container): void {
    (new Core\TrustedServiceProvider())->register($container);
});

add_action('unity/loaded', static function ($container): void {
    Plugin::instance()->boot($container);
});

// If Unity never loads, Trusted cannot run — surface a clear admin notice.
add_action('plugins_loaded', static function (): void {
    if (function_exists('unity')) {
        return;
    }

    add_action('admin_notices', static function (): void {
        echo '<div class="notice notice-error"><p>'
            . esc_html__('Trusted requires the Unity plugin to be installed and active.', 'trusted')
            . '</p></div>';
    });
}, 20);
