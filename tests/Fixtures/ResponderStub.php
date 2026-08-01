<?php

declare(strict_types=1);

namespace Trusted\Tests\Fixtures;

use Unity\Testing\Doubles\MemberStub;

/**
 * A Unity member, defaulted the way Trusted's tests want one.
 *
 * The 23 accessors of Unity\Members\Interfaces\Member come from the stub Unity
 * ships (see Unity\Testing\Doubles\MemberStub), so a change to that contract
 * surfaces in Unity's own build rather than as silent drift here — the same
 * guarantee this class used to buy by implementing the interface directly, at
 * 143 lines instead of these few.
 *
 * What is left is only what is specific to Trusted: a member is a telephone
 * responder unless a test says otherwise, because nearly every test here is
 * about responders, and the identity fields carry values rather than empty
 * strings so assertions read as names instead of blanks.
 *
 * Keeping those defaults local is the point of the split. They belong to this
 * suite, not to Unity, whose stub defaults everything to empty/false so it
 * stays neutral for the other plugins.
 */
final class ResponderStub extends MemberStub
{
    public function __construct(
        int $id = 1,
        bool $telephoneResponder = true,
        string $anonymousName = 'John D',
        string $personalEmail = 'john@example.test',
        string $mobileNumber = '07700 900123',
    ) {
        parent::__construct(
            id: $id,
            anonymousName: $anonymousName,
            showAnonymousName: true,
            showMemberProfile: true,
            personalEmail: $personalEmail,
            mobileNumber: $mobileNumber,
            telephoneResponder: $telephoneResponder,
            gdprAccepted: true,
        );
    }
}
