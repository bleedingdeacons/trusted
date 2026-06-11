<?php

declare(strict_types=1);

namespace Trusted\Admin;

use Trusted\Http\RestController;

final class Assets
{
    public function enqueue(string $hook): void
    {
        // Only load on the top-level Trusted calendar page.
        if ($hook !== 'toplevel_page_' . CalendarPage::SLUG) {
            return;
        }

        wp_enqueue_style(
            'trusted-calendar',
            \TRUSTED_URL . 'assets/css/calendar.css',
            [],
            \TRUSTED_VERSION
        );

        wp_enqueue_script(
            'trusted-calendar',
            \TRUSTED_URL . 'assets/js/calendar.js',
            [],
            \TRUSTED_VERSION,
            true
        );

        wp_localize_script('trusted-calendar', 'TrustedData', [
            'restRoot'  => esc_url_raw(rest_url(RestController::NAMESPACE)),
            'nonce'     => wp_create_nonce('wp_rest'),
            'weekStart' => $this->currentMonday(),
            'startDow'  => (int) get_option('start_of_week', 1), // 0 = Sun, 1 = Mon
            'i18n'      => [
                'assign'        => __('Assign', 'trusted'),
                'selectMember'  => __('— Select a member —', 'trusted'),
                'addShift'      => __('+ Add shift', 'trusted'),
                'applyTemplate' => __('Apply template', 'trusted'),
                'replace'       => __('Replace existing slots this week', 'trusted'),
                'prevWeek'      => __('← Previous', 'trusted'),
                'nextWeek'      => __('Next →', 'trusted'),
                'today'         => __('This week', 'trusted'),
                'remove'        => __('Remove', 'trusted'),
                'bulkAssign'    => __('Assign member to shifts', 'trusted'),
                'bulkHint'      => __('Pick a member, tick the empty shifts to fill, then Assign.', 'trusted'),
                'oneSelected'   => __('1 shift selected', 'trusted'),
                /* translators: %d: number of shifts selected. */
                'manySelected'  => __('%d shifts selected', 'trusted'),
                /* translators: %d: number of shifts that were already filled and skipped. */
                'bulkSkipped'   => __('%d shift(s) were already filled and left unchanged.', 'trusted'),
                'noTemplates'   => __('No templates yet', 'trusted'),
                'unassigned'    => __('Unassigned', 'trusted'),
                'confirmDelete' => __('Delete this shift slot and its assignments?', 'trusted'),
                'newSlotStart'  => __('Start (HH:MM)', 'trusted'),
                'newSlotEnd'    => __('End (HH:MM)', 'trusted'),
                'newSlotLabel'  => __('Shift name', 'trusted'),
                'nameRequired'  => __('Please enter a shift name.', 'trusted'),
                'save'          => __('Save', 'trusted'),
                'cancel'        => __('Cancel', 'trusted'),
            ],
        ]);
    }

    private function currentMonday(): string
    {
        $dt  = new \DateTimeImmutable('today');
        $dow = (int) $dt->format('N');

        return $dt->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }
}
