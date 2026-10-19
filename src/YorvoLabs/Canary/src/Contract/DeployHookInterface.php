<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Deploy\DeployHookResult;
use YorvoLabs\Canary\Deploy\DeployPhase;

/**
 * Implement this interface to register a custom pre- or post-deploy action.
 *
 * Hooks are collected by DeployHookRunner. The runner filters by phase before
 * executing, so a single hook runs in exactly one phase.
 *
 * In the Shopware bridge, tag your service with `canary.deploy_hook` in services.xml.
 * The runner receives all tagged hooks via a tagged_iterator.
 *
 * Contract:
 *   - Must never throw — catch internal exceptions and return a failing result.
 *   - Include durationMs in the result (measure with hrtime(true) internally).
 *   - Return a stable name in snake_case for response payloads and logs.
 */
interface DeployHookInterface
{
    /**
     * A short, stable identifier used in response payloads and logs.
     * Use snake_case: "migrate_database", "warm_cache", "notify_slack".
     */
    public function getName(): string;

    /** The deploy phase in which this hook should run. */
    public function getPhase(): DeployPhase;

    /**
     * Execute the hook and return a result.
     * Must never throw — catch internal exceptions and return DeployHookResult::fail().
     */
    public function execute(): DeployHookResult;
}
