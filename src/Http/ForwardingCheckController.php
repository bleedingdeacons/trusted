<?php

declare(strict_types=1);

namespace Trusted\Http;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Trusted\Admin\ForwardingPage;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Forwarding\HuntgroupComparison;
use Trusted\Forwarding\RotaForwardingProjection;
use Trusted\Support\Week;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET trusted/v1/forwarding-check`: does Tamar's hunt group for the current
 * week forward calls the way the rota says it should?
 *
 * Reads the hunt group named for the week ("Forward Week N", the name the
 * Forwarding page publishes under) through Tamar's `tamar/find_huntgroup`
 * filter, and compares it with the week's rota projected into forwarding
 * rules. Nothing is written to Tamar: the filter only reads, and this
 * endpoint never calls the publish filter.
 *
 * Always the current week, in the site's timezone. That is the week whose
 * forwarding is live, and the Rota Calendar only offers the check while it
 * is showing it.
 *
 * The answer is a `status` — `match`, `mismatch` or `missing` — with the
 * differences, each carrying a message ready to show. A Tamar that is not
 * active, refuses the user, or cannot reach its panel is an error, never
 * `missing`, so an outage cannot pass for an absent hunt group.
 *
 * @phpstan-import-type Difference from HuntgroupComparison
 */
final class ForwardingCheckController
{
    /** The filter Tamar reads a hunt group through. */
    public const FIND_HOOK = 'tamar/find_huntgroup';

    public const MATCH    = 'match';
    public const MISMATCH = 'mismatch';
    public const MISSING  = 'missing';

    public function __construct(
        private RotaRepositoryInterface $rota,
        private HuntgroupComparison $comparison = new HuntgroupComparison(),
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(RestController::NAMESPACE, '/forwarding-check', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'check'],
            'permission_callback' => [$this, 'can'],
        ]);
    }

    public function can(): bool
    {
        $capability = (string) apply_filters('trusted_capability', 'manage_options');

        return current_user_can($capability);
    }

    /** Whether Tamar is there to read from. */
    public static function available(): bool
    {
        return has_filter(self::FIND_HOOK) !== false;
    }

    public function check(): WP_REST_Response|WP_Error
    {
        $monday = Week::currentMonday();
        $name   = ForwardingPage::huntgroupName($monday);

        if (! self::available()) {
            return new WP_Error(
                'trusted_forwarding_unavailable',
                __('Tamar is not active, so there is no forwarding to check.', 'trusted'),
                ['status' => 503]
            );
        }

        $expected = (new RotaForwardingProjection())->project($this->rota->findForWeek($monday))->rules;

        try {
            $group = apply_filters(self::FIND_HOOK, null, $name);
        } catch (\Throwable $e) {
            return new WP_Error(
                'trusted_forwarding_failed',
                /* translators: 1: hunt group name, 2: the reason Tamar gave. */
                sprintf(__('Could not read "%1$s" from Tamar: %2$s', 'trusted'), $name, $e->getMessage()),
                ['status' => 502]
            );
        }

        $base = ['week_start' => $monday, 'name' => $name];

        if ($group === null) {
            return new WP_REST_Response($base + [
                'status'      => self::MISSING,
                /* translators: %s: hunt group name, e.g. "Forward Week 40". */
                'message'     => sprintf(__('Tamar has no hunt group named "%s" for this week.', 'trusted'), $name),
                'differences' => [],
            ]);
        }

        $actual = is_array($group) && is_array($group['rules'] ?? null) ? $group['rules'] : null;

        if ($actual === null || array_filter($actual, static fn ($rule): bool => ! $rule instanceof ForwardingRule) !== []) {
            return new WP_Error(
                'trusted_forwarding_failed',
                /* translators: %s: hunt group name. */
                sprintf(__('Tamar did not return the rules of "%s".', 'trusted'), $name),
                ['status' => 502]
            );
        }

        /** @var array<ForwardingRule> $actual */
        $differences = $this->comparison->compare($expected, $actual);

        if ($differences === []) {
            return new WP_REST_Response($base + [
                'status'      => self::MATCH,
                /* translators: %s: hunt group name. */
                'message'     => sprintf(__('Tamar\'s "%s" matches this week\'s rota.', 'trusted'), $name),
                'differences' => [],
            ]);
        }

        return new WP_REST_Response($base + [
            'status'      => self::MISMATCH,
            'message'     => sprintf(
                /* translators: 1: hunt group name, 2: number of differences. */
                _n(
                    'Tamar\'s "%1$s" does not match this week\'s rota: %2$d difference.',
                    'Tamar\'s "%1$s" does not match this week\'s rota: %2$d differences.',
                    count($differences),
                    'trusted'
                ),
                $name,
                count($differences)
            ),
            'differences' => array_map(fn (array $d): array => $d + ['message' => $this->describe($d)], $differences),
        ]);
    }

    /**
     * One difference as a sentence: "Mon 10:00–14:00: the rota forwards to
     * Anon A (07700900123), but Tamar forwards to voicemail."
     *
     * @param Difference $difference
     */
    private function describe(array $difference): string
    {
        $when     = $this->dayName($difference['day']) . ' ' . $difference['from'] . '–' . $difference['to'];
        $expected = $difference['expected'] === null ? '' : $this->destination($difference['expected']);
        $actual   = $difference['actual'] === null ? '' : $this->destination($difference['actual']);

        return match ($difference['kind']) {
            /* translators: 1: day and times, 2: where the rota forwards. */
            HuntgroupComparison::MISSING => sprintf(__('%1$s: the rota forwards to %2$s, but Tamar has no row for this time.', 'trusted'), $when, $expected),
            /* translators: 1: day and times, 2: where Tamar forwards. */
            HuntgroupComparison::EXTRA => sprintf(__('%1$s: Tamar forwards to %2$s, but the rota has no shift at this time.', 'trusted'), $when, $actual),
            /* translators: 1: day and times, 2: where the rota forwards. */
            HuntgroupComparison::DISABLED => sprintf(__('%1$s: the rota forwards to %2$s, but Tamar\'s row for this time is switched off.', 'trusted'), $when, $expected),
            /* translators: 1: day and times, 2: where the rota forwards, 3: where Tamar forwards. */
            default => sprintf(__('%1$s: the rota forwards to %2$s, but Tamar forwards to %3$s.', 'trusted'), $when, $expected, $actual),
        };
    }

    /**
     * @param array{destination: string, label: string} $side
     */
    private function destination(array $side): string
    {
        if ($side['destination'] === HuntgroupComparison::VOICEMAIL) {
            return __('voicemail', 'trusted');
        }

        if ($side['destination'] === '') {
            return __('nowhere', 'trusted');
        }

        return trim($side['label']) === ''
            ? $side['destination']
            : trim($side['label']) . ' (' . $side['destination'] . ')';
    }

    private function dayName(string $day): string
    {
        return match ($day) {
            'mon'   => __('Mon', 'trusted'),
            'tue'   => __('Tue', 'trusted'),
            'wed'   => __('Wed', 'trusted'),
            'thu'   => __('Thu', 'trusted'),
            'fri'   => __('Fri', 'trusted'),
            'sat'   => __('Sat', 'trusted'),
            'sun'   => __('Sun', 'trusted'),
            default => $day,
        };
    }
}
