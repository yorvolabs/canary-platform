<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Diagnostics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\QueueController;
use YorvoLabs\Canary\Response\ResponseFactory;

final class QueueControllerTest extends TestCase
{
    private function makeController(Connection $connection): QueueController
    {
        return new QueueController(new ResponseFactory(), $connection);
    }

    /** @return array<string, mixed> */
    private function invoke(Connection $connection): array
    {
        $response = ($this->makeController($connection))(Request::create('/canary/diag/queue', 'GET'));
        return json_decode((string) $response->getContent(), true)['data'];
    }

    #[Test]
    public function it_returns_200_with_queues_failed_and_workers_sections(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(['cnt' => 0, 'oldest' => null]);

        $response = ($this->makeController($connection))(Request::create('/canary/diag/queue', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true)['data'];

        self::assertArrayHasKey('queues', $body);
        self::assertArrayHasKey('failed', $body);
        self::assertArrayHasKey('workers', $body);
    }

    #[Test]
    public function it_returns_queue_depth_and_oldest_age(): void
    {
        $connection = $this->createMock(Connection::class);

        $connection->method('fetchFirstColumn')->willReturn(['async']);

        $connection->method('fetchAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) {
                if (isset($params[0]) && $params[0] === 'async') {
                    return ['depth' => 3, 'oldest' => date('Y-m-d H:i:s', time() - 30)];
                }
                // failed
                return ['cnt' => 0, 'oldest' => null];
            },
        );

        $data = $this->invoke($connection);

        self::assertCount(1, $data['queues']);
        self::assertSame('async', $data['queues'][0]['name']);
        self::assertSame(3, $data['queues'][0]['depth']);
        self::assertIsInt($data['queues'][0]['oldest_age_seconds']);
    }

    #[Test]
    public function it_returns_failed_count_and_oldest_timestamp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn([
            'cnt'    => 2,
            'oldest' => '2026-04-25 09:15:00',
        ]);

        $data = $this->invoke($connection);

        self::assertSame(2, $data['failed']['count']);
        self::assertNotNull($data['failed']['oldest_failed_at']);
    }

    #[Test]
    public function it_returns_zero_counts_when_db_throws(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willThrowException(new \RuntimeException('no table'));
        $connection->method('fetchAssociative')->willThrowException(new \RuntimeException('no table'));

        $data = $this->invoke($connection);

        self::assertSame([], $data['queues']);
        self::assertSame(0, $data['failed']['count']);
        self::assertNull($data['failed']['oldest_failed_at']);
    }

    #[Test]
    public function it_includes_worker_detection_result(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(['cnt' => 0, 'oldest' => null]);

        $data = $this->invoke($connection);

        self::assertArrayHasKey('detected', $data['workers']);
        self::assertIsBool($data['workers']['detected']);
        self::assertArrayHasKey('note', $data['workers']);
    }
}
