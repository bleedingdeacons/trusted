<?php

declare(strict_types=1);

namespace Trusted\Core;

// Prevent direct access
if (! defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Factory\AssignmentFactory;
use Trusted\Factory\RotaFactory;
use Trusted\Http\RestController;
use Trusted\Http\SignupController;
use Trusted\Repository\AssignmentRepository;
use Trusted\Repository\RotaRepository;
use Trusted\Service\ShiftSignup;
use Trusted\Support\ResponderDirectory;
use Trusted\Template\TemplateApplicator;
use Trusted\Template\TemplateParser;
use Trusted\Template\TemplatePostType;
use Trusted\Template\TemplateValidator;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;

/**
 * Trusted Service Provider
 *
 * Registers all Trusted services into Unity's dependency container, following
 * the same pattern as Unity\Core\UnityServiceProvider and AmberServiceProvider.
 * Trusted's repositories and controller resolve Unity's MemberRepository from
 * the same container — Trusted no longer carries its own member factory.
 *
 * Wired up on the `unity/register_services` hook (see trusted.php).
 */
class TrustedServiceProvider
{
    /**
     * Register all Trusted services in Unity's container.
     *
     * @param Container $container The Unity dependency container
     * @return void
     */
    public function register(Container $container): void
    {
        $container->register(RotaFactoryInterface::class, static function (ContainerInterface $c): RotaFactory {
            return new RotaFactory();
        });

        $container->register(AssignmentFactoryInterface::class, static function (ContainerInterface $c): AssignmentFactory {
            return new AssignmentFactory();
        });

        $container->register(AssignmentRepositoryInterface::class, static function (ContainerInterface $c): AssignmentRepository {
            return new AssignmentRepository(
                $c->get(AssignmentFactoryInterface::class),
                $c->get(MemberRepository::class)
            );
        });

        $container->register(RotaRepositoryInterface::class, static function (ContainerInterface $c): RotaRepository {
            return new RotaRepository(
                $c->get(RotaFactoryInterface::class),
                $c->get(AssignmentRepositoryInterface::class)
            );
        });

        $container->register(TemplateParser::class, static function (ContainerInterface $c): TemplateParser {
            return new TemplateParser();
        });

        $container->register(ResponderDirectory::class, static function (ContainerInterface $c): ResponderDirectory {
            return new ResponderDirectory($c->get(MemberRepository::class));
        });

        $container->register(TemplateApplicator::class, static function (ContainerInterface $c): TemplateApplicator {
            return new TemplateApplicator(
                $c->get(RotaRepositoryInterface::class),
                $c->get(RotaFactoryInterface::class),
                $c->get(AssignmentRepositoryInterface::class),
                $c->get(AssignmentFactoryInterface::class),
                $c->get(ResponderDirectory::class),
                $c->get(TemplateParser::class)
            );
        });

        $container->register(TemplateValidator::class, static function (ContainerInterface $c): TemplateValidator {
            return new TemplateValidator(
                $c->get(ResponderDirectory::class),
                $c->get(TemplateParser::class)
            );
        });

        $container->register(ShiftSignup::class, static function (ContainerInterface $c): ShiftSignup {
            return new ShiftSignup(
                $c->get(RotaRepositoryInterface::class),
                $c->get(AssignmentRepositoryInterface::class)
            );
        });

        $container->register(RestController::class, static function (ContainerInterface $c): RestController {
            return new RestController(
                $c->get(RotaRepositoryInterface::class),
                $c->get(AssignmentRepositoryInterface::class),
                $c->get(MemberRepository::class),
                $c->get(TemplateApplicator::class),
                $c->get(RotaFactoryInterface::class),
                $c->get(ShiftSignup::class)
            );
        });

        $container->register(SignupController::class, static function (ContainerInterface $c): SignupController {
            return new SignupController($c->get(ShiftSignup::class));
        });

        $container->register(TemplatePostType::class, static function (ContainerInterface $c): TemplatePostType {
            return new TemplatePostType();
        });
    }
}
