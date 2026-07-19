<?php

declare(strict_types=1);

namespace Trusted\Tests\Fixtures;

use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Domain\Assignment;

/**
 * An in-memory AssignmentRepository for tests.
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
     * @param int[] $rotaIds
     * @return Assignment[]
     */
    public function findByRotaIds(array $rotaIds): array
    {
        return array_values(array_filter(
            $this->assignments,
            static fn (Assignment $a): bool => in_array($a->rotaId(), $rotaIds, true)
        ));
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
