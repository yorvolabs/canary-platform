<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Configuration;

use YorvoLabs\Canary\Auth\ScopedToken;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Contract\ConfigProviderInterface;
use YorvoLabs\Canary\Exception\RuntimeException;

/**
 * Immutable configuration value object.
 *
 * Built by the platform layer (DI extension in the Shopware bridge) from
 * environment variables and/or plugin config. The core library never reads
 * env vars directly — all configs arrive through this object.
 *
 * The two-tier model from the PRD:
 *   1. Environment variables (primary — CI/CD, infrastructure-as-code)
 *   2. Shopware plugin config (fallback — shared hosting)
 *
 * fromEnv() is provided as a convenience for standalone use and testing.
 */
final class Options
{
    /**
     * @param array<string, ScopedToken> $tokens           Only configured scopes; absent = not set.
     * @param list<string>               $allowedCidrs
     * @param list<string>               $enabledFeatures
     * @param array<string, int>         $rateLimits        Keyed by rate-limit group name.
     * @param array<string, int>         $deployThresholds  Keyed by threshold name.
     */
    private function __construct(
        private readonly bool $production,
        private readonly string $routePrefix,
        private readonly array $tokens,
        private readonly array $allowedCidrs,
        private readonly array $enabledFeatures,
        private readonly array $rateLimits,
        private readonly array $deployThresholds,
    ) {}

    /**
     * Build from a flat key-value array. The bridge DI extension calls this
     * after merging env vars with plugin config.
     *
     * @param array<string, mixed> $config
     * @throws RuntimeException if a token value is present but too short
     */
    public static function fromArray(array $config): self
    {
        $tokens = [];
        foreach (TokenScope::cases() as $scope) {
            $raw = $config[$scope->envVar()] ?? null;

            if ($raw === null || $raw === '') {
                continue;
            }

            // Fail loudly — a token that is present but too short is a
            // misconfiguration, not a missing value. Silently ignoring it
            // would leave an endpoint unprotected without any indication.
            $tokens[$scope->value] = ScopedToken::fromEnv($scope, (string) $raw);
        }

        return new self(
            production: ($config['APP_ENV'] ?? 'prod') === 'prod',
            routePrefix: RoutePrefix::fromString((string) ($config['CANARY_ROUTE_PREFIX'] ?? ''))->withLeadingSlash(),
            tokens: $tokens,
            allowedCidrs: self::splitCsvConfig($config, 'CANARY_ALLOWED_CIDRS'),
            enabledFeatures: self::splitCsvConfig($config, 'CANARY_FEATURES'),
            rateLimits: [
                RateLimitGroup::HEALTH->value   => (int) ($config['CANARY_RATE_LIMIT_HEALTH'] ?? 60),
                RateLimitGroup::MUTATION->value => (int) ($config['CANARY_RATE_LIMIT_MUTATION'] ?? 10),
            ],
            deployThresholds: [
                DeployThreshold::QUEUE->value  => (int) ($config['CANARY_DEPLOY_QUEUE_THRESHOLD'] ?? 100),
                DeployThreshold::FAILED->value => (int) ($config['CANARY_DEPLOY_FAILED_THRESHOLD'] ?? 0),
            ],
        );
    }

    /**
     * Convenience factory that reads directly from $_ENV / getenv().
     * Useful for standalone use and tests without a DI container.
     */
    public static function fromEnv(): self
    {
        return self::fromArray(self::readEnvArray());
    }

    /**
     * Builds Options by merging environment variables with a higher-priority config
     * provider (e.g. Shopware admin UI config). Provider values override env vars.
     */
    public static function fromProvider(ConfigProviderInterface $provider): self
    {
        $envConfig      = self::readEnvArray();
        $providerConfig = array_filter(
            $provider->getConfig(),
            fn(string|int|bool $v) => $v !== '',
        );

        return self::fromArray(array_merge($envConfig, $providerConfig));
    }

    /** @return array<string, mixed> */
    private static function readEnvArray(): array
    {
        $keys = [
            'APP_ENV',
            'CANARY_ROUTE_PREFIX',
            'CANARY_ALLOWED_CIDRS',
            'CANARY_RATE_LIMIT_HEALTH',
            'CANARY_RATE_LIMIT_MUTATION',
            'CANARY_FEATURES',
            'CANARY_DEPLOY_QUEUE_THRESHOLD',
            'CANARY_DEPLOY_FAILED_THRESHOLD',
            ...array_map(fn(TokenScope $s) => $s->envVar(), TokenScope::cases()),
        ];

        $config = [];
        foreach ($keys as $key) {
            $envValue = getenv($key);
            $value    = $_ENV[$key] ?? ($envValue !== false ? $envValue : null);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    public function isProduction(): bool
    {
        return $this->production;
    }

    /**
     * URL prefix for all Canary endpoints (e.g. "/canary" or "/ops").
     * Normalised to have a leading slash and no trailing slash.
     * Defaults to RoutePrefix::DEFAULT when CANARY_ROUTE_PREFIX is not set.
     */
    public function getRoutePrefix(): string
    {
        return $this->routePrefix;
    }

    /** @return list<string> */
    public function getAllowedCidrs(): array
    {
        return $this->allowedCidrs;
    }

    public function getToken(TokenScope $scope): ScopedToken|null
    {
        return $this->tokens[$scope->value] ?? null;
    }

    /** @return array<string, ScopedToken> */
    public function getTokenMap(): array
    {
        return $this->tokens;
    }

    public function getRateLimit(RateLimitGroup $group): int
    {
        return $this->rateLimits[$group->value];
    }

    public function getDeployThreshold(DeployThreshold $key): int
    {
        return $this->deployThresholds[$key->value];
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private static function splitCsvConfig(array $config, string $key): array
    {
        $raw = (string) ($config[$key] ?? '');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isFeatureEnabled(Feature $feature): bool
    {
        // Empty list = all features enabled.
        if ($this->enabledFeatures === []) {
            return true;
        }

        return in_array($feature->value, $this->enabledFeatures, strict: true);
    }
}
