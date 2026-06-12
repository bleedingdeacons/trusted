<?php

declare(strict_types=1);

namespace Trusted\Support;

use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Resolves template member names to Unity members.
 *
 * Templates name members by their anonymous name (free text typed by a
 * coordinator). This helper maps such a name to the matching Unity member,
 * shared by TemplateValidator (save-time checks) and TemplateApplicator
 * (apply-time assignment) so both resolve names identically.
 *
 * Matching is trimmed and case-insensitive. Anonymous names are not guaranteed
 * unique; when several members share a name the first match wins. The telephone
 * responder lookup is built once from MemberRepository::findTelephoneResponders()
 * and cached on the instance.
 */
final class ResponderDirectory
{
    /** @var array<string, UnityMember>|null Lower-cased name => responder. */
    private ?array $responders = null;

    public function __construct(private MemberRepository $members)
    {
    }

    /**
     * The telephone responder whose anonymous name matches $name, or null.
     */
    public function findResponder(string $name): ?UnityMember
    {
        $key = $this->key($name);

        if ($key === '') {
            return null;
        }

        return $this->respondersByName()[$key] ?? null;
    }

    /**
     * Whether any member (responder or not) has the anonymous name $name.
     *
     * Used only to tell "no such member" apart from "not a responder" when
     * reporting why a template name was rejected; backed by the heavier
     * findAll() and therefore only called on the failure path.
     */
    public function memberExists(string $name): bool
    {
        $key = $this->key($name);

        if ($key === '') {
            return false;
        }

        foreach ($this->members->findAll([]) as $member) {
            if ($member instanceof UnityMember && $this->key($member->getAnonymousName()) === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, UnityMember>
     */
    private function respondersByName(): array
    {
        if ($this->responders !== null) {
            return $this->responders;
        }

        $this->responders = [];

        foreach ($this->members->findTelephoneResponders() as $member) {
            if (! $member instanceof UnityMember) {
                continue;
            }

            $key = $this->key($member->getAnonymousName());

            // First match wins on duplicate names.
            if ($key !== '' && ! isset($this->responders[$key])) {
                $this->responders[$key] = $member;
            }
        }

        return $this->responders;
    }

    private function key(string $name): string
    {
        return strtolower(trim($name));
    }
}
