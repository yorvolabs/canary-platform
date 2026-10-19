<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Diagnostics\QueueTransportCheck;

final class QueueTransportCheckTest extends TestCase
{
    #[Test]
    public function it_has_stable_name(): void
    {
        self::assertSame('queue_transport', (new QueueTransportCheck())->getName());
    }

    #[Test]
    public function it_fails_when_transport_is_sync(): void
    {
        $result = (new QueueTransportCheck('sync://'))->execute();

        self::assertFalse($result->pass);
        self::assertStringContainsString('sync', (string) $result->message);
    }

    #[Test]
    public function it_passes_for_async_transport(): void
    {
        $result = (new QueueTransportCheck('doctrine://default'))->execute();

        self::assertTrue($result->pass);
    }

    #[Test]
    public function it_does_not_throw_when_dsn_is_empty(): void
    {
        // Empty string → falls through to getenv(); check it completes without exception
        $result = (new QueueTransportCheck(''))->execute();
        self::assertNotEmpty($result->name);
    }

    #[Test]
    public function it_passes_for_redis_transport(): void
    {
        $result = (new QueueTransportCheck('redis://localhost:6379/messages'))->execute();

        self::assertTrue($result->pass);
    }
}
