<?php

declare(strict_types=1);

namespace Trusted\Domain;

/**
 * Links a Member (by id) to a Rota slot.
 *
 * The Member object itself is resolved on demand through a MemberFactory and
 * attached here for presentation; it is never persisted in this table.
 */
final class Assignment implements \JsonSerializable
{
    public function __construct(
        private ?int $id,
        private int $rotaId,
        private string $memberId,
        private string $notes = '',
        private ?string $assignedAt = null,
        private ?Member $member = null,
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

    public function rotaId(): int
    {
        return $this->rotaId;
    }

    public function memberId(): string
    {
        return $this->memberId;
    }

    public function notes(): string
    {
        return $this->notes;
    }

    public function assignedAt(): ?string
    {
        return $this->assignedAt;
    }

    public function member(): ?Member
    {
        return $this->member;
    }

    public function withMember(?Member $member): self
    {
        $clone         = clone $this;
        $clone->member = $member;

        return $clone;
    }

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'rota_id'     => $this->rotaId,
            'member_id'   => $this->memberId,
            'notes'       => $this->notes,
            'assigned_at' => $this->assignedAt,
            'member'      => $this->member?->toArray(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
