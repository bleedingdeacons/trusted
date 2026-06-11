<?php

declare(strict_types=1);

namespace Trusted\Factory;

use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Domain\Assignment;

final class AssignmentFactory implements AssignmentFactoryInterface
{
    public function fromRow(array $row): Assignment
    {
        return new Assignment(
            id: isset($row['id']) ? (int) $row['id'] : null,
            rotaId: (int) ($row['rota_id'] ?? 0),
            memberId: (string) ($row['member_id'] ?? ''),
            notes: (string) ($row['notes'] ?? ''),
            assignedAt: isset($row['assigned_at']) ? (string) $row['assigned_at'] : null,
        );
    }

    public function create(int $rotaId, string $memberId, string $notes = ''): Assignment
    {
        return new Assignment(
            id: null,
            rotaId: $rotaId,
            memberId: $memberId,
            notes: $notes,
        );
    }
}
