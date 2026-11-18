<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\RateLimit;

use YorvoLabs\Canary\Contract\RateLimiterInterface;
use YorvoLabs\Canary\Exception\RateLimiterUnavailableException;

/**
 * Sliding-window rate limiter backed by the filesystem.
 *
 * Each bucket is stored as a JSON file in $storageDir. The file holds an
 * array of Unix timestamps (one per request) within the current 60-second
 * window. On each check, stale entries are pruned before counting.
 *
 * Bounded state:
 *   - Stale buckets (no hit for a full window) are deleted by a sweep that
 *     runs at most once per window, on a request.
 *   - At most $maxBuckets bucket files exist. When that many are live, a key
 *     without a bucket shares one overflow bucket per group (the part of the
 *     key before the first ':'), limited like any other bucket. Callers that
 *     already have a bucket keep it.
 *
 * Storage failures (directory or file cannot be opened, read or written) throw
 * RateLimiterUnavailableException; the caller decides whether to fail open or
 * closed. The limiter never silently allows a request it could not count.
 *
 * Concurrency: uses flock() for atomic reads/writes. On high-traffic
 * deployments this may become a bottleneck — swap for a Redis-backed
 * implementation if needed. For the Canary endpoints (typically < 60 RPM)
 * it is more than sufficient.
 *
 * File naming: sha256(key) ensures the filename is safe for any key string.
 * The bookkeeping file has no .json extension, so it is never read as a bucket.
 */
final class FileBasedRateLimiter implements RateLimiterInterface
{
    private const WINDOW_SECONDS = 60;

    public const DEFAULT_MAX_BUCKETS = 10_000;

    private const STATE_FILE = '_state';

    private const OVERFLOW_SUFFIX = ':overflow';

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /**
     * @param \Closure(): int|null $clock Current Unix time; defaults to time(). For tests.
     */
    public function __construct(
        private readonly string $storageDir,
        private readonly int $maxBuckets = self::DEFAULT_MAX_BUCKETS,
        \Closure|null $clock = null,
    ) {
        if ($maxBuckets < 1) {
            throw new \InvalidArgumentException('maxBuckets must be at least 1');
        }

        $this->clock = $clock ?? static fn(): int => time();

        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0750, true);
        }
    }

    public function check(string $key, int $limit): RateLimitResult
    {
        $now = ($this->clock)();

        $this->sweepIfDue($now);

        $file = $this->filePath($key);
        if (!is_file($file) && !$this->reserveBucket()) {
            $file = $this->filePath($this->overflowKey($key));
        }

        return $this->hit($file, $limit, $now);
    }

    private function hit(string $file, int $limit, int $now): RateLimitResult
    {
        $windowStart = $now - self::WINDOW_SECONDS;

        // Retry once if a sweep unlinked the file between fopen() and flock().
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $fp = @fopen($file, 'c+');

            if ($fp === false) {
                throw new RateLimiterUnavailableException('Cannot open rate-limit bucket');
            }

            try {
                if (!flock($fp, LOCK_EX)) {
                    throw new RateLimiterUnavailableException('Cannot lock rate-limit bucket');
                }

                $stat = fstat($fp);
                if ($stat !== false && $stat['nlink'] === 0) {
                    continue;
                }

                $content = stream_get_contents($fp);
                if ($content === false) {
                    throw new RateLimiterUnavailableException('Cannot read rate-limit bucket');
                }

                $decoded = $content !== '' ? json_decode($content, true) : [];
                /** @var list<int> $timestamps */
                $timestamps = is_array($decoded) ? array_values(array_filter($decoded, 'is_int')) : [];

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
                $this->overwrite($fp, json_encode($timestamps, JSON_THROW_ON_ERROR));
                // The sweep compares mtimes with the limiter's clock, so stamp it.
                @touch($file, $now);

                return RateLimitResult::allow($limit - count($timestamps));
            } finally {
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }

        throw new RateLimiterUnavailableException('Rate-limit bucket kept disappearing');
    }

    /**
     * Counts a new bucket against the cap. Returns false when the cap is reached.
     */
    private function reserveBucket(): bool
    {
        return $this->withState(function (array $state): array {
            if ($state['count'] >= $this->maxBuckets) {
                return [$state, false];
            }

            $state['count']++;

            return [$state, true];
        });
    }

    /**
     * Deletes buckets without a hit in the last window, at most once per window,
     * and resets the bucket count to the number of files left.
     */
    private function sweepIfDue(int $now): void
    {
        $this->withState(function (array $state) use ($now): array {
            if ($now - $state['sweptAt'] < self::WINDOW_SECONDS) {
                return [$state, null];
            }

            $remaining = 0;
            foreach (glob($this->storageDir . '/*.json') ?: [] as $bucket) {
                if (!$this->deleteIfStale($bucket, $now - self::WINDOW_SECONDS)) {
                    $remaining++;
                }
            }

            return [['count' => $remaining, 'sweptAt' => $now], null];
        });
    }

    private function deleteIfStale(string $bucket, int $windowStart): bool
    {
        $mtime = $this->mtime($bucket);
        if ($mtime === null) {
            return true; // already gone
        }
        if ($mtime > $windowStart) {
            return false;
        }

        // Skip buckets in use right now; the next sweep gets them.
        $fp = @fopen($bucket, 'r');
        if ($fp === false) {
            return false;
        }

        try {
            if (!flock($fp, LOCK_EX | LOCK_NB)) {
                return false;
            }
            $mtime = $this->mtime($bucket);
            if ($mtime !== null && $mtime > $windowStart) {
                return false;
            }

            return @unlink($bucket) || !is_file($bucket);
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /**
     * Runs $fn on the bookkeeping state under an exclusive lock.
     *
     * @template T
     * @param \Closure(array{count: int, sweptAt: int}): array{0: array{count: int, sweptAt: int}, 1: T} $fn
     * @return T
     */
    private function withState(\Closure $fn): mixed
    {
        $fp = @fopen($this->storageDir . \DIRECTORY_SEPARATOR . self::STATE_FILE, 'c+');
        if ($fp === false) {
            throw new RateLimiterUnavailableException('Cannot open rate-limit state');
        }

        try {
            if (!flock($fp, LOCK_EX)) {
                throw new RateLimiterUnavailableException('Cannot lock rate-limit state');
            }

            $content = stream_get_contents($fp);
            $decoded = is_string($content) && $content !== '' ? json_decode($content, true) : null;

            // Missing or unreadable state: count unknown, so sweep (and recount) now.
            $state = [
                'count'   => is_array($decoded) && is_int($decoded['count'] ?? null) ? $decoded['count'] : 0,
                'sweptAt' => is_array($decoded) && is_int($decoded['sweptAt'] ?? null) ? $decoded['sweptAt'] : 0,
            ];

            [$newState, $result] = $fn($state);

            if ($newState !== $state) {
                $this->overwrite($fp, json_encode($newState, JSON_THROW_ON_ERROR));
            }

            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @param resource $fp */
    private function overwrite($fp, string $data): void
    {
        if (!ftruncate($fp, 0) || !rewind($fp)) {
            throw new RateLimiterUnavailableException('Cannot write rate-limit state');
        }

        $written = fwrite($fp, $data);
        if ($written !== strlen($data) || !fflush($fp)) {
            throw new RateLimiterUnavailableException('Cannot write rate-limit state');
        }
    }

    private function mtime(string $file): int|null
    {
        clearstatcache(true, $file);
        $mtime = @filemtime($file);

        return $mtime === false ? null : $mtime;
    }

    private function overflowKey(string $key): string
    {
        $group = str_contains($key, ':') ? strstr($key, ':', true) : '';

        return $group . self::OVERFLOW_SUFFIX;
    }

    private function filePath(string $key): string
    {
        return $this->storageDir . \DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
