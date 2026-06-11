<?php

declare(strict_types=1);

namespace Trusted\Domain;

/**
 * A rota entry: one concrete shift slot on one date.
 *
 * A week's rota is simply the collection of Rota rows whose slot_date falls
 * within that week. Assignments attach Members to these slots.
 */
final class Rota implements \JsonSerializable
{
    /**
     * @param Assignment[] $assignments Hydrated lazily by the repository.
     */
    public function __construct(
        private ?int $id,
        private string $slotDate,   // Y-m-d
        private string $startTime,  // H:i
        private string $endTime,    // H:i
        private string $label = '',
        private ?int $templateId = null,
        private array $assignments = [],
    ) {
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function withId(int $id): self
    {
        $clone     = clone $this;
        $clone->id = $id;

        return $clone;
    }

    public function slotDate(): string
    {
        return $this->slotDate;
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

    public function templateId(): ?int
    {
        return $this->templateId;
    }

    /**
     * @return Assignment[]
     */
    public function assignments(): array
    {
        return $this->assignments;
    }

    /**
     * @param Assignment[] $assignments
     */
    public function withAssignments(array $assignments): self
    {
        $clone              = clone $this;
        $clone->assignments = array_values($assignments);

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'date'        => $this->slotDate,
            'start'       => $this->startTime,
            'end'         => $this->endTime,
            'label'       => $this->label,
            'template_id' => $this->templateId,
            'assignments' => array_map(
                static fn (Assignment $a): array => $a->toArray(),
                $this->assignments
            ),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
