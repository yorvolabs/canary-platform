<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Health;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Health\DatabaseCheck;
use YorvoLabs\Canary\Health\HealthStatus;

final class DatabaseCheckTest extends TestCase
{
    private Connection&MockObject $connection;
    private DatabaseCheck $check;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->check = new DatabaseCheck($this->connection);
    }

    public function testGetName(): void
    {
        $connection = $this->createStub(Connection::class);
        $check = new DatabaseCheck($connection);

        self::assertSame('database', $check->getName());
    }

    public function testPassWhenQuerySucceeds(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('executeQuery')
            ->with('SELECT 1');

        $check = new DatabaseCheck($connection);
        $result = $check->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
        self::assertSame('database', $result->name);
        self::assertNull($result->error);
    }

    public function testFailWhenQueryThrows(): void
    {
        $this->connection->expects(self::once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('Connection refused'));

        $result = $this->check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('Connection refused', (string) $result->error);
    }

    public function testDurationIsNonNegative(): void
    {
        $this->connection->expects(self::once())->method('executeQuery');

        $result = $this->check->execute();

        self::assertGreaterThanOrEqual(0, $result->durationMs);
    }

    public function testFailureMessageContainsPrefixAndExceptionMessage(): void
    {
        $this->connection->expects(self::once())
            ->method('executeQuery')
            ->willThrowException(new \RuntimeException('SQLSTATE[HY000]'));

        $result = $this->check->execute();

        self::assertStringContainsString('Database unreachable:', (string) $result->error);
        self::assertStringContainsString('SQLSTATE[HY000]', (string) $result->error);
    }
}
