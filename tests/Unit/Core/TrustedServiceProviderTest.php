<?php

declare(strict_types=1);

namespace Trusted\Tests\Unit\Core;

use Mockery;
use Trusted\Core\TrustedServiceProvider;
use Trusted\Http\RestController;
use Trusted\Http\SignupController;
use Trusted\Service\ShiftSignup;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplatePostType;
use Trusted\Template\TemplateValidator;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Testing\Doubles\FakeContainer;
use Unity\Testing\Doubles\InMemoryMemberRepository;

covers(TrustedServiceProvider::class);

it('wires every service so it resolves', function (string $service) {
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

    // Resolving the service invokes its registration closure.
    expect($container->get($service))->toBeInstanceOf($service);
})->with([
    TemplateApplicator::class,
    TemplateValidator::class,
    ShiftSignup::class,
    RestController::class,
    SignupController::class,
    TemplatePostType::class,
]);
