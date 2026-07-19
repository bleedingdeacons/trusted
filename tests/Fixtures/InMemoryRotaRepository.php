<?php

declare(strict_types=1);

namespace Trusted\Tests\Fixtures;

use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Rota;

/**
 * An in-memory RotaRepository for tests.
 *
 * Implements the real interface so a change to the contract surfaces here
 * rather than drifting. Only the read paths ShiftSignup uses are meaningful;
 * the write paths satisfy the interface and are not exercised.
 */
final class InMemoryRotaRepository implements RotaRepositoryInterface
{
    /** @param array<int, Rota> $rotas keyed by id */
    public function __construct(private array $rotas = [])
    {
    }

    public function find(int $id): ?Rota
    {
        return $this->rotas[$id] ?? null;
    }

    /** @return Rota[] */
    public function findForWeek(string $weekStart): array
    {
        return array_values($this->rotas);
    }

    /** @return Rota[] */
    public function findForDate(string $date): array
    {
        return array_values(array_filter(
            $this->rotas,
            static fn (Rota $rota): bool => $rota->slotDate() === $date
        ));
    }

    public function save(Rota $rota): Rota
    {
        $id = $rota->id() ?? (max(array_keys($this->rotas) ?: [0]) + 1);
        $stored = $rota->id() === null ? $rota->withId($id) : $rota;
        $this->rotas[$id] = $stored;

        return $stored;
    }

    public function delete(int $id): bool
    {
        if (!isset($this->rotas[$id])) {
            return false;
        }
        unset($this->rotas[$id]);

        return true;
    }

    public function deleteWeek(string $weekStart): int
    {
        $count = count($this->rotas);
        $this->rotas = [];

        return $count;
    }

    public function deleteAll(): int
    {
        return $this->deleteWeek('');
    }
}
