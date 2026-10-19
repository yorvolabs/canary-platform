<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\RateLimit;

use YorvoLabs\Canary\Contract\RateLimiterInterface;

/**
 * In-process rate limiter. State is lost between requests.
 *
 * Use in tests (no temp files) and local dev. Never in production.
 */
final class InMemoryRateLimiter implements RateLimiterInterface
{
    /** @var array<string, list<int>> key → list of timestamps */
    private array $buckets = [];

    public function check(string $key, int $limit): RateLimitResult
    {
        $now = time();
        $windowStart = $now - 60;

        $timestamps = array_values(array_filter(
            $this->buckets[$key] ?? [],
            fn(int $ts) => $ts > $windowStart,
        ));

        if (count($timestamps) >= $limit) {
            $retryAfter = ($timestamps[0] + 60) - $now;
            return RateLimitResult::deny(max(1, $retryAfter));
        }

        $timestamps[] = $now;
        $this->buckets[$key] = $timestamps;

        return RateLimitResult::allow($limit - count($timestamps));
    }

    /** Reset all buckets — useful between test cases. */
    public function reset(): void
    {
        $this->buckets = [];
    }
}
