<?php

declare(strict_types=1);

namespace Trusted\Contracts;

use Trusted\Domain\Assignment;

interface AssignmentRepositoryInterface
{
    public function find(int $id): ?Assignment;

    /**
     * @return Assignment[]
     */
    public function findByRota(int $rotaId): array;

    /**
     * Bulk-load assignments for many slots at once, keyed by rota_id.
     *
     * @param int[] $rotaIds
     * @return array<int, Assignment[]>
     */
    public function findByRotaIds(array $rotaIds): array;

    public function save(Assignment $assignment): Assignment;

    public function delete(int $id): bool;

    public function deleteByRota(int $rotaId): bool;

    /**
     * Delete every assignment in the table. Returns the number removed.
     */
    public function deleteAll(): int;
}
