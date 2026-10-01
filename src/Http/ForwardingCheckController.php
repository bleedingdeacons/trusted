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
 * The Rota Calendar's forwarding check and sync, both for the current week.
 *
 * `GET trusted/v1/forwarding-check`: does Tamar's hunt group for the current
 * week forward calls the way the rota says it should? Reads the hunt group
 * named for the week ("Forward Week N", the name the Forwarding page
 * publishes under) through Tamar's `tamar/find_huntgroup` filter and compares
 * it with the week's rota projected into forwarding rules. Nothing is written
 * to Tamar.
 *
 * `POST trusted/v1/forwarding-sync`: write the current week's rota into that
 * hunt group through Tamar's `tamar/publish_huntgroup` filter — the same path
 * as the Forwarding page's Publish button, so Tamar creates the group if need
 * be, replaces every row and makes it the group its Overview shows — then
 * check again and answer as the check does. The re-read is what reports the
 * outcome, so a write Tamar accepted but did not apply still shows as a
 * mismatch rather than a success.
 *
 * Always the current week, in the site's timezone. That is the week whose
 * forwarding is live, and the Rota Calendar only offers either action while
 * it is showing it.
 *
 * A check answers with a `status` — `match`, `mismatch` or `missing` — the
 * differences, each carrying a message ready to show, and `can_sync`: whether
 * a sync would change anything and is possible (Tamar can publish, the week
 * has shifts). A Tamar that is not active, refuses the user, or cannot reach
 * its panel is an error, never `missing`, so an outage cannot pass for an
 * absent hunt group.
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

        register_rest_route(RestController::NAMESPACE, '/forwarding-sync', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'sync'],
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

    /** Whether Tamar is there to write to. */
    public static function canPublish(): bool
    {
        return has_filter(ForwardingPage::PUBLISH_HOOK) !== false;
    }

    public function check(): WP_REST_Response|WP_Error
    {
        if (! self::available()) {
            return $this->unavailable(__('Tamar is not active, so there is no forwarding to check.', 'trusted'));
        }

        $monday = Week::currentMonday();

        return $this->compare($monday, $this->rulesFor($monday));
    }

    public function sync(): WP_REST_Response|WP_Error
    {
        if (! self::available() || ! self::canPublish()) {
            return $this->unavailable(__('Tamar is not active, so there is nowhere to sync to.', 'trusted'));
        }

        $monday = Week::currentMonday();
        $name   = ForwardingPage::huntgroupName($monday);
        $rules  = $this->rulesFor($monday);

        if ($rules === []) {
            return new WP_Error(
                'trusted_forwarding_empty',
                __('This week has no shifts, so there is nothing to sync to Tamar.', 'trusted'),
                ['status' => 409]
            );
        }

        try {
            $published = apply_filters(ForwardingPage::PUBLISH_HOOK, null, $name, $rules);
        } catch (\Throwable $e) {
            return new WP_Error(
                'trusted_forwarding_failed',
                /* translators: 1: hunt group name, 2: the reason Tamar gave. */
                sprintf(__('Could not sync "%1$s" to Tamar: %2$s', 'trusted'), $name, $e->getMessage()),
                ['status' => 502]
            );
        }

        if (! is_array($published) || ! isset($published['id'])) {
            return new WP_Error(
                'trusted_forwarding_failed',
                /* translators: %s: hunt group name. */
                sprintf(__('Tamar did not confirm that "%s" was synced.', 'trusted'), $name),
                ['status' => 502]
            );
        }

        $result = $this->compare($monday, $rules);

        if ($result instanceof WP_Error) {
            return $result;
        }

        $data           = (array) $result->get_data();
        $data['synced'] = true;

        if ($data['status'] === self::MATCH) {
            $data['message'] = sprintf(
                /* translators: 1: hunt group name, 2: number of forwarding steps. */
                _n(
                    'Synced this week\'s rota to Tamar\'s "%1$s" (%2$d forwarding step). It now matches, and Tamar\'s Overview shows it.',
                    'Synced this week\'s rota to Tamar\'s "%1$s" (%2$d forwarding steps). It now matches, and Tamar\'s Overview shows it.',
                    count($rules),
                    'trusted'
                ),
                $name,
                count($rules)
            );
        } else {
            $data['message'] = sprintf(
                /* translators: 1: hunt group name, 2: what the check found afterwards. */
                __('Tamar accepted the sync of "%1$s", but reading it back still shows a difference. %2$s', 'trusted'),
                $name,
                (string) $data['message']
            );
        }

        return new WP_REST_Response($data, $result->get_status());
    }

    /**
     * The week's rota as forwarding rules.
     *
     * @return list<ForwardingRule>
     */
    private function rulesFor(string $monday): array
    {
        return (new RotaForwardingProjection())->project($this->rota->findForWeek($monday))->rules;
    }

    private function unavailable(string $message): WP_Error
    {
        return new WP_Error('trusted_forwarding_unavailable', $message, ['status' => 503]);
    }

    /**
     * Read the week's hunt group from Tamar and compare it with $expected.
     *
     * @param list<ForwardingRule> $expected
     */
    private function compare(string $monday, array $expected): WP_REST_Response|WP_Error
    {
        $name = ForwardingPage::huntgroupName($monday);

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

        // A sync is worth offering only when it would change something, and
        // possible only when Tamar can publish and there is a rota to write.
        $syncable = self::canPublish() && $expected !== [];

        $base = ['week_start' => $monday, 'name' => $name];

        if ($group === null) {
            return new WP_REST_Response($base + [
                'status'      => self::MISSING,
                /* translators: %s: hunt group name, e.g. "Forward Week 40". */
                'message'     => sprintf(__('Tamar has no hunt group named "%s" for this week.', 'trusted'), $name),
                'differences' => [],
                'can_sync'    => $syncable,
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
                'can_sync'    => false,
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
            'can_sync'    => $syncable,
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
