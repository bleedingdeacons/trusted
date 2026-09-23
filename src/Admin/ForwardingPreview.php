<?php

declare(strict_types=1);

namespace Trusted\Admin;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;
use Trusted\Forwarding\ForwardingSchedule;
use Trusted\Forwarding\RotaForwardingProjection;

/**
 * Read-only call-flow view of a week's rota as a hunt group.
 *
 * Deliberately drawn like Tamar's forwarding overview (Tamar\Admin\
 * ForwardingOverview) — each day's forwarding steps in time order, the week
 * starting Monday, then the targets they forward to grouped by kind — so a
 * coordinator can hold the two side by side and see what the rota would put
 * into the hunt group. The markup and styles are a copy rather than a
 * dependency: Tamar is optional, and this screen has to work without it.
 *
 * Where it departs from Tamar's view is the shifts that fall to voicemail.
 * To Tamar a voicemail row is just a row; the rota knows why it is there —
 * nobody assigned, or someone who cannot be reached — and those steps are
 * drawn as warnings with the reason, because an unfilled shift is the thing a
 * coordinator most needs to see.
 *
 * Times are shown as the forwarding system holds them, 00:00–23:59, rather
 * than with the 24:00 the calendar uses for the end of the day.
 *
 * @phpstan-import-type UnfilledShift from ForwardingSchedule
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

        if ($schedule->rules === []) {
            echo '<p class="trusted-forwarding__empty">'
                . esc_html__('No shifts on the rota this week — nothing would be forwarded.', 'trusted')
                . '</p>';
        } else {
            foreach ($this->groupByDay($schedule->rules) as $day => $rules) {
                $this->renderDay($day, $weekStart, $rules, $targetsById, $schedule);
            }
        }

        $this->renderTargets($schedule->targets);

        echo '</div>';
    }

    private function renderSummary(ForwardingSchedule $schedule): void
    {
        $steps     = count($schedule->rules);
        $voicemail = count($schedule->unfilled);

        echo '<p class="trusted-forwarding__sub">'
            . esc_html(sprintf(
                /* translators: %d: number of forwarding steps */
                _n('%d forwarding step', '%d forwarding steps', $steps, 'trusted'),
                $steps
            ));

        if ($voicemail > 0) {
            echo ' · <span class="trusted-forwarding__warn">'
                . esc_html(sprintf(
                    /* translators: %d: number of steps forwarding to voicemail because their shift is unfilled */
                    _n('%d unfilled, to voicemail', '%d unfilled, to voicemail', $voicemail, 'trusted'),
                    $voicemail
                ))
                . '</span>';
        }

        echo '</p>';
    }

    /**
     * Bucket rules under their weekday, Monday first.
     *
     * Every day is present even when empty, so a day with no cover shows as
     * one. Within a day, rules sort by start time; usort is stable, so rules
     * with the same start keep hunt order.
     *
     * @param list<ForwardingRule> $rules In hunt order.
     * @return array<string, list<ForwardingRule>>
     */
    private function groupByDay(array $rules): array
    {
        /** @var array<string, list<ForwardingRule>> $groups */
        $groups = array_fill_keys(RotaForwardingProjection::DAYS, []);

        foreach ($rules as $rule) {
            foreach ($this->windowDays($rule) as $day) {
                if (isset($groups[$day])) {
                    $groups[$day][] = $rule;
                }
            }
        }

        foreach ($groups as $day => $dayRules) {
            usort($dayRules, fn (ForwardingRule $a, ForwardingRule $b): int
                => $this->windowFrom($a) <=> $this->windowFrom($b));
            $groups[$day] = $dayRules;
        }

        return $groups;
    }

    /**
     * @param list<ForwardingRule>            $rules
     * @param array<string, ForwardingTarget> $targetsById
     */
    private function renderDay(
        string $day,
        string $weekStart,
        array $rules,
        array $targetsById,
        ForwardingSchedule $schedule,
    ): void {
        echo '<section class="trusted-forwarding__day">';
        echo '<h3 class="trusted-forwarding__day-head">' . esc_html($this->dayHeading($day, $weekStart))
            . ' <span>(' . count($rules) . ')</span></h3>';

        if ($rules === []) {
            echo '<p class="trusted-forwarding__day-empty">' . esc_html__('Nothing forwarded on this day.', 'trusted') . '</p>';
            echo '</section>';
            return;
        }

        echo '<ol class="trusted-flow">';
        foreach ($rules as $rule) {
            $this->renderStep($rule, $targetsById[$rule->getTargetId()] ?? null, $schedule->unfilledFor($rule));
        }
        echo '</ol>';
        echo '</section>';
    }

    /**
     * @param UnfilledShift|null $unfilled Why the rule forwards to voicemail,
     *                                     or null when it forwards to a person.
     */
    private function renderStep(ForwardingRule $rule, ?ForwardingTarget $target, ?array $unfilled): void
    {
        $label = $rule->getLabel() !== '' ? $rule->getLabel() : __('(unnamed rule)', 'trusted');

        echo '<li class="trusted-step' . ($unfilled !== null ? ' trusted-step--gap' : '') . '">';
        echo '<span class="trusted-step__time">' . esc_html($this->describeWindow($rule)) . '</span>';
        echo '<div class="trusted-step__body">';

        echo '<div class="trusted-step__head">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo $unfilled !== null
            ? '<span class="trusted-badge trusted-badge--warn">' . esc_html__('Unfilled', 'trusted') . '</span>'
            : '<span class="trusted-badge trusted-badge--on">' . esc_html__('Active', 'trusted') . '</span>';
        echo '</div>';

        echo '<div class="trusted-step__dest"><span class="dashicons dashicons-arrow-right-alt2"></span> '
            . $this->describeTarget($rule->getTargetId(), $target)
            . '</div>';

        if ($unfilled !== null) {
            echo '<div class="trusted-step__when"><span class="dashicons dashicons-warning"></span> '
                . esc_html($this->describeReason($unfilled['reason'], $unfilled['member'])) . '</div>';
        }

        echo '</div></li>';
    }

    private function describeReason(string $reason, string $member): string
    {
        return match ($reason) {
            ForwardingSchedule::UNASSIGNED     => __('Nobody is assigned to this shift.', 'trusted'),
            ForwardingSchedule::MEMBER_MISSING => __('The assigned member can no longer be found in Unity.', 'trusted'),
            ForwardingSchedule::NO_TELEPHONE   => sprintf(
                /* translators: %s: the assigned member's anonymous name */
                __('%s is assigned but has no telephone number.', 'trusted'),
                $member
            ),
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

        $icon  = $target->getKind() === ForwardingTarget::KIND_VOICEMAIL ? 'dashicons-microphone' : 'dashicons-phone';
        $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();

        $out = '<span class="trusted-target">';
        $out .= '<span class="dashicons ' . esc_attr($icon) . '"></span> ';
        $out .= '<strong>' . esc_html($label) . '</strong>';
        if ($target->getAddress() !== '') {
            $out .= ' <span class="trusted-target__addr">' . esc_html($target->getAddress()) . '</span>';
        }
        $out .= ' <span class="trusted-target__kind">' . esc_html($target->getKind()) . '</span>';
        $out .= '</span>';

        return $out;
    }

    /**
     * A rule's time of day as the forwarding system holds it, e.g.
     * "10:00–14:00" or "18:00–23:59".
     */
    private function describeWindow(ForwardingRule $rule): string
    {
        $value = $this->windowValue($rule);

        return sprintf('%s–%s', (string) ($value['from'] ?? '00:00'), (string) ($value['to'] ?? '23:59'));
    }

    /** Sort key for a rule: HH:MM compares correctly as a string. */
    private function windowFrom(ForwardingRule $rule): string
    {
        return (string) ($this->windowValue($rule)['from'] ?? '00:00');
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
        echo '<h2 class="trusted-forwarding__targets-head">' . esc_html__('Forwarded to', 'trusted') . '</h2>';

        if ($targets === []) {
            echo '<p class="trusted-forwarding__empty">' . esc_html__('Nothing is forwarded this week.', 'trusted') . '</p>';
            return;
        }

        $byKind = [];
        foreach ($targets as $target) {
            $byKind[$target->getKind()][] = $target;
        }
        ksort($byKind);

        echo '<div class="trusted-targets">';
        foreach ($byKind as $kind => $group) {
            usort($group, static fn (ForwardingTarget $a, ForwardingTarget $b): int
                => strcasecmp($a->getLabel(), $b->getLabel()));

            $heading = match ((string) $kind) {
                ForwardingTarget::KIND_NUMBER    => __('Responders', 'trusted'),
                ForwardingTarget::KIND_VOICEMAIL => __('Voicemail', 'trusted'),
                default                          => ucfirst((string) $kind),
            };

            echo '<div class="trusted-targets__group">';
            echo '<div class="trusted-targets__kind">' . esc_html($heading)
                . ' <span>(' . count($group) . ')</span></div>';
            echo '<ul>';
            foreach ($group as $target) {
                $label = $target->getLabel() !== '' ? $target->getLabel() : $target->getId();
                echo '<li><strong>' . esc_html($label) . '</strong>';
                if ($target->getAddress() !== '') {
                    echo ' <span class="trusted-target__addr">' . esc_html($target->getAddress()) . '</span>';
                }
                echo '</li>';
            }
            echo '</ul></div>';
        }
        echo '</div>';
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
