<?php

declare(strict_types=1);

namespace Trusted\Template;

/**
 * Registers the "Shift Template" custom post type.
 *
 * This CPT only holds reusable weekly shift patterns edited with ACF — it is
 * never queried on the front end (public => false). The heavy, frequently read
 * rota data lives in custom tables instead.
 */
final class TemplatePostType
{
    public function register(): void
    {
        $labels = [
            'name'               => __('Shift Templates', 'trusted'),
            'singular_name'      => __('Shift Template', 'trusted'),
            'add_new'            => __('Add Template', 'trusted'),
            'add_new_item'       => __('Add Shift Template', 'trusted'),
            'edit_item'          => __('Edit Shift Template', 'trusted'),
            'new_item'           => __('New Shift Template', 'trusted'),
            'view_item'          => __('View Shift Template', 'trusted'),
            'search_items'       => __('Search Templates', 'trusted'),
            'not_found'          => __('No templates found', 'trusted'),
            'not_found_in_trash' => __('No templates in Trash', 'trusted'),
            'menu_name'          => __('Shift Templates', 'trusted'),
        ];

        register_post_type(\TRUSTED_TEMPLATE_POST_TYPE, [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'exclude_from_search' => true,
            'show_ui'             => true,
            'show_in_menu'        => 'trusted', // Nest under the Trusted menu.
            'show_in_rest'        => false,
            'supports'            => ['title'],
            'has_archive'         => false,
            'rewrite'             => false,
            'query_var'           => false,
            'map_meta_cap'        => true,
        ]);
    }
}
