<?php

declare(strict_types=1);

namespace Trusted\Contracts;

use Trusted\Domain\Rota;

interface RotaFactoryInterface
{
    /**
     * Build a Rota from a raw database row.
     *
     * @param array<string, mixed> $row
     */
    public function fromRow(array $row): Rota;

    public function create(
        string $slotDate,
        string $startTime,
        string $endTime,
        string $label = '',
        ?int $templateId = null
    ): Rota;
}
