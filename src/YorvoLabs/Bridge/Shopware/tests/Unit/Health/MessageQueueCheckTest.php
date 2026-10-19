<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Health;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Health\MessageQueueCheck;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Health\HealthStatus;

final class MessageQueueCheckTest extends TestCase
{
    private function makeCheck(Connection $connection, int $queueThreshold = 100, int $failedThreshold = 0): MessageQueueCheck
    {
        $options = Options::fromArray([
            'CANARY_DEPLOY_QUEUE_THRESHOLD'  => (string) $queueThreshold,
            'CANARY_DEPLOY_FAILED_THRESHOLD' => (string) $failedThreshold,
        ]);

        return new MessageQueueCheck(new MessengerQueueRepository($connection), $options);
    }

    #[Test]
    public function it_returns_the_correct_name(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        self::assertSame('message_queue', $this->makeCheck($connection)->getName());
    }

    #[Test]
    public function it_passes_when_counts_are_within_thresholds(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(5, 0);

        $result = $this->makeCheck($connection)->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
        self::assertSame(5, $result->detail['depth']);
        self::assertSame(0, $result->detail['failed']);
    }

    #[Test]
    public function it_warns_when_queue_depth_exceeds_threshold(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(101, 0);

        $result = $this->makeCheck($connection, queueThreshold: 100)->execute();

        self::assertSame(HealthStatus::WARN, $result->status);
        self::assertStringContainsString('101', (string) $result->error);
    }

    #[Test]
    public function it_warns_when_failed_messages_exceed_threshold(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(0, 1);

        $result = $this->makeCheck($connection, failedThreshold: 0)->execute();

        self::assertSame(HealthStatus::WARN, $result->status);
        self::assertStringContainsString('Dead-letter', (string) $result->error);
    }

    #[Test]
    public function it_fails_when_the_transport_is_unreachable(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('table not found'));

        $result = $this->makeCheck($connection)->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('unreachable', (string) $result->error);
    }

    #[Test]
    public function it_includes_non_negative_duration_ms(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $result = $this->makeCheck($connection)->execute();

        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }
}
