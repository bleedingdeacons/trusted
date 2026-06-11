<?php

declare(strict_types=1);

namespace Trusted\Contracts;

use Trusted\Domain\Assignment;

interface AssignmentFactoryInterface
{
    /**
     * @param array<string, mixed> $row
     */
    public function fromRow(array $row): Assignment;

    public function create(int $rotaId, string $memberId, string $notes = ''): Assignment;
}
