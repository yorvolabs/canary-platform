<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Health;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use YorvoLabs\Canary\Bridge\Shopware\Health\CacheAdapterCheck;
use YorvoLabs\Canary\Health\HealthStatus;

final class CacheAdapterCheckTest extends TestCase
{
    private CacheItemPoolInterface&Stub $pool;
    private CacheAdapterCheck $check;

    protected function setUp(): void
    {
        $this->pool = $this->createStub(CacheItemPoolInterface::class);
        $this->check = new CacheAdapterCheck($this->pool);
    }

    public function testGetName(): void
    {
        self::assertSame('cache_adapter', $this->check->getName());
    }

    public function testPassOnSuccessfulRoundTrip(): void
    {
        $writeItem = $this->createStub(CacheItemInterface::class);
        $writeItem->method('set')->willReturnSelf();
        $writeItem->method('expiresAfter')->willReturnSelf();

        $readItem = $this->createStub(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn('ok');

        $this->pool->method('getItem')
            ->willReturnOnConsecutiveCalls($writeItem, $readItem);
        $this->pool->method('save')->willReturn(true);
        $this->pool->method('deleteItem')->willReturn(true);

        $result = $this->check->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
    }

    public function testFailWhenReadbackMisses(): void
    {
        $writeItem = $this->createStub(CacheItemInterface::class);
        $writeItem->method('set')->willReturnSelf();
        $writeItem->method('expiresAfter')->willReturnSelf();

        $readItem = $this->createStub(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(false);

        $this->pool->method('getItem')
            ->willReturnOnConsecutiveCalls($writeItem, $readItem);
        $this->pool->method('save')->willReturn(true);

        $result = $this->check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('key not found after write', (string) $result->error);
    }

    public function testFailWhenReadbackReturnsWrongValue(): void
    {
        $writeItem = $this->createStub(CacheItemInterface::class);
        $writeItem->method('set')->willReturnSelf();
        $writeItem->method('expiresAfter')->willReturnSelf();

        $readItem = $this->createStub(CacheItemInterface::class);
        $readItem->method('isHit')->willReturn(true);
        $readItem->method('get')->willReturn('corrupted');

        $this->pool->method('getItem')
            ->willReturnOnConsecutiveCalls($writeItem, $readItem);
        $this->pool->method('save')->willReturn(true);

        $result = $this->check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('unexpected value', (string) $result->error);
    }

    public function testFailWhenPoolThrows(): void
    {
        $this->pool->method('getItem')
            ->willThrowException(new \RuntimeException('Redis connection lost'));

        $result = $this->check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('Redis connection lost', (string) $result->error);
    }

    public function testProbeKeysAreDifferentAcrossExecutions(): void
    {
        $capturedKeys = [];

        $makeItem = function () {
            $item = $this->createStub(CacheItemInterface::class);
            $item->method('set')->willReturnSelf();
            $item->method('expiresAfter')->willReturnSelf();
            $item->method('isHit')->willReturn(true);
            $item->method('get')->willReturn('ok');
            return $item;
        };

        $this->pool->method('getItem')
            ->willReturnCallback(function (string $key) use ($makeItem, &$capturedKeys) {
                $capturedKeys[] = $key;
                return $makeItem();
            });
        $this->pool->method('save')->willReturn(true);
        $this->pool->method('deleteItem')->willReturn(true);

        // Run twice — write keys (index 0 and 2) should differ.
        $this->check->execute();
        $this->check->execute();

        self::assertNotSame($capturedKeys[0], $capturedKeys[2]);
    }
}
