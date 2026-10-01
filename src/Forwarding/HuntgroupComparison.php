<?php

declare(strict_types=1);

namespace Trusted\Forwarding;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Trusted\Domain\ShiftTime;

/**
 * Compares the rules a week of the rota projects to with the rules a hunt
 * group actually holds, and lists where they disagree.
 *
 * What is compared is where a call goes, and nothing else. Each rule is
 * broken into one window per weekday it is ticked for — a hand-made row
 * ticked Monday to Friday is five windows — and windows are matched on
 * day, start and end. For each matched pair the destination must agree and
 * the hunt group's row must be switched on. Labels (the Comment column),
 * ring timeouts and row order are not compared: they change nothing about
 * who a call reaches, and row order only matters where windows overlap,
 * which a rota's do not.
 *
 * Destinations are compared as Tamar identifies them: any voicemail box is
 * voicemail, and a number is its digits, so spacing never counts as a
 * difference. A number written internationally (+44 or 0044) is read as
 * its national form, so "+44 7700 900123" is the same as "07700 900123".
 *
 * A switched-off row the rota has no window for is ignored; it forwards
 * nothing.
 *
 * Pure: nothing here reads WordPress or talks to a forwarding driver.
 *
 * @phpstan-type Window array{day: string, from: string, to: string, destination: string, label: string, enabled: bool}
 * @phpstan-type Side array{destination: string, label: string}
 * @phpstan-type Difference array{kind: string, day: string, from: string, to: string, expected: Side|null, actual: Side|null}
 */
final class HuntgroupComparison
{
    /** The rota forwards in a window the hunt group has no row for. */
    public const MISSING = 'missing';

    /** The hunt group forwards in a window the rota has no shift for. */
    public const EXTRA = 'extra';

    /** Both forward in the window, to different destinations. */
    public const DESTINATION = 'destination';

    /** The hunt group's row for the window is switched off. */
    public const DISABLED = 'disabled';

    /** Where any voicemail box counts as the same destination. */
    public const VOICEMAIL = 'voicemail';

    /**
     * @param array<ForwardingRule> $expected What the rota projects to.
     * @param array<ForwardingRule> $actual   What the hunt group holds.
     * @return list<Difference> In weekday then time order; empty when they agree.
     */
    public function compare(array $expected, array $actual): array
    {
        $wanted = $this->byWindow($expected);
        $held   = $this->byWindow($actual);

        $differences = [];

        foreach ($wanted as $key => $windows) {
            $candidates = $held[$key] ?? [];
            unset($held[$key]);

            // Pair exact matches first, so a window the rota has twice is
            // not reported as a mismatch just because the rows came back in
            // a different order.
            foreach ($windows as $i => $window) {
                foreach ($candidates as $j => $candidate) {
                    if ($candidate['enabled'] && $candidate['destination'] === $window['destination']) {
                        unset($windows[$i], $candidates[$j]);
                        break;
                    }
                }
            }

            foreach ($windows as $window) {
                $candidate = array_shift($candidates);

                if ($candidate === null) {
                    $differences[] = $this->difference(self::MISSING, $window, $window, null);
                } elseif (! $candidate['enabled']) {
                    $differences[] = $this->difference(self::DISABLED, $window, $window, $candidate);
                } else {
                    $differences[] = $this->difference(self::DESTINATION, $window, $window, $candidate);
                }
            }

            foreach ($candidates as $candidate) {
                if ($candidate['enabled']) {
                    $differences[] = $this->difference(self::EXTRA, $candidate, null, $candidate);
                }
            }
        }

        foreach ($held as $candidates) {
            foreach ($candidates as $candidate) {
                if ($candidate['enabled']) {
                    $differences[] = $this->difference(self::EXTRA, $candidate, null, $candidate);
                }
            }
        }

        usort($differences, static fn (array $a, array $b): int
            => [self::dayIndex($a['day']), $a['from'], $a['to']] <=> [self::dayIndex($b['day']), $b['from'], $b['to']]);

        return $differences;
    }

    /**
     * Every rule's windows, grouped by "day from to".
     *
     * @param array<ForwardingRule> $rules
     * @return array<string, list<Window>>
     */
    private function byWindow(array $rules): array
    {
        usort($rules, static fn (ForwardingRule $a, ForwardingRule $b): int
            => $a->getPriority() <=> $b->getPriority());

        $out = [];

        foreach ($rules as $rule) {
            $value = $rule->getMatch()['value'] ?? [];
            $value = is_array($value) ? $value : [];
            $days  = is_array($value['days'] ?? null) ? $value['days'] : [];
            $from  = self::time((string) ($value['from'] ?? ''));
            $to    = self::time((string) ($value['to'] ?? ''));

            foreach ($days as $day) {
                $day = strtolower((string) $day);

                $out[$day . ' ' . $from . ' ' . $to][] = [
                    'day'         => $day,
                    'from'        => $from,
                    'to'          => $to,
                    'destination' => self::destination($rule->getTargetId()),
                    'label'       => $rule->getLabel(),
                    'enabled'     => $rule->isEnabled(),
                ];
            }
        }

        return $out;
    }

    /**
     * @param Window      $at
     * @param Window|null $expected
     * @param Window|null $actual
     * @return Difference
     */
    private function difference(string $kind, array $at, ?array $expected, ?array $actual): array
    {
        return [
            'kind'     => $kind,
            'day'      => $at['day'],
            'from'     => $at['from'],
            'to'       => $at['to'],
            'expected' => $expected === null ? null : ['destination' => $expected['destination'], 'label' => $expected['label']],
            'actual'   => $actual === null ? null : ['destination' => $actual['destination'], 'label' => $actual['label']],
        ];
    }

    /**
     * A target id as a comparable destination: 'voicemail' for any
     * voicemail box, a number's national digits, or the id itself.
     */
    public static function destination(string $targetId): string
    {
        if (str_starts_with($targetId, 'vm:')) {
            return self::VOICEMAIL;
        }

        if (! str_starts_with($targetId, 'num:')) {
            return $targetId;
        }

        $digits = (string) preg_replace('/\D+/', '', substr($targetId, 4));

        // +44 7700 900123 and 0044 7700 900123 are 07700 900123.
        if (preg_match('/^(?:00)?44(\d{10})$/', $digits, $m) === 1) {
            return '0' . $m[1];
        }

        return $digits;
    }

    /**
     * HH:MM, zero-padded, with the forwarding system's 23:59 for 24:00. Any
     * seconds are dropped. Anything unreadable is kept as given, so it still
     * shows up as a difference rather than silently matching.
     */
    private static function time(string $value): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($value), $m) !== 1) {
            return trim($value);
        }

        return ShiftTime::toStored(str_pad($m[1], 2, '0', STR_PAD_LEFT) . ':' . $m[2]);
    }

    private static function dayIndex(string $day): int
    {
        $index = array_search($day, RotaForwardingProjection::DAYS, true);

        return $index === false ? 7 : $index;
    }
}
