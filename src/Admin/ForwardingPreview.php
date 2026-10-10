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
 * One table per day, the week starting Monday, each day's forwarding steps
 * in time order — start, end, who, where the calls go, and whether the
 * shift is filled — then the targets they forward to grouped by kind. The
 * table is styled like Tamar's own (Tamar\Admin\ForwardingOverview), so a
 * coordinator can hold the two side by side and see what the rota would put
 * into the hunt group. The styles are a copy rather than a dependency: Tamar
 * is optional, and this screen has to work without it.
 *
 * Where it departs from Tamar's view is the shifts that fall to voicemail.
 * To Tamar a voicemail row is just a row; the rota knows why it is there —
 * nobody assigned, or someone who cannot be reached — and those rows are
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

        echo '<div class="trusted-flow-wrap"><table class="trusted-flow">';
        echo '<thead><tr>'
            . '<th class="trusted-flow__time">' . esc_html__('Start time', 'trusted') . '</th>'
            . '<th class="trusted-flow__time">' . esc_html__('End time', 'trusted') . '</th>'
            . '<th>' . esc_html__('Responder', 'trusted') . '</th>'
            . '<th>' . esc_html__('Divert calls to', 'trusted') . '</th>'
            . '<th>' . esc_html__('Status', 'trusted') . '</th>'
            . '</tr></thead><tbody>';
        foreach ($rules as $rule) {
            $this->renderStep($rule, $targetsById[$rule->getTargetId()] ?? null, $schedule->unfilledFor($rule));
        }
        echo '</tbody></table></div>';
        echo '</section>';
    }

    /**
     * One row of a day's table.
     *
     * @param UnfilledShift|null $unfilled Why the rule forwards to voicemail,
     *                                     or null when it forwards to a person.
     */
    private function renderStep(ForwardingRule $rule, ?ForwardingTarget $target, ?array $unfilled): void
    {
        $label = $rule->getLabel() !== '' ? $rule->getLabel() : __('(unnamed rule)', 'trusted');
        $value = $this->windowValue($rule);

        echo '<tr class="trusted-step' . ($unfilled !== null ? ' trusted-step--gap' : '') . '">';
        echo '<td class="trusted-flow__time">' . esc_html((string) ($value['from'] ?? '00:00')) . '</td>';
        echo '<td class="trusted-flow__time">' . esc_html((string) ($value['to'] ?? '23:59')) . '</td>';
        echo '<td><strong>' . esc_html($label) . '</strong></td>';
        echo '<td>' . $this->describeTarget($rule->getTargetId(), $target) . '</td>';

        echo '<td>';
        if ($unfilled !== null) {
            echo '<span class="trusted-badge trusted-badge--warn">' . esc_html__('Unfilled', 'trusted') . '</span>'
                . '<div class="trusted-step__why">' . esc_html($this->describeReason($unfilled['reason'], $unfilled['member'])) . '</div>';
        } else {
            echo '<span class="trusted-badge trusted-badge--on">' . esc_html__('Active', 'trusted') . '</span>';
        }
        echo '</td>';

        echo '</tr>';
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

        // The responder has a column of its own, so a number shows as just
        // the number; voicemail has no number, so it shows by name.
        $voicemail = $target->getKind() === ForwardingTarget::KIND_VOICEMAIL;
        $icon      = $voicemail ? 'dashicons-microphone' : 'dashicons-phone';
        $text      = ! $voicemail && $target->getAddress() !== ''
            ? '<span class="trusted-target__addr">' . esc_html($target->getAddress()) . '</span>'
            : esc_html($target->getLabel() !== '' ? $target->getLabel() : $target->getId());

        return '<span class="trusted-target"><span class="dashicons ' . esc_attr($icon) . '"></span> ' . $text . '</span>';
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
.trusted-flow-wrap{overflow-x:auto;background:#fff;border:1px solid #d9dbe6;border-radius:10px;}
.trusted-flow{border-collapse:collapse;width:100%;font-size:14px;}
.trusted-flow th{font-weight:700;text-align:left;vertical-align:bottom;padding:8px 12px;border-bottom:1px solid #dee2e6;white-space:nowrap;}
.trusted-flow td{padding:8px 12px;border-bottom:1px solid #dee2e6;vertical-align:top;}
.trusted-flow tbody tr:last-child td{border-bottom:0;}
.trusted-flow__time{width:80px;font-variant-numeric:tabular-nums;white-space:nowrap;}
td.trusted-flow__time{font-family:Consolas,Monaco,monospace;font-weight:600;color:#1d2327;}
.trusted-step--gap td{background:#fcf9e8;}
.trusted-step__why{color:#646970;font-size:13px;margin-top:4px;}
.trusted-step .dashicons{color:#646970;width:18px;height:18px;font-size:18px;}
.trusted-step--gap .dashicons-microphone{color:#996800;}
.trusted-target{display:inline-flex;align-items:center;gap:5px;white-space:nowrap;}
.trusted-target__addr{font-family:Consolas,Monaco,monospace;font-size:13px;}
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
</style>';
    }
}
