<?php

declare(strict_types=1);

namespace Trusted\Tests;

use BleedingDeacons\WpMocks\TestCase as WpMocksTestCase;

/**
 * Base TestCase for the WordPress-coupled tests.
 *
 * Brain Monkey's lifecycle, Mockery integration, the WordPress stubs and the
 * hook assertions all come from bleedingdeacons/wp-mocks, shared across the
 * plugin suite.
 *
 * The pure-PHP tests (template grammar, domain, factories, sign-up rules)
 * extend PHPUnit's TestCase directly — they need none of this. That split is
 * load-bearing in a way it was not before: add_action() and add_filter() now
 * belong to Brain Monkey, which defines them inside its own setUp(), so a test
 * that reaches WordPress-registering code has to come through here.
 */
abstract class TestCase extends WpMocksTestCase
{
}
