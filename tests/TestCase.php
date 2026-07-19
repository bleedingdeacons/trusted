<?php

declare(strict_types=1);

namespace Trusted\Tests;

use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WP_Mock;

/**
 * Base TestCase for the WordPress-coupled tests.
 *
 * Extends PHPUnit's TestCase and drives WP_Mock by hand, matching Unity,
 * Scrutiny, Integrity and tsml-for-unity. WP_Mock\Tools\TestCase is not used
 * anywhere in the suite: it overrides expectOutputString(), which PHPUnit made
 * final, so merely autoloading it fatals.
 *
 * The pure-PHP tests (template grammar, domain, factories, sign-up rules)
 * extend PHPUnit's TestCase directly — they need none of this.
 */
abstract class TestCase extends PHPUnitTestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        WP_Mock::setUp();
    }

    protected function tearDown(): void
    {
        WP_Mock::tearDown();
        Mockery::close();
        parent::tearDown();
    }
}
