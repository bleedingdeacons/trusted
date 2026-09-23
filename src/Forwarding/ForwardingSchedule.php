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
 *
 * Every shift becomes at least one rule. One nobody can answer — unfilled, or
 * filled by someone who cannot be reached — forwards to voicemail, and its
 * rules are listed in `unfilled` with the reason, because a shift that falls
 * to voicemail is the thing a coordinator most needs to see.
 *
 * @phpstan-type UnfilledShift array{reason: string, member: string}
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
     * @param list<ForwardingRule>            $rules    In hunt order.
     * @param list<ForwardingTarget>          $targets  One per distinct destination.
     * @param array<array-key, UnfilledShift> $unfilled Keyed by the id of each
     *                                                 rule that forwards to
     *                                                 voicemail. Rule ids are
     *                                                 numeric strings, which PHP
     *                                                 turns into int keys — look
     *                                                 them up with unfilledFor().
     */
    public function __construct(
        public readonly array $rules,
        public readonly array $targets,
        public readonly array $unfilled,
    ) {
    }

    /**
     * Why a rule forwards to voicemail, or null when it forwards to a person.
     *
     * @return UnfilledShift|null
     */
    public function unfilledFor(ForwardingRule $rule): ?array
    {
        return $this->unfilled[$rule->getId()] ?? null;
    }
}
