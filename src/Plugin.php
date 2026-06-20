<?php

declare(strict_types=1);

namespace Trusted;

use Trusted\Admin\Assets;
use Trusted\Admin\CalendarPage;
use Trusted\Admin\DeveloperPage;
use Trusted\Http\RestController;
use Trusted\Http\SignupController;
use Trusted\Template\TemplateFields;
use Trusted\Template\TemplatePostType;
use Trusted\Template\TemplateValidator;
use Beacon\Forwarding\Interfaces\CallForwardingService;
use Psr\Container\ContainerInterface as BeaconContainer;
use Unity\Core\Interfaces\Container;

/**
 * Bootstraps Trusted on top of Unity.
 *
 * Trusted registers its services into Unity's container (see
 * TrustedServiceProvider, wired on `unity/register_services`) and boots once
 * Unity is fully loaded (`unity/loaded`). All Trusted services — including the
 * member data, which comes from Unity's MemberRepository — are resolved from
 * Unity's container. Trusted has no container of its own.
 */
final class Plugin
{
    private static ?Plugin $instance = null;

    private ?Container $container = null;

    /**
     * Beacon's PSR-11 container, captured on `beacon/loaded`. Beacon's
     * forwarding API is private (in-process only), so this handle is how
     * Trusted reaches it — never over HTTP. Null until Beacon boots.
     */
    private ?BeaconContainer $beaconContainer = null;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
    }

    /**
     * Wire WordPress hooks. Called on `unity/loaded` with Unity's container.
     *
     * Runs during `plugins_loaded`, so registering `init`, `admin_menu`,
     * `rest_api_init`, etc. here is in good time for those hooks to fire.
     */
    public function boot(Container $container): void
    {
        $this->container = $container;

        load_plugin_textdomain('trusted', false, dirname(plugin_basename(\TRUSTED_FILE)) . '/languages');

        add_action('init', static function (): void {
            (new TemplatePostType())->register();
        });

        add_action('acf/init', [new TemplateFields(), 'register']);

        // Reject template saves that name an unknown member or a non-responder,
        // so a template can never carry a name that won't resolve on apply.
        add_action('acf/validate_save_post', static function () use ($container): void {
            $container->get(TemplateValidator::class)->validate();
        });

        add_action('admin_menu', [new CalendarPage(), 'registerMenu']);
        add_action('admin_enqueue_scripts', [new Assets(), 'enqueue']);

        $developerPage = new DeveloperPage($container);
        add_action('admin_menu', [$developerPage, 'registerMenu']);
        add_action('admin_post_trusted_delete_week', [$developerPage, 'handleDeleteWeek']);
        add_action('admin_post_trusted_clear_all', [$developerPage, 'handleClearAll']);

        add_action('rest_api_init', function () use ($container): void {
            $container->get(RestController::class)->registerRoutes();
            $container->get(SignupController::class)->registerRoutes();
        });
    }

    /**
     * Unity's container, available after boot().
     */
    public function container(): ?Container
    {
        return $this->container;
    }

    /**
     * Adopt Beacon's container. Called on `beacon/loaded` (see
     * trusted.php) with the PSR-11 container Beacon passes to consumers.
     */
    public function useBeaconContainer(BeaconContainer $container): void
    {
        $this->beaconContainer = $container;
    }

    /**
     * The active call-forwarding service, or null when no Beacon driver
     * (e.g. Tamar) is bound.
     *
     * Resolved lazily — and re-checked on every call — so the result
     * reflects driver state at the moment Trusted needs it, not plugin
     * load order. Drivers bind `CallForwardingService` on `beacon/loaded`
     * too, so an eager resolve here could race ahead of them.
     *
     * Callers must enforce their own capability check (e.g.
     * `beacon_manage_forwarding`) before mutating forwarding — the
     * private service does no permission gating of its own.
     */
    public function forwardingService(): ?CallForwardingService
    {
        if ($this->beaconContainer === null
            || ! $this->beaconContainer->has(CallForwardingService::class)) {
            return null;
        }

        return $this->beaconContainer->get(CallForwardingService::class);
    }
}
