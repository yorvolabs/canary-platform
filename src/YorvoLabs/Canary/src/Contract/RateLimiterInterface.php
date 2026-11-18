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
     * @param string $key Bucket identifier "{group}:{ip}", e.g. "health:192.0.2.1"
     *                    or "mutation:2001:db8:1:2::/64" (IPv6 is keyed per /64)
     * @param int $limit Maximum allowed hits per minute
     *
     * @throws \YorvoLabs\Canary\Exception\RateLimiterUnavailableException when the
     *         limiter cannot read or write its state. The guard then allows health
     *         requests and answers 503 for the other groups.
     */
    public function check(string $key, int $limit): RateLimitResult;
}
