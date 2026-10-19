<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

/**
 * Provides a higher-priority configuration source that overlays environment variables.
 *
 * Implement this interface to let a bridge (e.g. Shopware admin UI) supply config
 * values that take precedence over .env files without requiring server access.
 *
 * The returned map must use the same key names as the corresponding environment
 * variables (e.g. 'CANARY_TOKEN_MONITORING', 'CANARY_ALLOWED_CIDRS').
 * Only non-null, non-empty values need to be returned — omitted keys fall back
 * to the environment variable of the same name.
 *
 * In the Shopware bridge, tag your service with `canary.config_provider` and
 * wire it via Options::fromProvider().
 */
interface ConfigProviderInterface
{
    /**
     * @return array<string, string|int|bool>
     */
    public function getConfig(): array;
}
