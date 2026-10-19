<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Routing;

use Shopware\Core\Framework\Routing\RouteScopeWhitelistInterface;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\AdapterFlushController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\OpcacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\ShopwareCacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\MaintenanceController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\PostDeployController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\PreDeployController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\ConfigController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\EnvController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\LogController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\PluginsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\QueueController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\LivenessController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\MetricsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\ReadinessController;

/**
 * Bypasses Shopware's route-scope validation for Canary controllers.
 *
 * Canary endpoints don't belong to any Shopware sales channel or API context,
 * so the normal scope check (api / storefront) would always fail. This whitelist
 * tells RouteScopeListener to skip scope validation for our controllers entirely.
 * Auth is handled by RequestGuardSubscriber before any controller is invoked.
 */
final class RouteScopeWhitelist implements RouteScopeWhitelistInterface
{
    private const CANARY_CONTROLLERS = [
        LivenessController::class,
        ReadinessController::class,
        MetricsController::class,
        OpcacheController::class,
        ShopwareCacheController::class,
        AdapterFlushController::class,
        PreDeployController::class,
        MaintenanceController::class,
        PostDeployController::class,
        EnvController::class,
        ConfigController::class,
        LogController::class,
        QueueController::class,
        PluginsController::class,
    ];

    public function applies(string $controllerClass): bool
    {
        return in_array($controllerClass, self::CANARY_CONTROLLERS, strict: true);
    }
}
