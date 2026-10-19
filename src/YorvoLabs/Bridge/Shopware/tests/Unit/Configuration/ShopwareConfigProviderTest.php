<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use YorvoLabs\Canary\Bridge\Shopware\Configuration\ShopwareConfigProvider;

final class ShopwareConfigProviderTest extends TestCase
{
    #[Test]
    public function it_returns_empty_config_when_no_plugin_settings_are_set(): void
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturn(null);

        $provider = new ShopwareConfigProvider($systemConfig);

        self::assertSame([], $provider->getConfig());
    }

    #[Test]
    public function it_maps_shopware_keys_to_env_var_names(): void
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['YorvoLabsCanary.config.tokenMonitoring', null, 'monitoring-token-from-admin-' . str_repeat('a', 36)],
            ['YorvoLabsCanary.config.queueThreshold',  null, 50],
        ]);

        $provider = new ShopwareConfigProvider($systemConfig);
        $config   = $provider->getConfig();

        self::assertArrayHasKey('CANARY_TOKEN_MONITORING', $config);
        self::assertArrayHasKey('CANARY_DEPLOY_QUEUE_THRESHOLD', $config);
        self::assertSame(50, $config['CANARY_DEPLOY_QUEUE_THRESHOLD']);
    }

    #[Test]
    public function it_omits_null_and_empty_values(): void
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['YorvoLabsCanary.config.tokenMonitoring', null, null],
            ['YorvoLabsCanary.config.tokenCache',      null, ''],
            ['YorvoLabsCanary.config.queueThreshold',  null, 25],
        ]);

        $provider = new ShopwareConfigProvider($systemConfig);
        $config   = $provider->getConfig();

        self::assertArrayNotHasKey('CANARY_TOKEN_MONITORING', $config);
        self::assertArrayNotHasKey('CANARY_TOKEN_CACHE', $config);
        self::assertArrayHasKey('CANARY_DEPLOY_QUEUE_THRESHOLD', $config);
    }

    #[Test]
    public function it_covers_all_expected_env_var_keys(): void
    {
        $expected = [
            'CANARY_TOKEN_MONITORING',
            'CANARY_TOKEN_CACHE',
            'CANARY_TOKEN_DEPLOY',
            'CANARY_TOKEN_ADMIN',
            'CANARY_ALLOWED_CIDRS',
            'CANARY_DEPLOY_QUEUE_THRESHOLD',
            'CANARY_DEPLOY_FAILED_THRESHOLD',
            'CANARY_RATE_LIMIT_HEALTH',
            'CANARY_RATE_LIMIT_MUTATION',
        ];

        // Return a non-empty value for every key so none are filtered out.
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturn('value');

        $provider = new ShopwareConfigProvider($systemConfig);
        $config   = $provider->getConfig();

        foreach ($expected as $key) {
            self::assertArrayHasKey($key, $config, "Expected env key '{$key}' to be present in provider config");
        }
    }
}
