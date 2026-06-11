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

    public function register(): void
    {
        if (! function_exists('acf_add_local_field_group')) {
            return;
        }

        $instructions = __(
            'One shift per line: <code>HH:MM-HH:MM | Name</code>. Give every shift a name; if you omit it, the time range is used as the name.',
            'trusted'
        );

        $dayLabels = [
            'trusted_shifts_mon' => __('Monday', 'trusted'),
            'trusted_shifts_tue' => __('Tuesday', 'trusted'),
            'trusted_shifts_wed' => __('Wednesday', 'trusted'),
            'trusted_shifts_thu' => __('Thursday', 'trusted'),
            'trusted_shifts_fri' => __('Friday', 'trusted'),
            'trusted_shifts_sat' => __('Saturday', 'trusted'),
            'trusted_shifts_sun' => __('Sunday', 'trusted'),
        ];

        $fields = [];

        foreach ($dayLabels as $name => $label) {
            $fields[] = [
                'key'          => 'field_' . $name,
                'label'        => $label,
                'name'         => $name,
                'type'         => 'textarea', // Free ACF field.
                'instructions' => $instructions,
                'rows'         => 4,
                'new_lines'    => '', // Keep raw newlines; we parse them ourselves.
                'placeholder'  => "09:00-13:00 | Reception\n13:00-17:00 | Reception",
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
            'description'           => __('Defines the shifts for each day of a week.', 'trusted'),
        ]);
    }
}
