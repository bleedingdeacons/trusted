<?php

declare(strict_types=1);

namespace Trusted\Service;

use InvalidArgumentException;
use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Unity\Members\Interfaces\Member as UnityMember;

/**
 * Reusable shift sign-up logic, decoupled from the admin REST controller.
 *
 * Lets a verified telephone responder be attached to existing shifts, enforcing
 * the calendar's rules (responder-only, one member per shift). It can read the
 * day's shifts in an anonymised form for a member to choose from. It is
 * deliberately read + assign only — it exposes no way to create, edit or delete
 * shifts.
 *
 * Consumed both by Trusted's own admin controller and by sibling plugins (e.g.
 * a member-facing sign-up app) that resolve it from Unity's container after
 * authenticating the member.
 */
final class ShiftSignup
{
    public function __construct(
        private RotaRepositoryInterface $rota,
        private AssignmentRepositoryInterface $assignments,
        private AssignmentFactoryInterface $assignmentFactory,
    ) {
    }

    /**
     * The day's shifts for a member to choose from, in an anonymised form.
     *
     * Carries no assignee details (name/email/phone) — only whether each shift
     * is still open — so it is safe to surface outside the admin.
     *
     * @return array<int, array{id:int, date:string, start:string, end:string, label:string, is_open:bool}>
     */
    public function openShiftsForDate(string $date): array
    {
        $out = [];

        foreach ($this->rota->findForDate($date) as $slot) {
            $out[] = [
                'id'      => (int) $slot->id(),
                'date'    => $slot->slotDate(),
                'start'   => $slot->startTime(),
                'end'     => $slot->endTime(),
                'label'   => $slot->label(),
                'is_open' => $slot->assignments() === [],
            ];
        }

        return $out;
    }

    /**
     * Assign a telephone responder to the given shifts.
     *
     * One member per shift: any slot that is missing or already filled is
     * skipped (not an error) and reported back, so a caller can tell the member
     * what was left out. The member MUST be a telephone responder — callers are
     * expected to verify this first; passing a non-responder is a programming
     * error and throws.
     *
     * @param int[] $rotaIds
     * @return array{assigned: array<int, array<string, mixed>>, skipped: array<int, array{rota_id:int, reason:string}>}
     */
    public function assignResponder(UnityMember $member, array $rotaIds, string $notes = ''): array
    {
        if (! $member->isTelephoneResponder()) {
            throw new InvalidArgumentException('Member is not a telephone responder.');
        }

        $memberId = (string) $member->getId();
        $assigned = [];
        $skipped  = [];

        foreach ($this->normaliseIds($rotaIds) as $rotaId) {
            if ($this->rota->find($rotaId) === null) {
                $skipped[] = ['rota_id' => $rotaId, 'reason' => 'not_found'];
                continue;
            }

            if ($this->assignments->findByRota($rotaId) !== []) {
                $skipped[] = ['rota_id' => $rotaId, 'reason' => 'full'];
                continue;
            }

            $saved      = $this->assignments->save($this->assignmentFactory->create($rotaId, $memberId, $notes));
            $assigned[] = $saved->toArray();
        }

        return ['assigned' => $assigned, 'skipped' => $skipped];
    }

    /**
     * De-duplicate to a unique list of positive ints.
     *
     * @param int[] $ids
     * @return int[]
     */
    private function normaliseIds(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }
}
