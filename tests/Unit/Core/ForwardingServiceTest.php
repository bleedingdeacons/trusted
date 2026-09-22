<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Core;

use Beacon\Forwarding\ForwardingRegistry;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Mockery;
use Trusted\Plugin;

beforeEach(fn () => ForwardingRegistry::clear());
afterEach(fn () => ForwardingRegistry::clear());

it('has no forwarding service when no driver is bound', function () {
    expect(Plugin::instance()->forwardingService())->toBeNull();
});

it('resolves whatever driver is bound in the Beacon registry', function () {
    $driver = Mockery::mock(CallForwardingService::class);
    ForwardingRegistry::bind(fn (): CallForwardingService => $driver);

    expect(Plugin::instance()->forwardingService())->toBe($driver);
});
