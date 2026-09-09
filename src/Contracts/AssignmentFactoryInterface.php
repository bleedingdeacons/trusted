<?php

declare(strict_types=1);

namespace Trusted\Contracts;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Trusted\Domain\Assignment;

interface AssignmentFactoryInterface
{
    /**
     * @param array<string, mixed> $row
     */
    public function fromRow(array $row): Assignment;

    public function create(int $rotaId, string $memberId, string $notes = ''): Assignment;
}
