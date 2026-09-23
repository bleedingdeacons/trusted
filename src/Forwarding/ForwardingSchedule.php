<?php

declare(strict_types=1);

namespace Trusted\Forwarding;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Beacon\Forwarding\Models\ForwardingRule;
use Beacon\Targets\Models\ForwardingTarget;

/**
 * A week of the rota expressed as a hunt group: what RotaForwardingProjection
 * produces and ForwardingPreview draws.
 *
 * The rules and targets are the Beacon library's own models, so the result is
 * in exactly the shape a forwarding driver such as Tamar reads and writes.
 * Shifts that could not become a rule are kept alongside rather than dropped:
 * a shift nobody is forwarded for is the thing a coordinator most needs to see.
 *
 * @phpstan-type SkippedShift array{
 *     day: string,
 *     date: string,
 *     start: string,
 *     end: string,
 *     label: string,
 *     member: string,
 *     reason: string
 * }
 */
final class ForwardingSchedule
{
    /** The slot has nobody assigned to it. */
    public const UNASSIGNED = 'unassigned';

    /** The assigned member can no longer be found in Unity. */
    public const MEMBER_MISSING = 'member_missing';

    /** The assigned member has no telephone number to forward to. */
    public const NO_TELEPHONE = 'no_telephone';

    /**
     * @param list<ForwardingRule>   $rules   In hunt order.
     * @param list<ForwardingTarget> $targets One per distinct number.
     * @param list<SkippedShift>     $skipped Shifts no rule was made for.
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $targets,
        public readonly array $skipped,
    ) {
    }
}
