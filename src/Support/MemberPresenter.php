<?php

declare(strict_types=1);

namespace Trusted\Support;

use Trusted\Domain\Member;
use Unity\Members\Interfaces\Member as UnityMember;

/**
 * Adapts a Unity member to Trusted's lightweight Member value object.
 *
 * This is the single mapping point between Unity's domain and Trusted's REST
 * boundary. Trusted sources all member data from Unity's MemberRepository, so
 * this presenter keeps the field translation in one place:
 *
 *   getAnonymousName() -> name
 *   getPersonalEmail() -> email
 *   getMobileNumber()  -> telephone
 */
final class MemberPresenter
{
    public static function toMember(UnityMember $member): Member
    {
        return new Member(
            id: (string) $member->getId(),
            name: $member->getAnonymousName(),
            email: $member->getPersonalEmail(),
            telephone: $member->getMobileNumber(),
        );
    }
}
