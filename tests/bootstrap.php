<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Trusted.
 *
 * The suite covers the parts of the plugin that are pure PHP: the template
 * grammar, the domain value objects, the row-to-object factories and the
 * sign-up service. None of them touch WordPress, so no WP test harness is
 * needed — only Unity's Member interface, which ShiftSignup and
 * MemberPresenter type-hint.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// ──────────────────────────────────────────────
//  Unity's Member interface
//
//  Loaded from a sibling Unity checkout when there is one — the layout CI
//  uses, and the one a developer working across the suite will have. Falls
//  back to a local stub so the suite still runs from a bare clone of this
//  repo alone.
//
//  The stub must stay in step with Unity's real interface: a test double
//  implementing a stale copy would satisfy the stub and fail against the
//  real thing, which is exactly how Reach's suite came to be broken.
// ──────────────────────────────────────────────
$unityMember = dirname(__DIR__, 2) . '/unity/src/Members/Interfaces/Member.php';

if (is_file($unityMember)) {
    require_once dirname(__DIR__, 2) . '/unity/src/Members/ResponderCertification.php';
    require_once $unityMember;
} elseif (!interface_exists(\Unity\Members\Interfaces\Member::class)) {
    eval(<<<'PHP'
namespace Unity\Members;

enum ResponderCertification: string
{
    case None = 'None';
    case Applied = 'Applied';
    case InTraining = 'In Training';
    case CertificationPending = 'Certification Pending';
    case Certified = 'Certified';
    case RecertificationRequired = 'Recertification Required';
    case Denied = 'Denied';
}

namespace Unity\Members\Interfaces;

interface Member
{
    public function getId(): int;
    public function getAnonymousName(): string;
    public function showAnonymousName(): bool;
    public function showMemberProfile(): bool;
    public function getAnonymousProfile(): string;
    public function getIntergroupPosition(): int;
    public function getIntergroupPositionRotation(): string;
    public function getHomeGroup(): int;
    public function isGSR(): bool;
    public function getMeetingPO(): mixed;
    public function getPersonalEmail(): string;
    public function getMobileNumber(): string;
    public function isTwelfthStepper(): bool;
    public function isTelephoneResponder(): bool;
    public function getResponderCertification(): \Unity\Members\ResponderCertification;
    public function getArea(): string;
    public function getAccepts(): array;
    public function isGdprAccepted(): bool;
    public function getGdprAcceptedAt(): string;
    public function getGdprAcceptanceVersion(): string;
    public function getGdprAcceptanceMethod(): string;
    public function getGdprAcceptanceStatement(): string;
    public function getUpdated(): string;
}
PHP
    );
}
