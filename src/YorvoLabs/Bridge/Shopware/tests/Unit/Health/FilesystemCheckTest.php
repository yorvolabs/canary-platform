<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Health\FilesystemCheck;
use YorvoLabs\Canary\Health\HealthStatus;

final class FilesystemCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new FilesystemCheck('/tmp');
        self::assertSame('filesystem', $check->getName());
    }

    public function testPassWhenExplicitPathsAreWritable(): void
    {
        $check = new FilesystemCheck('/tmp', [sys_get_temp_dir()]);

        $result = $check->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
    }

    public function testFailWhenPathDoesNotExist(): void
    {
        $nonExistent = sys_get_temp_dir() . '/canary_test_nonexistent_' . uniqid();
        $check = new FilesystemCheck('/tmp', [$nonExistent]);

        $result = $check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('not found', (string) $result->error);
        self::assertStringContainsString($nonExistent, (string) $result->error);
    }

    public function testDefaultPathsFallBackToVarCacheAndVarLog(): void
    {
        // Use /tmp as the project dir — /tmp/var/cache and /tmp/var/log won't exist.
        $check = new FilesystemCheck('/tmp');

        $result = $check->execute();

        // Both default paths don't exist under /tmp → FAIL with both listed.
        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('/tmp/var/cache', (string) $result->error);
        self::assertStringContainsString('/tmp/var/log', (string) $result->error);
    }

    public function testMultipleFailuresAreAllListed(): void
    {
        $path1 = sys_get_temp_dir() . '/canary_no_exist_1_' . uniqid();
        $path2 = sys_get_temp_dir() . '/canary_no_exist_2_' . uniqid();
        $check = new FilesystemCheck('/tmp', [$path1, $path2]);

        $result = $check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString($path1, (string) $result->error);
        self::assertStringContainsString($path2, (string) $result->error);
    }

    public function testPassWithMultipleWritablePaths(): void
    {
        $tmp = sys_get_temp_dir();
        $check = new FilesystemCheck('/tmp', [$tmp, $tmp]);

        $result = $check->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
    }
}
