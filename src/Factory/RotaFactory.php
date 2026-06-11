<?php

declare(strict_types=1);

namespace Trusted\Factory;

use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Domain\Rota;

final class RotaFactory implements RotaFactoryInterface
{
    public function fromRow(array $row): Rota
    {
        return new Rota(
            id: isset($row['id']) ? (int) $row['id'] : null,
            slotDate: (string) ($row['slot_date'] ?? ''),
            startTime: $this->normaliseTime((string) ($row['start_time'] ?? '')),
            endTime: $this->normaliseTime((string) ($row['end_time'] ?? '')),
            label: (string) ($row['label'] ?? ''),
            templateId: isset($row['template_id']) && $row['template_id'] !== null
                ? (int) $row['template_id']
                : null,
        );
    }

    public function create(
        string $slotDate,
        string $startTime,
        string $endTime,
        string $label = '',
        ?int $templateId = null
    ): Rota {
        return new Rota(
            id: null,
            slotDate: $slotDate,
            startTime: $this->normaliseTime($startTime),
            endTime: $this->normaliseTime($endTime),
            label: $label,
            templateId: $templateId,
        );
    }

    /**
     * MySQL TIME columns return "H:i:s"; the UI works in "H:i".
     */
    private function normaliseTime(string $time): string
    {
        return substr($time, 0, 5);
    }
}
