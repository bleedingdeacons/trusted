<?php

declare(strict_types=1);

namespace Trusted\Admin;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Trusted\Http\ForwardingCheckController;
use Trusted\Http\RestController;
use Trusted\Support\Week;

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
            // Where calendar.js asks for a fresh nonce when this one expires.
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'weekStart' => Week::currentMonday(),
            'startDow'  => (int) get_option('start_of_week', 1), // 0 = Sun, 1 = Mon
            // Whether Tamar can be asked for the current week's hunt group.
            'forwardingCheck' => ForwardingCheckController::available(),
            'i18n'      => [
                'assign'        => __('Assign', 'trusted'),
                'selectMember'  => __('Select Member', 'trusted'),
                'select'        => __('Select', 'trusted'),
                'addShift'      => __('Add Shift', 'trusted'),
                'applyTemplate' => __('Apply template', 'trusted'),
                'selectTemplate' => __('Select Template', 'trusted'),
                'replace'       => __('Replace existing slots this week', 'trusted'),
                'prevWeek'      => __('← Previous', 'trusted'),
                'nextWeek'      => __('Next →', 'trusted'),
                'today'         => __('This week', 'trusted'),
                /* translators: %d: ISO-8601 week number, 1–53. */
                'weekNumber'    => __('Week %d', 'trusted'),
                'remove'        => __('Remove', 'trusted'),
                'confirmRemove' => __('Are you sure?', 'trusted'),
                'bulkAssign'    => __('Assign member to shifts', 'trusted'),
                'bulkHint'      => __('Pick a member, tick the empty shifts to fill, then Assign.', 'trusted'),
                'oneSelected'   => __('1 shift selected', 'trusted'),
                /* translators: %d: number of shifts selected. */
                'manySelected'  => __('%d shifts selected', 'trusted'),
                /* translators: %d: number of shifts that were already filled and skipped. */
                'bulkSkipped'   => __('%d shift(s) were already filled and left unchanged.', 'trusted'),
                'noTemplates'   => __('No templates yet', 'trusted'),
                'unassigned'    => __('Unassigned', 'trusted'),
                /* translators: shown between two shifts with uncovered time, e.g. "Gap 13:00–14:00". */
                'gap'           => __('Gap', 'trusted'),
                'gapAddHint'    => __('Double-click to add a shift for this gap', 'trusted'),
                'gapLocked'     => __('Before the rota starts: no shift on the previous day runs past 24:00.', 'trusted'),
                'saveAsTemplate'       => __('Save week as template', 'trusted'),
                'templateName'         => __('Template name', 'trusted'),
                'includeMembers'       => __('Include assigned members', 'trusted'),
                'templateNameRequired' => __('Please enter a template name.', 'trusted'),
                /* translators: %s: the new template's name. */
                'templateSaved'        => __('Saved as template "%s".', 'trusted'),
                'clearWeek'            => __('Clear week', 'trusted'),
                'confirmClearWeek'     => __('Delete all shifts for this week? This cannot be undone.', 'trusted'),
                'clearAssignments'        => __('Delete week\'s assignments', 'trusted'),
                'confirmClearAssignments' => __('Remove every member assignment for this week? The shifts will stay.', 'trusted'),
                'delete'        => __('Delete', 'trusted'),
                'addingShift'   => __('Adding Shift', 'trusted'),
                'memberOptional' => __('Member (optional)', 'trusted'),
                'newSlotStart'  => __('Start', 'trusted'),
                'newSlotEnd'    => __('End', 'trusted'),
                'newSlotLabel'  => __('Shift name', 'trusted'),
                'nameRequired'  => __('Please enter a shift name.', 'trusted'),
                'invalidTime'   => __('Enter times as HH:MM, between 00:00 and 24:00.', 'trusted'),
                'save'          => __('Save', 'trusted'),
                'cancel'        => __('Cancel', 'trusted'),
                'checkForwarding'     => __('Check call forwarding', 'trusted'),
                'checkingForwarding'  => __('Checking Tamar…', 'trusted'),
                'checkForwardingHint' => __('Compare Tamar\'s hunt group for this week with the rota. Nothing in Tamar is changed.', 'trusted'),
                'checkCurrentOnly'    => __('Only the current week\'s forwarding can be checked. Go to This week to check it.', 'trusted'),
                'dismiss'             => __('Dismiss this notice.', 'trusted'),
                'syncForwarding'      => __('Sync to Tamar', 'trusted'),
                'syncingForwarding'   => __('Syncing…', 'trusted'),
                /* translators: %s: hunt group name, e.g. "Forward Week 40". */
                'sessionExpired'      => __('Your WordPress session has expired. Reload the page and log in again.', 'trusted'),
                'confirmSync'         => __('Write this week\'s rota to Tamar as "%s"? Every row in that hunt group is replaced, and it becomes the group Tamar\'s Overview shows.', 'trusted'),
            ],
        ]);
    }
}
