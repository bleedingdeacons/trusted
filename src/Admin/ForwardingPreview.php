<?php

declare(strict_types=1);

namespace Trusted\Admin;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Trusted\Domain\ShiftTime;
use Trusted\Forwarding\ForwardingSchedule;
use Trusted\Forwarding\RotaForwardingProjection;

/**
 * Read-only call-flow view of a week's rota as a hunt group.
 *
 * Deliberately drawn like Tamar's forwarding overview (Tamar\Admin\
 * ForwardingOverview) — each day's forwarding steps in time order, the week
 * starting Monday, then the numbers they forward to — so a coordinator can
 * hold the two side by side and see what the rota would put into the hunt
 * group. The markup and styles are a copy rather than a dependency: Tamar is
 * optional, and this screen has to work without it.
 *
 * Where it departs from Tamar's view is the shifts that forward nowhere.
 * Tamar only knows about rows it has; the rota also knows about the shifts
 * that did not become a row — unassigned, or assigned to someone with no
 * number — and those are drawn in place as warnings, because a shift missing
 * from the hunt group is otherwise invisible.
 *
 * @phpstan-import-type SkippedShift from ForwardingSchedule
 */
final class ForwardingPreview
{
    /**
     * @param string $weekStart The Monday of the week shown (Y-m-d).
     */
    public function render(ForwardingSchedule $schedule, string $weekStart): void
    {
        $targetsById = [];
        foreach ($schedule->targets as $target) {
            $targetsById[$target->getId()] = $target;
        }

        echo '<div class="trusted-forwarding">';
        $this->styles();

        echo '<div class="trusted-forwarding__head">';
        echo '<h2>' . esc_html__('Call flow', 'trusted') . '</h2>';
        echo '<span class="trusted-forwarding__hint">' . esc_html__('By day, earliest first — overlapping windows ring in hunt order', 'trusted') . '</span>';
        echo '</div>';

        $this->renderSummary($schedule);

        if ($schedule->rules === [] && $schedule->skipped === []) {
            echo '<p class="trusted-forwarding__empty">'
                . esc_html__('No shifts on the rota this week — nothing would be forwarded.', 'trusted')
                . '</p>';
        } else {
            foreach ($this->groupByDay($schedule) as $day => $steps) {
                $this->renderDay($day, $weekStart, $steps, $targetsById);
            }
        }

        $this->renderTargets($schedule->targets);

        echo '</div>';
    }

    private function renderSummary(ForwardingSchedule $schedule): void
    {
        $steps   = count($schedule->rules);
        $skipped = count($schedule->skipped);

        echo '<p class="trusted-forwarding__sub">'
            . esc_html(sprintf(
                /* translators: %d: number of forwarding steps */
                _n('%d forwarding step', '%d forwarding steps', $steps, 'trusted'),
                $steps
            ));

        if ($skipped > 0) {
            echo ' · <span class="trusted-forwarding__warn">'
                . esc_html(sprintf(
                    /* translators: %d: number of shifts that forward nowhere */
                    _n('%d shift not forwarded', '%d shifts not forwarded', $skipped, 'trusted'),
                    $skipped
                ))
                . '</span>';
        }

        echo '</p>';
    }

    /**
     * Bucket rules and skipped shifts under their weekday, Monday first.
     *
     * Every day is present even when empty, so a day with no cover shows as
     * one. Within a day, entries sort by start time; usort is stable, so rules
     * with the same start keep hunt order and come before a skipped shift.
     *
     * @return array<string, list<ForwardingRule|SkippedShift>>
     */
    private function groupByDay(ForwardingSchedule $schedule): array
    {
        /** @var array<string, list<ForwardingRule|SkippedShift>> $groups */
        $groups = array_fill_keys(RotaForwardingProjection::DAYS, []);

        foreach ($schedule->rules as $rule) {
            foreach ($this->windowDays($rule) as $day) {
                if (isset($groups[$day])) {
                    $groups[$day][] = $rule;
                }
            }
        }

        foreach ($schedule->skipped as $shift) {
            if (isset($groups[$shift['day']])) {
                $groups[$shift['day']][] = $shift;
            }
        }

        foreach ($groups as $day => $steps) {
            usort($steps, fn (ForwardingRule|array $a, ForwardingRule|array $b): int
                => $this->startOf($a) <=> $this->startOf($b));
            $groups[$day] = $steps;
        }

        return $groups;
    }

    /**
     * @param list<ForwardingRule|SkippedShift>       $steps
     * @param array<string, ForwardingTarget>         $targetsById
     */
    private function renderDay(string $day, string $weekStart, array $steps, array $targetsById): void
    {
        echo '<section class="trusted-forwarding__day">';
        echo '<h3 class="trusted-forwarding__day-head">' . esc_html($this->dayHeading($day, $weekStart))
            . ' <span>(' . count($steps) . ')</span></h3>';

        if ($steps === []) {
            echo '<p class="trusted-forwarding__day-empty">' . esc_html__('Nothing forwarded on this day.', 'trusted') . '</p>';
            echo '</section>';
            return;
        }

        echo '<ol class="trusted-flow">';
        foreach ($steps as $step) {
            if ($step instanceof ForwardingRule) {
                $this->renderStep($step, $targetsById[$step->getTargetId()] ?? null);
            } else {
                $this->renderSkipped($step);
            }
        }
        echo '</ol>';
        echo '</section>';
    }

    private function renderStep(ForwardingRule $rule, ?ForwardingTarget $target): void
    {
        $label = $rule->getLabel() !== '' ? $rule->getLabel() : __('(unnamed rule)', 'trusted');

        echo '<li class="trusted-step">';
        echo '<span class="trusted-step__time">' . esc_html($this->describeWindow($rule)) . '</span>';
        echo '<div class="trusted-step__body">';

        echo '<div class="trusted-step__head">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '<span class="trusted-badge trusted-badge--on">' . esc_html__('Active', 'trusted') . '</span>';
        echo '</div>';

        echo '<div class="trusted-step__dest"><span class="dashicons dashicons-arrow-right-alt2"></span> '
            . $this->describeTarget($rule->getTargetId(), $target)
            . '</div>';

        echo '</div></li>';
    }

    /**
     * @param SkippedShift $shift
     */
    private function renderSkipped(array $shift): void
    {
        $title = $shift['member'] !== ''
            ? $shift['member']
            : ($shift['label'] !== '' ? $shift['label'] : __('Open shift', 'trusted'));

        echo '<li class="trusted-step trusted-step--gap">';
        echo '<span class="trusted-step__time">' . esc_html($shift['start'] . '–' . $shift['end']) . '</span>';
        echo '<div class="trusted-step__body">';

        echo '<div class="trusted-step__head">';
        echo '<strong>' . esc_html($title) . '</strong>';
        echo '<span class="trusted-badge trusted-badge--warn">' . esc_html__('Not forwarded', 'trusted') . '</span>';
        echo '</div>';

        echo '<div class="trusted-step__when"><span class="dashicons dashicons-warning"></span> '
            . esc_html($this->describeReason($shift['reason'])) . '</div>';

        echo '</div></li>';
    }

    private function describeReason(string $reason): string
    {
        return match ($reason) {
            ForwardingSchedule::UNASSIGNED     => __('Nobody is assigned to this shift.', 'trusted'),
            ForwardingSchedule::MEMBER_MISSING => __('The assigned member can no longer be found in Unity.', 'trusted'),
            ForwardingSchedule::NO_TELEPHONE   => __('The assigned member has no telephone number.', 'trusted'),
            default                            => $reason,
        };
    }

    /**
     * Render a rule's destination, falling back to the raw target id so
     * nothing is silently dropped.
     */
    private function describeTarget(string $targetId, ?ForwardingTarget $target): string
    {
        if ($target === null) {
            return '<span class="trusted-target trusted-target--unknown"><code>'
                . esc_html($targetId) . '</code></span>';
        }

        $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();

        $out = '<span class="trusted-target">';
        $out .= '<span class="dashicons dashicons-phone"></span> ';
        $out .= '<strong>' . esc_html($label) . '</strong>';
        if ($target->getAddress() !== '') {
            $out .= ' <span class="trusted-target__addr">' . esc_html($target->getAddress()) . '</span>';
        }
        $out .= ' <span class="trusted-target__kind">' . esc_html($target->getKind()) . '</span>';
        $out .= '</span>';

        return $out;
    }

    /**
     * A rule's time of day, e.g. "10:00–14:00", with the end of the day shown
     * as 24:00 the way the rest of Trusted shows it.
     */
    private function describeWindow(ForwardingRule $rule): string
    {
        $value = $this->windowValue($rule);

        return sprintf(
            '%s–%s',
            (string) ($value['from'] ?? '00:00'),
            ShiftTime::toShown((string) ($value['to'] ?? ShiftTime::LAST_MINUTE))
        );
    }

    /**
     * Sort key for a step: HH:MM compares correctly as a string.
     *
     * @param ForwardingRule|SkippedShift $step
     */
    private function startOf(ForwardingRule|array $step): string
    {
        if (is_array($step)) {
            return $step['start'];
        }

        return (string) ($this->windowValue($step)['from'] ?? '00:00');
    }

    /** @return array<mixed> */
    private function windowValue(ForwardingRule $rule): array
    {
        $match = $rule->getMatch();
        return is_array($match['value'] ?? null) ? $match['value'] : [];
    }

    /** @return list<string> */
    private function windowDays(ForwardingRule $rule): array
    {
        $days = $this->windowValue($rule)['days'] ?? null;
        return is_array($days) ? array_values(array_map('strval', $days)) : [];
    }

    /** "Monday 21 September" for a day code within the week starting $weekStart. */
    private function dayHeading(string $day, string $weekStart): string
    {
        $name = match ($day) {
            'mon'   => __('Monday', 'trusted'),
            'tue'   => __('Tuesday', 'trusted'),
            'wed'   => __('Wednesday', 'trusted'),
            'thu'   => __('Thursday', 'trusted'),
            'fri'   => __('Friday', 'trusted'),
            'sat'   => __('Saturday', 'trusted'),
            default => __('Sunday', 'trusted'),
        };

        $monday = \DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart);
        $offset = (int) array_search($day, RotaForwardingProjection::DAYS, true);

        if ($monday === false) {
            return $name;
        }

        return $name . ' ' . $monday->modify('+' . $offset . ' days')->format('j F');
    }

    /** @param list<ForwardingTarget> $targets */
    private function renderTargets(array $targets): void
    {
        echo '<h2 class="trusted-forwarding__targets-head">' . esc_html__('Numbers forwarded to', 'trusted') . '</h2>';

        if ($targets === []) {
            echo '<p class="trusted-forwarding__empty">' . esc_html__('No responder numbers this week.', 'trusted') . '</p>';
            return;
        }

        usort($targets, static fn (ForwardingTarget $a, ForwardingTarget $b): int
            => strcasecmp($a->getLabel(), $b->getLabel()));

        echo '<div class="trusted-targets"><div class="trusted-targets__group">';
        echo '<div class="trusted-targets__kind">' . esc_html__('Responders', 'trusted')
            . ' <span>(' . count($targets) . ')</span></div>';
        echo '<ul>';
        foreach ($targets as $target) {
            $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();
            echo '<li><strong>' . esc_html($label) . '</strong>';
            if ($target->getAddress() !== '') {
                echo ' <span class="trusted-target__addr">' . esc_html($target->getAddress()) . '</span>';
            }
            echo '</li>';
        }
        echo '</ul></div></div>';
    }

    /**
     * Scoped inline styles, as Tamar's overview does it, so the view is one
     * self-contained drop-in. Everything is namespaced under
     * .trusted-forwarding and uses WP admin colour cues.
     */
    private function styles(): void
    {
        echo '<style>
.trusted-forwarding{max-width:760px;}
.trusted-forwarding__head{display:flex;align-items:baseline;justify-content:space-between;gap:1em;flex-wrap:wrap;}
.trusted-forwarding__head h2{margin:0;}
.trusted-forwarding__hint{color:#646970;font-size:13px;}
.trusted-forwarding__sub{color:#646970;margin:.25em 0 1em;}
.trusted-forwarding__warn{color:#996800;font-weight:600;}
.trusted-forwarding__targets-head{margin-top:1.75em;}
.trusted-forwarding__day{margin-bottom:1.25em;}
.trusted-forwarding__day-head{font-size:14px;margin:0 0 .5em;}
.trusted-forwarding__day-head span{color:#8c8f94;font-weight:400;}
.trusted-forwarding__day-empty{color:#646970;font-style:italic;margin:0;padding:.6em 1em;border:1px dashed #dcdcde;border-radius:8px;}
.trusted-forwarding__empty{color:#646970;}
.trusted-flow{list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:10px;}
.trusted-step{display:flex;gap:12px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:.85em 1em;}
.trusted-step--gap{background:#fcf9e8;border-color:#f0c33c;}
.trusted-step__time{flex:0 0 96px;font-family:Consolas,Monaco,monospace;font-size:13px;font-weight:600;color:#1d2327;padding-top:2px;}
.trusted-step__body{flex:1;min-width:0;}
.trusted-step__head{display:flex;align-items:center;gap:8px;margin-bottom:6px;}
.trusted-step__head strong{font-size:15px;}
.trusted-step__dest{display:flex;align-items:center;flex-wrap:wrap;gap:4px;font-size:14px;margin-bottom:4px;}
.trusted-step__when{display:flex;align-items:center;gap:5px;color:#646970;font-size:13px;}
.trusted-step .dashicons{color:#646970;width:18px;height:18px;font-size:18px;}
.trusted-step--gap .trusted-step__when .dashicons{color:#996800;}
.trusted-target{display:inline-flex;align-items:center;flex-wrap:wrap;gap:5px;}
.trusted-target__addr{font-family:Consolas,Monaco,monospace;font-size:12px;color:#646970;}
.trusted-target__kind{font-size:11px;padding:1px 7px;border-radius:999px;background:#f0f0f1;color:#646970;}
.trusted-target--unknown code{font-size:12px;}
.trusted-badge{font-size:11px;padding:2px 8px;border-radius:999px;}
.trusted-badge--on{background:#edfaef;color:#0a7d33;}
.trusted-badge--warn{background:#fcf0c3;color:#996800;}
.trusted-targets{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;}
.trusted-targets__group{background:#f6f7f7;border-radius:6px;padding:.85em 1em;}
.trusted-targets__kind{font-size:13px;color:#646970;margin-bottom:8px;}
.trusted-targets__kind span{color:#8c8f94;}
.trusted-targets__group ul{margin:0;padding:0;list-style:none;}
.trusted-targets__group li{padding:3px 0;font-size:14px;}
@media (max-width:600px){.trusted-step{flex-direction:column;gap:4px;}.trusted-step__time{flex:none;}}
</style>';
    }
}
