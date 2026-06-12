<?php

declare(strict_types=1);

namespace Trusted\Template;

use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Rota;
use Trusted\Support\ResponderDirectory;

/**
 * Turns a weekly template (ACF post) into concrete Rota slots for a given week,
 * and — in the other direction — captures an existing week back into a template.
 *
 * A template line may name a member to pre-assign; when the template is applied
 * that member is resolved through {@see ResponderDirectory} and assigned to the
 * newly created slot. Names are validated when the template is saved (see
 * TemplateValidator), so a name that fails to resolve here means the member was
 * deleted or un-flagged since — in which case the slot is simply left empty.
 */
final class TemplateApplicator
{
    public function __construct(
        private RotaRepositoryInterface $rota,
        private RotaFactoryInterface $rotaFactory,
        private AssignmentRepositoryInterface $assignments,
        private AssignmentFactoryInterface $assignmentFactory,
        private ResponderDirectory $responders,
        private TemplateParser $parser,
    ) {
    }

    /**
     * Available templates as id => title, for selection in the UI.
     *
     * @return array<int, string>
     */
    public function options(): array
    {
        $posts = get_posts([
            'post_type'      => \TRUSTED_TEMPLATE_POST_TYPE,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ]);

        $out = [];

        foreach ($posts as $post) {
            $out[(int) $post->ID] = get_the_title($post) ?: sprintf(
                /* translators: %d: template post ID */
                __('Template #%d', 'trusted'),
                $post->ID
            );
        }

        return $out;
    }

    /**
     * Parse a template into its per-weekday shifts.
     *
     * @return array<int, \Trusted\Domain\Shift[]> Keyed by ISO weekday (1 = Mon … 7 = Sun).
     */
    public function shiftsForTemplate(int $templateId): array
    {
        $byDay = [];

        foreach (TemplateFields::DAY_FIELDS as $field => $weekday) {
            $raw             = $this->fieldValue($templateId, $field);
            $byDay[$weekday] = $this->parser->parse($raw);
        }

        return $byDay;
    }

    /**
     * Apply a template to the 7 days starting at $weekStart (a Monday, Y-m-d).
     *
     * @return Rota[] The slots that were created.
     */
    public function apply(int $templateId, string $weekStart, bool $replace = false): array
    {
        if ($replace) {
            $this->rota->deleteWeek($weekStart);
        }

        // Existing slots in this week, keyed by date+start+end. Applying a
        // template never overwrites or duplicates these — their names and
        // assignments are left untouched; only genuinely new shifts are added.
        $existing = [];
        foreach ($this->rota->findForWeek($weekStart) as $slot) {
            $existing[$this->slotKey($slot->slotDate(), $slot->startTime(), $slot->endTime())] = true;
        }

        $shiftsByDay = $this->shiftsForTemplate($templateId);
        $monday      = $this->date($weekStart);
        $created     = [];

        foreach ($shiftsByDay as $weekday => $shifts) {
            // $weekday: 1..7 → offset 0..6 from Monday.
            $date = $monday->modify('+' . ($weekday - 1) . ' days')->format('Y-m-d');

            foreach ($shifts as $shift) {
                $key = $this->slotKey($date, $shift->startTime(), $shift->endTime());

                // Skip a shift that already exists on this date/time — keep the
                // current slot (and its name) exactly as it is.
                if (isset($existing[$key])) {
                    continue;
                }

                // Names are required and enforced at save time; this fallback
                // only guards a legacy template saved before that rule, so it
                // never writes a nameless slot.
                $label = $shift->label() !== ''
                    ? $shift->label()
                    : $shift->startTime() . '–' . $shift->endTime();

                $slot = $this->rotaFactory->create(
                    slotDate: $date,
                    startTime: $shift->startTime(),
                    endTime: $shift->endTime(),
                    label: $label,
                    templateId: $templateId,
                );

                $savedSlot      = $this->rota->save($slot);
                $created[]      = $savedSlot;
                $existing[$key] = true; // Guard against duplicate lines within the template too.

                $this->assignNamedMember($savedSlot, $shift->member());
            }
        }

        return $created;
    }

    /**
     * Capture the given week's slots as a new template post.
     *
     * Each slot becomes a line under its weekday field: "HH:MM-HH:MM | label",
     * plus " | member name" when $includeMembers is set and the slot has an
     * assigned member. Returns the new template id, or 0 on failure.
     */
    public function createFromWeek(string $weekStart, string $title, bool $includeMembers): int
    {
        $title = trim($title);

        if ($title === '') {
            return 0;
        }

        // Bucket each slot into its weekday field, preserving the week's order
        // (findForWeek already sorts by date then start time).
        $fieldByWeekday = array_flip(TemplateFields::DAY_FIELDS); // weekday => field name
        $linesByField   = array_fill_keys(array_keys(TemplateFields::DAY_FIELDS), []);

        foreach ($this->rota->findForWeek($weekStart) as $slot) {
            $weekday = (int) $this->date($slot->slotDate())->format('N');
            $field   = $fieldByWeekday[$weekday] ?? null;

            if ($field === null) {
                continue;
            }

            $linesByField[$field][] = $this->serialiseSlot($slot, $includeMembers);
        }

        $postId = wp_insert_post([
            'post_type'   => \TRUSTED_TEMPLATE_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => $title,
        ], true);

        if (is_wp_error($postId) || ! $postId) {
            return 0;
        }

        foreach ($linesByField as $field => $lines) {
            $this->writeFieldValue((int) $postId, $field, implode("\n", $lines));
        }

        return (int) $postId;
    }

    /**
     * Assign the named member to a freshly created slot, if they still resolve
     * to a telephone responder. A missing/un-flagged member is skipped silently,
     * leaving the slot empty.
     */
    private function assignNamedMember(Rota $slot, string $memberName): void
    {
        if ($memberName === '' || $slot->id() === null) {
            return;
        }

        $responder = $this->responders->findResponder($memberName);

        if ($responder === null) {
            return;
        }

        $this->assignments->save(
            $this->assignmentFactory->create((int) $slot->id(), (string) $responder->getId())
        );
    }

    /**
     * Build the template line for a slot.
     */
    private function serialiseSlot(Rota $slot, bool $includeMembers): string
    {
        $line = $slot->startTime() . '-' . $slot->endTime()
            . ' | ' . $this->sanitiseSegment($slot->label());

        if ($includeMembers) {
            $member = $this->assignedMemberName($slot);

            if ($member !== '') {
                $line .= ' | ' . $this->sanitiseSegment($member);
            }
        }

        return $line;
    }

    /**
     * The anonymous name of the slot's assigned member, or '' if none/unresolved.
     */
    private function assignedMemberName(Rota $slot): string
    {
        foreach ($slot->assignments() as $assignment) {
            $member = $assignment->member();

            if ($member !== null && $member->name() !== '') {
                return $member->name();
            }
        }

        return '';
    }

    /**
     * Strip pipes and newlines from a serialised segment so the generated line
     * round-trips cleanly back through the parser.
     */
    private function sanitiseSegment(string $value): string
    {
        return trim(str_replace(['|', "\r", "\n"], ' ', $value));
    }

    private function slotKey(string $date, string $start, string $end): string
    {
        return $date . '|' . $start . '|' . $end;
    }

    private function fieldValue(int $postId, string $field): string
    {
        if (function_exists('get_field')) {
            return (string) get_field($field, $postId);
        }

        // Fallback if ACF is unavailable: ACF stores the value under the bare
        // meta key as well.
        return (string) get_post_meta($postId, $field, true);
    }

    private function writeFieldValue(int $postId, string $field, string $value): void
    {
        if (function_exists('update_field')) {
            // Address the field by key so ACF records the field reference on a
            // brand-new post (it shows correctly on the edit screen).
            update_field(TemplateFields::fieldKey($field), $value, $postId);

            return;
        }

        update_post_meta($postId, $field, $value);
    }

    private function date(string $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            ?: new \DateTimeImmutable('today');
    }
}
