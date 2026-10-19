<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

/**
 * Token permission scopes, ordered from least to most privileged.
 *
 * ADMIN is the supertoken: it satisfies any scope check.
 * All other scopes are isolated — a CACHE token cannot call the diagnostics endpoint.
 */
enum TokenScope: string
{
    case MONITORING = 'monitoring';
    case CACHE = 'cache';
    case DIAGNOSTICS = 'diagnostics';
    case DEPLOY = 'deploy';
    case ADMIN = 'admin';

    /**
     * Returns true if this scope satisfies a required scope check.
     * ADMIN always returns true. Any other scope must match exactly.
     */
    public function satisfies(self $required): bool
    {
        return $this === self::ADMIN || $this === $required;
    }

    /** Human-readable label for audit logs and CLI output. */
    public function label(): string
    {
        return match ($this) {
            self::MONITORING => 'Monitoring (read-only health & metrics)',
            self::CACHE => 'Cache management',
            self::DIAGNOSTICS => 'Diagnostics (env, logs, queue)',
            self::DEPLOY => 'Deployment lifecycle',
            self::ADMIN => 'Admin (all scopes)',
        };
    }

    /** Environment variable name that holds the token for this scope. */
    public function envVar(): string
    {
        return 'CANARY_TOKEN_' . strtoupper($this->value);
    }
}
