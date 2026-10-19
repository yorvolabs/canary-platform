<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\RateLimit\FileBasedRateLimiter;

final class FileBasedRateLimiterTest extends TestCase
{
    private string $storageDir;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/canary-test-' . uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storageDir)) {
            array_map('unlink', glob($this->storageDir . '/*.json') ?: []);
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
}
