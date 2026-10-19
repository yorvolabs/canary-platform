<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\RateLimit\RateLimitResult;

/**
 * Sliding-window rate limiter abstraction.
 *
 * Two implementations ship in core:
 *   - FileBasedRateLimiter  — production, no Redis dependency
 *   - InMemoryRateLimiter   — tests and dev environments only
 */
interface RateLimiterInterface
{
    /**
     * Check and record a hit for $key within the current window.
     *
     * @param string $key Bucket identifier, e.g. "health:{ip}" or "cache:{ip}"
     * @param int $limit Maximum allowed hits per minute
     */
    public function check(string $key, int $limit): RateLimitResult;
}
