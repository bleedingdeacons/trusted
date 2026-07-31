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
 *
 * The WordPress stand-ins that are needed come from bleedingdeacons/wp-mocks,
 * shared across the plugin suite, plus this plugin's own wp-stubs.php for the
 * REST classes and the recording registrars the tests assert on.
 */

use BleedingDeacons\WpMocks\Bootstrap;
use BleedingDeacons\WpMocks\WpState;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Patchwork first, and nothing patchable before it.
//
// It rewrites functions as their defining file is included, so anything
// defined ahead of it can never be overridden per-test afterwards; Brain
// Monkey only requires it lazily inside Monkey\setUp(), by which point the
// stubs below exist. Symptom otherwise: Patchwork\Exceptions\DefinedTooEarly.
Bootstrap::loadPatchwork();

WpState::$pluginSlug = 'trusted';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

// This plugin's own stubs: the WP_REST_* classes, and recording registrars
// whose $GLOBALS the tests assert on directly. They come *before* the shared
// layer so those keep winning — the shared register_post_type() and
// register_rest_route() record somewhere else.
require_once __DIR__ . '/wp-stubs.php';

// The shared stub layer, loaded last as a backstop for everything above it
// does not define — __(), the escaping helpers, the option store. Every
// definition in it is function_exists()-guarded.
//
// Note what is *not* in it: add_action, add_filter and the rest of the hook
// layer, which Brain Monkey owns and defines inside its own setUp(). That is
// why the WordPress-coupled tests must extend Trusted\Tests\TestCase.
//
// The `acf` group is deliberately left out. TemplateApplicator::fieldValue()
// and writeFieldValue() branch on function_exists('get_field') /
// ('update_field') and fall back to post meta when ACF is absent — which is
// the branch this suite covers, stubbing get_post_meta()/update_post_meta().
// Loading the group would define those functions, silently sending every
// template test down the untested ACF path instead. acf_add_validation_error()
// needs no stub either: Brain Monkey defines a function outright when a test
// sets an expectation on it.
Bootstrap::load(['wordpress']);

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
    require_once dirname(__DIR__, 2) . '/unity/src/Members/Interfaces/MemberRepository.php';

    // Unity's container contract, which TrustedServiceProvider registers against.
    $unityContainer = dirname(__DIR__, 2) . '/unity/src/Core/Interfaces/Container.php';
    if (is_file($unityContainer) && !interface_exists(\Unity\Core\Interfaces\Container::class)) {
        require_once $unityContainer;
    }
} elseif (!interface_exists(\Unity\Members\Interfaces\Member::class)) {
    eval(<<<'PHP'
namespace Unity\Members;

enum ResponderCertification: string
{
    case None = 'None';
    case Applied = 'Applied';
    case InTraining = 'In Training';
    case Pending = 'Pending';
    case Certified = 'Certified';
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

interface MemberRepository
{
    public function findById(int $id): ?Member;
    public function findByEmail(string $email): ?Member;
    public function findAll(array $args = []): array;
    public function findTelephoneResponders(): array;
    public function count(array $args = []): int;
    public function create(string $anonymousName): int;
    public function save(Member $member): bool;
    public function delete(int $id): bool;
    public function update(Member $member): bool;
}
PHP
    );
}
