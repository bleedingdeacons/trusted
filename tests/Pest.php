<?php

declare(strict_types=1);

// Pest configuration.
//
// The split tests/TestCase.php describes still holds: a test that reaches
// WordPress-registering code has to run on wp-mocks' TestCase, because
// add_action() and add_filter() belong to Brain Monkey, which only defines
// them inside that TestCase's setUp(). The pure-PHP tests — template grammar,
// domain, factories, sign-up rules, the direct-access sweep — need none of
// it and stay on Pest's default, plain PHPUnit.
//
// So this list is load-bearing. A new WordPress-coupled test file belongs in
// one of these directories, or has to be named here.

use Trusted\Tests\TestCase;

pest()->extend(TestCase::class)->in(
    'Unit/Admin',
    'Unit/Core',
    'Unit/Http',
    'Unit/Repository',
    'Unit/Support',
    'Unit/Domain/RotaArrayTest.php',
    'Unit/Template/TemplateApplicatorTest.php',
    'Unit/Template/TemplateFieldsTest.php',
    'Unit/Template/TemplatePostTypeTest.php',
    'Unit/Template/TemplateValidatorTest.php',
);

/**
 * Runs $render inside an output buffer and returns what it printed.
 *
 * The admin screens echo their markup, so this is how their tests read it.
 * The buffer is closed in a finally, so a render that throws — wp_die() is a
 * WpDieException under the shared stubs — cannot leave it open and have
 * PHPUnit flag the test as risky.
 */
function captureOutput(callable $render): string
{
    ob_start();

    try {
        $render();
    } finally {
        $html = (string) ob_get_clean();
    }

    return $html;
}
