<?php

declare(strict_types=1);

namespace Trusted\Testing\Doubles;

use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Rota;

/**
 * An in-memory RotaRepository for tests.
 *
 * Implements the real interface so a change to the contract surfaces here
 * rather than drifting.
 *
 * Shipped from src/ rather than kept under tests/ so that consuming plugins
 * can use it too — Trusted's rota is read by anything that presents it, and
 * the alternative is each of those writing its own copy. Same reason Unity
 * ships Unity\Testing\Doubles.
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

    /**
     * All slots in the 7 days starting at $weekStart, as the real repository
     * defines it.
     *
     * This used to return every slot regardless of the week, which was
     * harmless while the only caller was ShiftSignup — it never asked for a
     * week. As a double other plugins build on it is not harmless: a consumer
     * asserting "this week holds two shifts" would pass against a repository
     * that had simply handed back everything, and fail the moment it met the
     * real one.
     *
     * @return Rota[]
     */
    public function findForWeek(string $weekStart): array
    {
        $weekEnd = date('Y-m-d', (int) strtotime($weekStart . ' +6 days'));

        return array_values(array_filter(
            $this->rotas,
            static fn (Rota $rota): bool => $rota->slotDate() >= $weekStart && $rota->slotDate() <= $weekEnd
        ));
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
