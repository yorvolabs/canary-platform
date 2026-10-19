<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Configuration;

/**
 * Endpoint groups that can be individually enabled or disabled.
 *
 * A disabled group returns 404 (not 403) to avoid revealing its existence.
 * Configure via CANARY_FEATURES env var: "health,cache" enables only those two.
 * An empty CANARY_FEATURES value enables all groups.
 */
enum Feature: string
{
    case HEALTH = 'health';
    case CACHE = 'cache';
    case DIAGNOSTICS = 'diagnostics';
    case DEPLOY = 'deploy';
}
