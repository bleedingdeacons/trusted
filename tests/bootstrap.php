<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap for Trusted.
 *
 * The suite covers the parts of the plugin that are pure PHP: the template
 * grammar, the domain value objects, the row-to-object factories and the
 * sign-up service. None of them touch WordPress, so no WP test harness is
 * needed — only Unity, whose interfaces ShiftSignup, MemberPresenter and
 * TrustedServiceProvider type-hint, and whose Unity\Testing\Doubles the
 * fixtures build on.
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
//  Unity
//
//  Loaded from the sibling checkout — the layout CI arranges (see the
//  "Checkout Unity" step in ci.yml) and the one a developer working across
//  the suite has. Registering a PSR-4 autoloader over the whole tree, rather
//  than requiring three named files, is what makes Unity\Testing\Doubles
//  reachable: the fixtures below extend the Member stub Unity ships.
//
//  There used to be an eval() fallback here defining Member, MemberRepository
//  and ResponderCertification inline, so the suite would run from a bare
//  clone of this repo alone. It is gone, and deliberately:
//
//    - it was a hand-copy of a contract owned elsewhere, kept in step by
//      discipline. Its own comment said "the stub must stay in step with
//      Unity's real interface ... which is exactly how Reach's suite came to
//      be broken";
//    - it never ran in CI, which always has ../unity, so nothing would have
//      caught it going stale;
//    - the doubles now come from Unity too, and no hand-copied interface can
//      supply those.
//
//  A bare clone therefore fails fast, with the message below, instead of
//  quietly testing against a contract nobody maintains.
// ──────────────────────────────────────────────
$unitySrc = dirname(__DIR__, 2) . '/unity/src';

if (!is_dir($unitySrc)) {
    fwrite(STDERR, PHP_EOL . 'ERROR: Unity plugin source not found at ' . $unitySrc . PHP_EOL
        . "Trusted is built on Unity's interfaces and test doubles, so the Unity" . PHP_EOL
        . 'plugin must be checked out as a sibling directory for this suite to run.' . PHP_EOL . PHP_EOL);
    exit(1);
}

spl_autoload_register(static function (string $class) use ($unitySrc): void {
    if (!str_starts_with($class, 'Unity\\')) {
        return;
    }

    $file = $unitySrc . '/' . str_replace('\\', '/', substr($class, strlen('Unity\\'))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});
