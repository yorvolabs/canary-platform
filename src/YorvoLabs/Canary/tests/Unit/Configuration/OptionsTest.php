<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Configuration\DeployThreshold;
use YorvoLabs\Canary\Configuration\Feature;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Configuration\RateLimitGroup;
use YorvoLabs\Canary\Exception\RuntimeException;

final class OptionsTest extends TestCase
{
    private const VALID_TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    // ------------------------------------------------------------------
    // Token parsing
    // ------------------------------------------------------------------

    #[Test]
    public function it_parses_a_valid_token(): void
    {
        $config = Options::fromArray([
            'CANARY_TOKEN_MONITORING' => self::VALID_TOKEN,
        ]);

        self::assertNotNull($config->getToken(TokenScope::MONITORING));
    }

    #[Test]
    public function it_throws_when_a_token_is_present_but_too_short(): void
    {
        $this->expectException(RuntimeException::class);

        Options::fromArray([
            'CANARY_TOKEN_MONITORING' => 'too-short',
        ]);
    }

    #[Test]
    public function it_treats_empty_token_value_as_not_configured(): void
    {
        $config = Options::fromArray([
            'CANARY_TOKEN_MONITORING' => '',
        ]);

        self::assertNull($config->getToken(TokenScope::MONITORING));
    }

    #[Test]
    public function it_treats_absent_token_as_not_configured(): void
    {
        $config = Options::fromArray([]);

        self::assertNull($config->getToken(TokenScope::MONITORING));
    }

    #[Test]
    public function it_builds_a_sparse_token_map_with_only_configured_scopes(): void
    {
        $config = Options::fromArray([
            'CANARY_TOKEN_MONITORING' => self::VALID_TOKEN,
        ]);

        $map = $config->getTokenMap();

        self::assertArrayHasKey(TokenScope::MONITORING->value, $map);
        self::assertArrayNotHasKey(TokenScope::CACHE->value, $map);
    }

    // ------------------------------------------------------------------
    // Environment detection
    // ------------------------------------------------------------------

    #[Test]
    public function it_detects_production_environment(): void
    {
        self::assertTrue(Options::fromArray(['APP_ENV' => 'prod'])->isProduction());
    }

    #[Test]
    public function it_detects_non_production_environment(): void
    {
        self::assertFalse(Options::fromArray(['APP_ENV' => 'dev'])->isProduction());
        self::assertFalse(Options::fromArray(['APP_ENV' => 'test'])->isProduction());
    }

    #[Test]
    public function it_defaults_to_production_when_app_env_is_absent(): void
    {
        self::assertTrue(Options::fromArray([])->isProduction());
    }

    // ------------------------------------------------------------------
    // Rate limits
    // ------------------------------------------------------------------

    #[Test]
    public function it_returns_configured_rate_limits(): void
    {
        $config = Options::fromArray([
            'CANARY_RATE_LIMIT_HEALTH'   => '30',
            'CANARY_RATE_LIMIT_MUTATION' => '5',
        ]);

        self::assertSame(30, $config->getRateLimit(RateLimitGroup::HEALTH));
        self::assertSame(5, $config->getRateLimit(RateLimitGroup::MUTATION));
    }

    #[Test]
    public function it_returns_default_rate_limits_when_not_configured(): void
    {
        $config = Options::fromArray([]);

        self::assertSame(60, $config->getRateLimit(RateLimitGroup::HEALTH));
        self::assertSame(10, $config->getRateLimit(RateLimitGroup::MUTATION));
    }

    // ------------------------------------------------------------------
    // Deploy thresholds
    // ------------------------------------------------------------------

    #[Test]
    public function it_returns_default_deploy_thresholds_when_not_configured(): void
    {
        $config = Options::fromArray([]);

        self::assertSame(100, $config->getDeployThreshold(DeployThreshold::QUEUE));
        self::assertSame(0, $config->getDeployThreshold(DeployThreshold::FAILED));
    }

    #[Test]
    public function it_returns_configured_deploy_thresholds(): void
    {
        $config = Options::fromArray([
            'CANARY_DEPLOY_QUEUE_THRESHOLD'  => '50',
            'CANARY_DEPLOY_FAILED_THRESHOLD' => '5',
        ]);

        self::assertSame(50, $config->getDeployThreshold(DeployThreshold::QUEUE));
        self::assertSame(5, $config->getDeployThreshold(DeployThreshold::FAILED));
    }

    #[Test]
    public function all_deploy_threshold_cases_are_accessible(): void
    {
        $config = Options::fromArray([]);

        foreach (DeployThreshold::cases() as $threshold) {
            self::assertGreaterThanOrEqual(0, $config->getDeployThreshold($threshold));
        }
    }

    // ------------------------------------------------------------------
    // Feature flags
    // ------------------------------------------------------------------

    #[Test]
    public function it_enables_all_features_when_canary_features_is_empty(): void
    {
        $config = Options::fromArray([]);

        foreach (Feature::cases() as $feature) {
            self::assertTrue($config->isFeatureEnabled($feature));
        }
    }

    #[Test]
    public function it_enables_only_the_configured_features(): void
    {
        $config = Options::fromArray([
            'CANARY_FEATURES' => 'health,cache',
        ]);

        self::assertTrue($config->isFeatureEnabled(Feature::HEALTH));
        self::assertTrue($config->isFeatureEnabled(Feature::CACHE));
        self::assertFalse($config->isFeatureEnabled(Feature::DIAGNOSTICS));
        self::assertFalse($config->isFeatureEnabled(Feature::DEPLOY));
    }

    #[Test]
    public function it_trims_whitespace_from_feature_flag_values(): void
    {
        $config = Options::fromArray([
            'CANARY_FEATURES' => ' health , cache ',
        ]);

        self::assertTrue($config->isFeatureEnabled(Feature::HEALTH));
        self::assertTrue($config->isFeatureEnabled(Feature::CACHE));
    }

    // ------------------------------------------------------------------
    // CIDR parsing
    // ------------------------------------------------------------------

    #[Test]
    public function it_parses_allowed_cidrs(): void
    {
        $config = Options::fromArray([
            'CANARY_ALLOWED_CIDRS' => '127.0.0.1/32,10.0.0.0/8',
        ]);

        self::assertSame(['127.0.0.1/32', '10.0.0.0/8'], $config->getAllowedCidrs());
    }

    #[Test]
    public function it_returns_empty_cidr_list_when_not_configured(): void
    {
        self::assertSame([], Options::fromArray([])->getAllowedCidrs());
    }
}
