<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Core;

use Mockery;
use Psr\Container\ContainerInterface;
use Trusted\Core\TrustedServiceProvider;
use Trusted\Http\RestController;
use Trusted\Http\SignupController;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplatePostType;
use Trusted\Template\TemplateValidator;
use Trusted\Service\ShiftSignup;
use Trusted\Tests\Fixtures\InMemoryMemberRepository;
use Trusted\Tests\TestCase;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;

/**
 * @covers \Trusted\Core\TrustedServiceProvider
 */
final class TrustedServiceProviderTest extends TestCase
{
    public function testRegisterWiresEveryServiceResolvable(): void
    {
        // Repositories construct against $wpdb; a bare mock is enough since we
        // only resolve them, never query.
        $wpdb = Mockery::mock('wpdb');
        $wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $wpdb;

        $container = new FakeUnityContainer([
            MemberRepository::class => new InMemoryMemberRepository(),
        ]);

        (new TrustedServiceProvider())->register($container);

        // Resolving each service invokes its registration closure.
        self::assertInstanceOf(TemplateApplicator::class, $container->get(TemplateApplicator::class));
        self::assertInstanceOf(TemplateValidator::class, $container->get(TemplateValidator::class));
        self::assertInstanceOf(ShiftSignup::class, $container->get(ShiftSignup::class));
        self::assertInstanceOf(RestController::class, $container->get(RestController::class));
        self::assertInstanceOf(SignupController::class, $container->get(SignupController::class));
        self::assertInstanceOf(TemplatePostType::class, $container->get(TemplatePostType::class));
    }
}

/** Minimal Unity container: leaf presets + registered factories, resolved once. */
final class FakeUnityContainer implements Container
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @param array<string, mixed> $presets */
    public function __construct(private array $presets = [])
    {
    }

    public function register(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->presets)) {
            return $this->presets[$id];
        }

        return $this->presets[$id] = ($this->factories[$id])($this);
    }

    public function has(string $id): bool
    {
        return isset($this->factories[$id]) || array_key_exists($id, $this->presets);
    }
}
