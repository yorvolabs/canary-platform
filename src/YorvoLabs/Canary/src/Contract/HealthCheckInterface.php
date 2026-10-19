<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Health\HealthCheckResult;

/**
 * Implement this interface to register a health check.
 *
 * In the Shopware bridge, implementations are collected via the
 * `canary.health_check` service tag and injected into HealthCheckRunner.
 */
interface HealthCheckInterface
{
    /**
     * A short, stable identifier used in response payloads and logs.
     * Use snake_case: "database", "cache_adapter", "elasticsearch".
     */
    public function getName(): string;

    /**
     * Execute the check and return a result.
     * Must never throw — catch internal exceptions and return FAIL.
     */
    public function execute(): HealthCheckResult;
}
