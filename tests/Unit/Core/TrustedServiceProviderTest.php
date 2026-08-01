<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Core;

use Mockery;
use Trusted\Core\TrustedServiceProvider;
use Trusted\Http\RestController;
use Trusted\Http\SignupController;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplatePostType;
use Trusted\Template\TemplateValidator;
use Trusted\Service\ShiftSignup;
use Trusted\Tests\TestCase;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\FakeContainer;
use Unity\Testing\Doubles\InMemoryMemberRepository;

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

        // Unity would supply the member repository; everything else is
        // Trusted's own and comes from the registrations under test.
        $container = new FakeContainer([
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
