<?php

declare(strict_types=1);

namespace Trusted\Testing\Doubles;

use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Domain\Assignment;

/**
 * An in-memory AssignmentRepository for tests.
 *
 * Shipped from src/ rather than kept under tests/ so that consuming plugins
 * can use it too — see the note on {@see InMemoryRotaRepository}.
 *
 * assignIfOpen() is the interesting one: in production the atomicity comes
 * from a UNIQUE(rota_id) constraint, and a null return means the slot was
 * already claimed. This models that rule — one assignment per rota id — so
 * the "already full" path is exercised without a database.
 */
final class InMemoryAssignmentRepository implements AssignmentRepositoryInterface
{
    private int $nextId = 1;

    /** @param array<int, Assignment> $assignments keyed by id */
    public function __construct(private array $assignments = [])
    {
        foreach (array_keys($assignments) as $id) {
            $this->nextId = max($this->nextId, $id + 1);
        }
    }

    public function find(int $id): ?Assignment
    {
        return $this->assignments[$id] ?? null;
    }

    /** @return Assignment[] */
    public function findByRota(int $rotaId): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (Assignment $a): bool => $a->rotaId() === $rotaId
        ));
    }

    /**
     * Bulk-load assignments for many slots at once, keyed by rota_id.
     *
     * The keying is the contract, and this returned a flat list until the
     * class moved into src/ and PHPStan started analysing it. Nothing here
     * caught it: no test in this suite exercises the bulk path through the
     * double, so the wrong shape sat behind a correct-looking signature. A
     * consumer eager-loading a week's assignments would have got a list
     * indexed 0..n and quietly found nothing under any rota id.
     *
     * @param int[] $rotaIds
     * @return array<int, Assignment[]>
     */
    public function findByRotaIds(array $rotaIds): array
    {
        $byRota = [];

        foreach ($this->assignments as $assignment) {
            if (!in_array($assignment->rotaId(), $rotaIds, true)) {
                continue;
            }

            $byRota[$assignment->rotaId()][] = $assignment;
        }

        return $byRota;
    }

    public function save(Assignment $assignment): Assignment
    {
        $id = $assignment->id() ?? $this->nextId++;
        $stored = $assignment->id() === null ? $assignment->withId($id) : $assignment;
        $this->assignments[$id] = $stored;

        return $stored;
    }

    public function assignIfOpen(int $rotaId, string $memberId, string $notes): ?Assignment
    {
        // The UNIQUE(rota_id) constraint, modelled.
        if ($this->findByRota($rotaId) !== []) {
            return null;
        }

        return $this->save(new Assignment(
            id: null,
            rotaId: $rotaId,
            memberId: $memberId,
            notes: $notes,
        ));
    }

    public function delete(int $id): bool
    {
        if (!isset($this->assignments[$id])) {
            return false;
        }
        unset($this->assignments[$id]);

        return true;
    }

    public function deleteByRota(int $rotaId): bool
    {
        $found = false;
        foreach ($this->findByRota($rotaId) as $assignment) {
            if ($assignment->id() !== null) {
                $this->delete((int) $assignment->id());
                $found = true;
            }
        }

        return $found;
    }

    public function deleteAll(): int
    {
        $count = count($this->assignments);
        $this->assignments = [];

        return $count;
    }
}
