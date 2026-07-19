<?php

declare(strict_types=1);

namespace Trusted\Tests\Fixtures;

use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Members\ResponderCertification;

/**
 * A Unity member for tests.
 *
 * Implements Unity's real interface rather than a copy, so a change to that
 * contract surfaces here as an unimplemented-method error rather than silent
 * drift. Only the fields Trusted actually reads are parameterised; the rest
 * return fixed values.
 */
final class ResponderStub implements UnityMember
{
    public function __construct(
        private int $id = 1,
        private bool $telephoneResponder = true,
        private string $anonymousName = 'John D',
        private string $personalEmail = 'john@example.test',
        private string $mobileNumber = '07700 900123',
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function isTelephoneResponder(): bool
    {
        return $this->telephoneResponder;
    }

    public function getAnonymousName(): string
    {
        return $this->anonymousName;
    }

    public function getPersonalEmail(): string
    {
        return $this->personalEmail;
    }

    public function getMobileNumber(): string
    {
        return $this->mobileNumber;
    }

    public function showAnonymousName(): bool
    {
        return true;
    }

    public function showMemberProfile(): bool
    {
        return true;
    }

    public function getAnonymousProfile(): string
    {
        return '';
    }

    public function getIntergroupPosition(): int
    {
        return 0;
    }

    public function getIntergroupPositionRotation(): string
    {
        return '';
    }

    public function getHomeGroup(): int
    {
        return 0;
    }

    public function isGSR(): bool
    {
        return false;
    }

    public function getMeetingPO(): mixed
    {
        return null;
    }

    public function isTwelfthStepper(): bool
    {
        return false;
    }

    public function getResponderCertification(): ResponderCertification
    {
        return ResponderCertification::None;
    }

    public function getArea(): string
    {
        return '';
    }

    public function getAccepts(): array
    {
        return [];
    }

    public function isGdprAccepted(): bool
    {
        return true;
    }

    public function getGdprAcceptedAt(): string
    {
        return '';
    }

    public function getGdprAcceptanceVersion(): string
    {
        return '';
    }

    public function getGdprAcceptanceMethod(): string
    {
        return '';
    }

    public function getGdprAcceptanceStatement(): string
    {
        return '';
    }

    public function getUpdated(): string
    {
        return '';
    }
}
