<?php

declare(strict_types=1);

namespace Trusted\Domain;

/**
 * The person assigned to a shift.
 *
 * A lightweight, read-only value object. It deliberately carries only the
 * contact details a rota coordinator needs. Where Members come from (WP users,
 * an external HR system, a CRM) is the concern of a MemberFactory implementation.
 */
final class Member implements \JsonSerializable
{
    public function __construct(
        private string $id,
        private string $name,
        private string $email,
        private string $telephone,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function telephone(): string
    {
        return $this->telephone;
    }

    /**
     * @return array{id: string, name: string, email: string, telephone: string}
     */
    public function toArray(): array
    {
        return [
            'id'        => $this->id,
            'name'      => $this->name,
            'email'     => $this->email,
            'telephone' => $this->telephone,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
