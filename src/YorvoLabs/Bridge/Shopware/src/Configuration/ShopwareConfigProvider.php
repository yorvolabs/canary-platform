<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Configuration;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use YorvoLabs\Canary\Contract\ConfigProviderInterface;

/**
 * Reads Canary configuration from the Shopware admin plugin config page.
 *
 * Values set in the admin UI take precedence over environment variables when
 * Options::fromProvider() is used. Any field left blank in the admin UI is
 * omitted from the returned map, so the env var fallback is preserved.
 *
 * Admin config keys follow the convention: YorvoLabsCanary.config.{fieldName}
 * and are mapped to their equivalent env var names.
 */
final class ShopwareConfigProvider implements ConfigProviderInterface
{
    private const CONFIG_MAP = [
        'YorvoLabsCanary.config.tokenMonitoring'   => 'CANARY_TOKEN_MONITORING',
        'YorvoLabsCanary.config.tokenCache'        => 'CANARY_TOKEN_CACHE',
        'YorvoLabsCanary.config.tokenDeploy'       => 'CANARY_TOKEN_DEPLOY',
        'YorvoLabsCanary.config.tokenAdmin'        => 'CANARY_TOKEN_ADMIN',
        'YorvoLabsCanary.config.allowedCidrs'      => 'CANARY_ALLOWED_CIDRS',
        'YorvoLabsCanary.config.queueThreshold'    => 'CANARY_DEPLOY_QUEUE_THRESHOLD',
        'YorvoLabsCanary.config.failedThreshold'   => 'CANARY_DEPLOY_FAILED_THRESHOLD',
        'YorvoLabsCanary.config.rateLimitHealth'   => 'CANARY_RATE_LIMIT_HEALTH',
        'YorvoLabsCanary.config.rateLimitMutation' => 'CANARY_RATE_LIMIT_MUTATION',
    ];

    public function __construct(
        private readonly SystemConfigService $systemConfig,
    ) {}

    public function getConfig(): array
    {
        $config = [];

        foreach (self::CONFIG_MAP as $shopwareKey => $envKey) {
            $value = $this->systemConfig->get($shopwareKey);

            if (!is_string($value) && !is_int($value) && !is_bool($value)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $config[$envKey] = $value;
        }

        return $config;
    }
}
