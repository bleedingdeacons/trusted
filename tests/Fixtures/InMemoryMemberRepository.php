<?php

declare(strict_types=1);

namespace Trusted\Tests\Fixtures;

use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Members\Interfaces\MemberRepository;

/**
 * An in-memory Unity MemberRepository for tests.
 *
 * ResponderDirectory is final, so it cannot be mocked — and should not be:
 * driving the real directory through a fake repository exercises its actual
 * name matching (trimmed, case-insensitive, first match wins) rather than a
 * stubbed approximation of it.
 *
 * findTelephoneResponders() filters this list the way the real repository
 * does, so a member who is not a responder is visible to findAll() and
 * therefore to memberExists(), but not to findResponder().
 */
final class InMemoryMemberRepository implements MemberRepository
{
    /** @param UnityMember[] $members */
    public function __construct(private array $members = [])
    {
    }

    public function findById(int $id): ?UnityMember
    {
        foreach ($this->members as $member) {
            if ($member->getId() === $id) {
                return $member;
            }
        }

        return null;
    }

    public function findByEmail(string $email): ?UnityMember
    {
        foreach ($this->members as $member) {
            if ($member->getPersonalEmail() === $email) {
                return $member;
            }
        }

        return null;
    }

    /** @return UnityMember[] */
    public function findAll(array $args = []): array
    {
        return $this->members;
    }

    /** @return UnityMember[] */
    public function findTelephoneResponders(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (UnityMember $m): bool => $m->isTelephoneResponder()
        ));
    }

    public function count(array $args = []): int
    {
        return count($this->members);
    }

    public function create(string $anonymousName): int
    {
        return 0;
    }

    public function save(UnityMember $member): bool
    {
        return true;
    }

    public function delete(int $id): bool
    {
        return true;
    }

    public function update(UnityMember $member): bool
    {
        return true;
    }
}
