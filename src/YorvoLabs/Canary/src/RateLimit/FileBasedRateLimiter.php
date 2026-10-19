<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\RateLimit;

use YorvoLabs\Canary\Contract\RateLimiterInterface;

/**
 * Sliding-window rate limiter backed by the filesystem.
 *
 * Each bucket is stored as a JSON file in $storageDir. The file holds an
 * array of Unix timestamps (one per request) within the current 60-second
 * window. On each check, stale entries are pruned before counting.
 *
 * Concurrency: uses flock() for atomic reads/writes. On high-traffic
 * deployments this may become a bottleneck — swap for a Redis-backed
 * implementation if needed. For the Canary endpoints (typically < 60 RPM)
 * it is more than sufficient.
 *
 * File naming: sha256(key) ensures the filename is safe for any key string.
 */
final class FileBasedRateLimiter implements RateLimiterInterface
{
    private const WINDOW_SECONDS = 60;

    public function __construct(
        private readonly string $storageDir,
    ) {
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0750, true);
        }
    }

    public function check(string $key, int $limit): RateLimitResult
    {
        $file = $this->filePath($key);
        $now = time();
        $windowStart = $now - self::WINDOW_SECONDS;

        $fp = fopen($file, 'c+');

        if ($fp === false) {
            // Fail open — if we can't write state, allow the request.
            // This is preferable to locking out legitimate monitoring traffic.
            return RateLimitResult::allow($limit);
        }

        try {
            flock($fp, LOCK_EX);

            $content = stream_get_contents($fp);
            /** @var list<int> $timestamps */
            $timestamps = $content !== '' && $content !== false
                ? json_decode($content, true) ?? []
                : [];

            // Prune hits outside the current window.
            $timestamps = array_values(array_filter(
                $timestamps,
                fn(int $ts) => $ts > $windowStart,
            ));

            if (count($timestamps) >= $limit) {
                // Oldest hit + window size tells the client when the window resets.
                $retryAfter = ($timestamps[0] + self::WINDOW_SECONDS) - $now;
                return RateLimitResult::deny((int) max(1, $retryAfter));
            }

            $timestamps[] = $now;

            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, json_encode($timestamps, JSON_THROW_ON_ERROR));

            return RateLimitResult::allow($limit - count($timestamps));
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private function filePath(string $key): string
    {
        return $this->storageDir . \DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
