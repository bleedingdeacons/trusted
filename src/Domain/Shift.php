<?php

declare(strict_types=1);

namespace Trusted\Domain;

/**
 * A single shift definition coming from a weekly template (not yet scheduled
 * against a concrete date). Times are 24-hour "H:i" strings.
 */
final class Shift implements \JsonSerializable
{
    public function __construct(
        private string $startTime,
        private string $endTime,
        private string $label = '',
    ) {
    }

    public function startTime(): string
    {
        return $this->startTime;
    }

    public function endTime(): string
    {
        return $this->endTime;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * @return array{start: string, end: string, label: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startTime,
            'end'   => $this->endTime,
            'label' => $this->label,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
