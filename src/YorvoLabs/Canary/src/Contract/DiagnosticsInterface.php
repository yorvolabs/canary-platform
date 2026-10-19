<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

/**
 * Implement this interface to register a diagnostic check.
 *
 * In the Shopware bridge, implementations are collected via the
 * `canary.diagnostic` service tag and injected into DiagnosticsRunner.
 */
interface DiagnosticsInterface
{
    /**
     * A short, stable identifier used in response payloads.
     * Use snake_case: "app_env", "queue_transport", "mailer_transport".
     */
    public function getName(): string;

    /**
     * Execute the check and return a result.
     * Must never throw — catch internal exceptions and return a failing result.
     */
    public function execute(): DiagnosticsResult;
}
