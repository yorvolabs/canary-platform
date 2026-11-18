<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Exception\RateLimiterUnavailableException;
use YorvoLabs\Canary\RateLimit\FileBasedRateLimiter;

final class FileBasedRateLimiterTest extends TestCase
{
    private string $storageDir;

    private int $now = 1_700_000_000;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/canary-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir . '/*') ?: []);
            rmdir($this->storageDir);
        }
    }

    #[Test]
    public function it_allows_requests_within_the_limit(): void
    {
        $result = (new FileBasedRateLimiter($this->storageDir))->check('test-key', 5);
        self::assertTrue($result->allowed);
        self::assertSame(4, $result->remaining);
    }

    #[Test]
    public function it_tracks_multiple_hits(): void
    {
        $limiter = new FileBasedRateLimiter($this->storageDir);
        for ($i = 0; $i < 3; $i++) {
            $limiter->check('test-key', 5);
        }
        $result = $limiter->check('test-key', 5);
        self::assertTrue($result->allowed);
        self::assertSame(1, $result->remaining);
    }

    #[Test]
    public function it_denies_requests_when_limit_is_exceeded(): void
    {
        $limiter = new FileBasedRateLimiter($this->storageDir);
        for ($i = 0; $i < 3; $i++) {
            $limiter->check('test-key', 3);
        }
        $result = $limiter->check('test-key', 3);
        self::assertFalse($result->allowed);
        self::assertGreaterThanOrEqual(1, $result->retryAfterSeconds);
    }

    #[Test]
    public function it_isolates_different_keys(): void
    {
        $limiter = new FileBasedRateLimiter($this->storageDir);
        for ($i = 0; $i < 2; $i++) {
            $limiter->check('key-a', 2);
        }
        self::assertFalse($limiter->check('key-a', 2)->allowed);
        self::assertTrue($limiter->check('key-b', 2)->allowed);
    }

    #[Test]
    public function it_creates_the_storage_directory_if_it_does_not_exist(): void
    {
        $nested = $this->storageDir . '/nested/deep';
        new FileBasedRateLimiter($nested);
        self::assertDirectoryExists($nested);
        rmdir($nested);
        rmdir(dirname($nested));
    }

    // ------------------------------------------------------------------
    // Bounded state
    // ------------------------------------------------------------------

    private function limiter(int $maxBuckets = FileBasedRateLimiter::DEFAULT_MAX_BUCKETS): FileBasedRateLimiter
    {
        return new FileBasedRateLimiter($this->storageDir, $maxBuckets, fn(): int => $this->now);
    }

    private function bucketCount(): int
    {
        return count(glob($this->storageDir . '/*.json') ?: []);
    }

    #[Test]
    public function buckets_without_a_hit_for_a_window_are_deleted(): void
    {
        $limiter = $this->limiter();
        $limiter->check('health:192.0.2.1', 5);
        $limiter->check('health:192.0.2.2', 5);
        self::assertSame(2, $this->bucketCount());

        $this->now += 61;
        $limiter->check('health:192.0.2.3', 5);

        self::assertSame(1, $this->bucketCount(), 'Only the bucket hit in this window remains');
    }

    #[Test]
    public function a_bucket_hit_within_the_window_survives_the_sweep(): void
    {
        $limiter = $this->limiter();
        $limiter->check('health:192.0.2.1', 5);
        $this->now += 30;
        $limiter->check('health:192.0.2.2', 5);

        $this->now += 31; // first bucket idle 61 s, second 31 s
        $limiter->check('health:192.0.2.2', 5);

        self::assertSame(1, $this->bucketCount());
        // Hits at +30, +61 and now: all three are in the window.
        self::assertSame(2, $limiter->check('health:192.0.2.2', 5)->remaining, 'Its hits are kept');
    }

    #[Test]
    public function the_sweep_runs_at_most_once_per_window(): void
    {
        $limiter = $this->limiter();
        $limiter->check('health:192.0.2.1', 5); // first request sweeps

        $this->now += 61;
        $limiter->check('health:192.0.2.2', 5); // sweeps, deletes .1
        $limiter->check('health:192.0.2.3', 5);

        // .2 and .3 are stale only after another full window.
        $this->now += 30;
        $limiter->check('health:192.0.2.4', 5);
        self::assertSame(3, $this->bucketCount());
    }

    #[Test]
    public function stale_files_left_by_an_older_version_are_deleted_on_the_first_request(): void
    {
        mkdir($this->storageDir, 0750, true);
        $legacy = $this->storageDir . '/' . hash('sha256', 'health:198.51.100.7') . '.json';
        file_put_contents($legacy, '[1]');
        touch($legacy, $this->now - 3600);

        $this->limiter()->check('health:192.0.2.1', 5);

        self::assertFileDoesNotExist($legacy);
    }

    #[Test]
    public function at_the_cap_new_keys_share_one_overflow_bucket_per_group(): void
    {
        $limiter = $this->limiter(maxBuckets: 2);
        self::assertTrue($limiter->check('mutation:192.0.2.1', 2)->allowed);
        self::assertTrue($limiter->check('mutation:192.0.2.2', 2)->allowed);

        // Cap reached: .3 and .4 share the overflow bucket (limit 2).
        self::assertTrue($limiter->check('mutation:192.0.2.3', 2)->allowed);
        self::assertTrue($limiter->check('mutation:192.0.2.4', 2)->allowed);
        self::assertFalse($limiter->check('mutation:192.0.2.5', 2)->allowed, 'The overflow bucket is limited');

        // Callers that already had a bucket keep it.
        self::assertTrue($limiter->check('mutation:192.0.2.1', 2)->allowed);

        // Another group has its own overflow bucket.
        self::assertTrue($limiter->check('health:192.0.2.9', 2)->allowed);

        self::assertSame(4, $this->bucketCount(), 'Two buckets plus one overflow bucket per group');
    }

    #[Test]
    public function the_cap_is_shared_between_limiter_instances(): void
    {
        $this->limiter(maxBuckets: 1)->check('mutation:192.0.2.1', 1);
        $this->limiter(maxBuckets: 1)->check('mutation:192.0.2.2', 1);

        // .2 went to the overflow bucket, so .3 finds it full.
        self::assertFalse($this->limiter(maxBuckets: 1)->check('mutation:192.0.2.3', 1)->allowed);
    }

    #[Test]
    public function slots_freed_by_the_sweep_are_available_again(): void
    {
        $limiter = $this->limiter(maxBuckets: 1);
        $limiter->check('mutation:192.0.2.1', 1);
        $limiter->check('mutation:192.0.2.2', 1); // overflow, now full

        // The sweep deletes .1 and the old overflow bucket.
        $this->now += 61;
        self::assertTrue($limiter->check('mutation:192.0.2.3', 1)->allowed, '.3 gets the freed slot');
        self::assertTrue($limiter->check('mutation:192.0.2.4', 1)->allowed, '.4 starts a fresh overflow bucket');
        self::assertFalse($limiter->check('mutation:192.0.2.5', 1)->allowed, '.5 shares it with .4');
    }

    #[Test]
    public function unreadable_bucket_content_counts_as_empty(): void
    {
        mkdir($this->storageDir, 0750, true);
        $bucket = $this->storageDir . '/' . hash('sha256', 'health:192.0.2.1') . '.json';
        file_put_contents($bucket, 'not json');
        touch($bucket, $this->now);

        self::assertSame(4, $this->limiter()->check('health:192.0.2.1', 5)->remaining);
    }

    #[Test]
    public function it_throws_when_the_storage_cannot_be_used(): void
    {
        // A regular file where the directory should be: nothing can be created in it.
        mkdir($this->storageDir, 0750, true);
        touch($this->storageDir . '/blocker');
        $limiter = new FileBasedRateLimiter($this->storageDir . '/blocker/rate-limit');

        $this->expectException(RateLimiterUnavailableException::class);
        $limiter->check('health:192.0.2.1', 5);
    }

    #[Test]
    public function it_rejects_a_cap_below_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FileBasedRateLimiter($this->storageDir, 0);
    }
}
