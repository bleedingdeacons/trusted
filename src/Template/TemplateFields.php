<?php

declare(strict_types=1);

namespace Trusted\Template;

/**
 * Registers the ACF field group for shift templates.
 *
 * IMPORTANT — free-ACF constraint:
 * The Repeater and Flexible Content fields are ACF *Pro* only. To allow an
 * arbitrary number of shifts per day using only free fields, each day is a
 * plain Textarea: one shift per line, in the form
 *
 *     HH:MM-HH:MM | Optional label
 *
 * e.g.
 *     08:00-12:00 | Morning desk
 *     12:00-16:00 | Afternoon desk
 *     16:00-20:00 | Evening on-call
 *
 * Lines are parsed into Shift objects by TemplateApplicator. The "| label"
 * part is optional; a bare "09:00-17:00" works too.
 */
final class TemplateFields
{
    /**
     * Field key => weekday number (1 = Monday … 7 = Sunday, ISO-8601).
     *
     * @var array<string, int>
     */
    public const DAY_FIELDS = [
        'trusted_shifts_mon' => 1,
        'trusted_shifts_tue' => 2,
        'trusted_shifts_wed' => 3,
        'trusted_shifts_thu' => 4,
        'trusted_shifts_fri' => 5,
        'trusted_shifts_sat' => 6,
        'trusted_shifts_sun' => 7,
    ];

    /**
     * The ACF field key for a day field name. Mirrors the 'field_' prefix used
     * when the group is registered, so other code (e.g. TemplateValidator) can
     * address the same fields without duplicating the convention.
     */
    public static function fieldKey(string $name): string
    {
        return 'field_' . $name;
    }

    public function register(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        // Short per-field hint; the full guide lives in the help message at the
        // top of the group (added as the first field below).
        $instructions = __('One shift per line.', 'trusted');

        // Rich help block shown once at the top of the template editor.
        $help = '<p>' . __('Add one shift per line in each day, using:', 'trusted')
            . ' <code>HH:MM-HH:MM | Name | Member</code></p>'
            . '<ul style="list-style: disc; margin-left: 1.4em;">'
            . '<li>' . __('<strong>Times</strong> are 24-hour, from <code>00:00</code> to <code>24:00</code>, e.g. <code>09:00-17:00</code>. Use <code>00:00</code> for the start of the day and <code>24:00</code> for the end of the day, so a full day is <code>00:00-24:00</code>.', 'trusted') . '</li>'
            . '<li>' . __('<strong>Name</strong> is required — a line without one won\'t save.', 'trusted') . '</li>'
            . '<li>' . __('<strong>Member</strong> is optional: their anonymous name. They must be an existing telephone responder or the template won\'t save, and they\'re pre-assigned automatically when the template is applied.', 'trusted') . '</li>'
            . '</ul>'
            . '<p>' . __('Example:', 'trusted') . '</p>'
            . '<pre style="margin: 0;">09:00-13:00 | Reception | John D' . "\n" . '13:00-17:00 | Reception</pre>';

        $dayLabels = [
            'trusted_shifts_mon' => __('Monday', 'trusted'),
            'trusted_shifts_tue' => __('Tuesday', 'trusted'),
            'trusted_shifts_wed' => __('Wednesday', 'trusted'),
            'trusted_shifts_thu' => __('Thursday', 'trusted'),
            'trusted_shifts_fri' => __('Friday', 'trusted'),
            'trusted_shifts_sat' => __('Saturday', 'trusted'),
            'trusted_shifts_sun' => __('Sunday', 'trusted'),
        ];

        $fields = [
            [
                'key'       => 'field_trusted_template_help',
                'label'     => __('How to fill this in', 'trusted'),
                'name'      => '', // Message fields store nothing.
                'type'      => 'message',
                'message'   => $help,
                'new_lines' => '', // $help is already HTML.
                'esc_html'  => 0,  // Render the HTML rather than escaping it.
            ],
        ];

        foreach ($dayLabels as $name => $label) {
            $fields[] = [
                'key'          => self::fieldKey($name),
                'label'        => $label,
                'name'         => $name,
                'type'         => 'textarea', // Free ACF field.
                'instructions' => $instructions,
                'rows'         => 4,
                'new_lines'    => '', // Keep raw newlines; we parse them ourselves.
            ];
        }

        acf_add_local_field_group([
            'key'      => 'group_trusted_template',
            'title'    => __('Weekly Shift Pattern', 'trusted'),
            'fields'   => $fields,
            'location' => [
                [
                    [
                        'param'    => 'post_type',
                        'operator' => '==',
                        'value'    => \TRUSTED_TEMPLATE_POST_TYPE,
                    ],
                ],
            ],
            'menu_order'            => 0,
            'position'              => 'normal',
            'style'                 => 'default',
            'label_placement'       => 'top',
            'hide_on_screen'        => ['the_content'],
            'active'                => true,
            'description'           => __('A reusable weekly shift pattern. Apply it to a week from the Trusted calendar; any members you name are checked when you save and pre-assigned on apply.', 'trusted'),
        ]);

        // The template's name is its post title. WordPress would otherwise let
        // an untitled template publish, so require it through ACF's save
        // validation — the error blocks the save and surfaces in the editor
        // just like a required field.
        add_action('acf/validate_save_post', [$this, 'validateTemplateName']);
    }

    /**
     * Reject a shift template saved with an empty name (post title).
     *
     * Runs during ACF's save-validation pass. The template post type is
     * classic-editor (show_in_rest => false), so the post form's title and type
     * are present in $_POST when ACF validates.
     */
    public function validateTemplateName(): void
    {
        if (acf_maybe_get_POST('post_type') !== \TRUSTED_TEMPLATE_POST_TYPE) {
            return;
        }

        if (trim((string) acf_maybe_get_POST('post_title')) === '') {
            acf_add_validation_error(
                '',
                __('A template name is required — enter one in the title field at the top.', 'trusted')
            );
        }
    }
}
