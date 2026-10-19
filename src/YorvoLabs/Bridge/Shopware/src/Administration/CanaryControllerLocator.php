<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Administration;

use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\OpcacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\ShopwareCacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\ConfigController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\EnvController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\LogController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\PluginsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\QueueController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\MetricsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\ReadinessController;

/**
 * Groups all Canary sub-controllers injected into CanaryAdminController,
 * reducing the admin controller constructor from 15 arguments to 7.
 */
final class CanaryControllerLocator
{
    public function __construct(
        public readonly EnvController $env,
        public readonly ConfigController $config,
        public readonly LogController $log,
        public readonly QueueController $queue,
        public readonly PluginsController $plugins,
        public readonly ReadinessController $readiness,
        public readonly MetricsController $metrics,
        public readonly OpcacheController $opcache,
        public readonly ShopwareCacheController $shopwareCache,
    ) {}
}
