<?php

declare(strict_types=1);

/**
 * Fired when the plugin is deleted from the WordPress admin.
 *
 * @package Trusted
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/Support/Database.php';

\Trusted\Support\Database::uninstall();

// Remove shift template posts created by the plugin.
$templates = get_posts([
    'post_type'      => 'trusted_template',
    'post_status'    => 'any',
    'posts_per_page' => -1,
    'fields'         => 'ids',
]);

foreach ($templates as $templateId) {
    wp_delete_post((int) $templateId, true);
}
