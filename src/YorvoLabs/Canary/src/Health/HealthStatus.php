<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Health;

/**
 * Maps to the RFC draft application/health+json status values.
 *
 * PASS  → all checks healthy
 * WARN  → degraded but functional (used for soft dependencies like ES)
 * FAIL  → at least one critical check failed
 */
enum HealthStatus: string
{
    case PASS = 'pass';
    case WARN = 'warn';
    case FAIL = 'fail';

    /** Aggregate two statuses — worst wins. */
    public function merge(self $other): self
    {
        if ($this === self::FAIL || $other === self::FAIL) {
            return self::FAIL;
        }
        if ($this === self::WARN || $other === self::WARN) {
            return self::WARN;
        }
        return self::PASS;
    }

    public function httpStatus(): int
    {
        return $this === self::FAIL ? 503 : 200;
    }
}
