<?php

declare(strict_types=1);

namespace Trusted\Template;

use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Shift;

/**
 * Turns a weekly template (ACF post) into concrete Rota slots for a given week.
 */
final class TemplateApplicator
{
    public function __construct(
        private RotaRepositoryInterface $rota,
        private RotaFactoryInterface $rotaFactory,
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
     * @return array<int, Shift[]> Keyed by ISO weekday (1 = Mon … 7 = Sun).
     */
    public function shiftsForTemplate(int $templateId): array
    {
        $byDay = [];

        foreach (TemplateFields::DAY_FIELDS as $field => $weekday) {
            $raw            = $this->fieldValue($templateId, $field);
            $byDay[$weekday] = $this->parseShifts($raw);
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

                $slot = $this->rotaFactory->create(
                    slotDate: $date,
                    startTime: $shift->startTime(),
                    endTime: $shift->endTime(),
                    label: $shift->label(),
                    templateId: $templateId,
                );

                $created[]      = $this->rota->save($slot);
                $existing[$key] = true; // Guard against duplicate lines within the template too.
            }
        }

        return $created;
    }

    private function slotKey(string $date, string $start, string $end): string
    {
        return $date . '|' . $start . '|' . $end;
    }

    /**
     * Parse textarea content into Shift objects, skipping unparseable lines.
     *
     * Accepted line forms (whitespace-tolerant):
     *   09:00-17:00
     *   09:00 - 17:00 | Reception
     *   9:00-17:00 Reception
     *
     * @return Shift[]
     */
    private function parseShifts(string $raw): array
    {
        $shifts = [];

        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Capture two HH:MM times, then an optional label after - / – / |.
            if (! preg_match(
                '/^(\d{1,2}:\d{2})\s*[-–]\s*(\d{1,2}:\d{2})\s*(?:[|–-]\s*)?(.*)$/u',
                $line,
                $m
            )) {
                continue;
            }

            $start = $this->padTime($m[1]);
            $end   = $this->padTime($m[2]);
            $label = trim($m[3] ?? '');

            // A shift always has a name. If a template line omits one, fall back
            // to its time range rather than producing an unnamed slot.
            if ($label === '') {
                $label = $start . '–' . $end;
            }

            $shifts[] = new Shift($start, $end, $label);
        }

        return $shifts;
    }

    private function padTime(string $time): string
    {
        [$h, $min] = array_pad(explode(':', $time), 2, '00');

        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . str_pad($min, 2, '0', STR_PAD_LEFT);
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

    private function date(string $date): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $date)
            ?: new \DateTimeImmutable('today');
    }
}
