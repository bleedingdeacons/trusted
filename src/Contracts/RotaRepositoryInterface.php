<?php

declare(strict_types=1);

namespace Trusted\Contracts;

use Trusted\Domain\Rota;

interface RotaRepositoryInterface
{
    public function find(int $id): ?Rota;

    /**
     * All slots whose date falls within the 7 days starting at $weekStart
     * (a Monday, Y-m-d). Assignments are eager-loaded.
     *
     * @return Rota[]
     */
    public function findForWeek(string $weekStart): array;

    /**
     * Insert when the entity has no id, otherwise update. Returns the entity
     * with its id populated.
     */
    public function save(Rota $rota): Rota;

    /**
     * Delete a slot and cascade to its assignments.
     */
    public function delete(int $id): bool;

    /**
     * Delete every slot in the given week. Returns the number of slots removed.
     */
    public function deleteWeek(string $weekStart): int;

    /**
     * Delete every slot in every week, cascading to all assignments. Returns
     * the number of slots removed.
     */
    public function deleteAll(): int;
}
