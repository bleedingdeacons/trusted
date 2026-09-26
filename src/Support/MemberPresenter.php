<?php

declare(strict_types=1);

namespace Trusted\Support;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Trusted\Domain\Member;
use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Members\PreferredContact;

/**
 * Adapts a Unity member to Trusted's lightweight Member value object.
 *
 * This is the single mapping point between Unity's domain and Trusted's REST
 * boundary. Trusted sources all member data from Unity's MemberRepository, so
 * this presenter keeps the field translation in one place:
 *
 *   getAnonymousName() -> name
 *   getPersonalEmail() -> email
 *   getPreferredContact() picks the telephone:
 *     Mobile   -> getMobileNumber()
 *     Landline -> getLandlineNumber()
 *
 * The telephone is the number the member wants to be rung on, so it is what
 * the calendar shows on a filled shift and what forwarding rings. Unity
 * already resolves a member with no landline to Mobile.
 */
final class MemberPresenter
{
    public static function toMember(UnityMember $member): Member
    {
        return new Member(
            id: (string) $member->getId(),
            name: $member->getAnonymousName(),
            email: $member->getPersonalEmail(),
            telephone: self::telephone($member),
        );
    }

    private static function telephone(UnityMember $member): string
    {
        return $member->getPreferredContact() === PreferredContact::Landline
            ? $member->getLandlineNumber()
            : $member->getMobileNumber();
    }
}
