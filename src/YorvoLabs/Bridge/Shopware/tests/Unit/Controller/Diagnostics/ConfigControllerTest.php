<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Diagnostics;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\ConfigController;
use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;
use YorvoLabs\Canary\Diagnostics\DiagnosticsRunner;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

final class ConfigControllerTest extends TestCase
{
    private function makeController(
        Connection $connection,
        DiagnosticsRunner $runner = new DiagnosticsRunner(),
    ): ConfigController {
        return new ConfigController(
            new ResponseFactory(),
            new SensitiveValueMasker(),
            $runner,
            $connection,
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private function makeConnection(array $rows = []): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn($rows);
        return $connection;
    }

    #[Test]
    public function it_returns_200_with_all_three_sections(): void
    {
        $response = ($this->makeController($this->makeConnection()))(
            Request::create('/canary/diag/config', 'GET'),
        );

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('namespace', $body['data']);
        self::assertArrayHasKey('snapshot', $body['data']);
        self::assertArrayHasKey('validation', $body['data']);
    }

    #[Test]
    public function it_uses_default_namespace_of_yorvolabs_canary(): void
    {
        $response = ($this->makeController($this->makeConnection()))(
            Request::create('/canary/diag/config', 'GET'),
        );

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('YorvoLabsCanary', $body['data']['namespace']);
    }

    #[Test]
    public function it_respects_custom_namespace_query_param(): void
    {
        $response = ($this->makeController($this->makeConnection()))(
            Request::create('/canary/diag/config?namespace=Shopware', 'GET'),
        );

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('Shopware', $body['data']['namespace']);
    }

    #[Test]
    public function it_masks_sensitive_keys_in_snapshot(): void
    {
        $connection = $this->makeConnection([
            ['configuration_key' => 'YorvoLabsCanary.config.tokenMonitoring', 'configuration_value' => '"secret-token"'],
            ['configuration_key' => 'YorvoLabsCanary.config.queueThreshold',  'configuration_value' => '100'],
        ]);

        $response = ($this->makeController($connection))(
            Request::create('/canary/diag/config', 'GET'),
        );

        $snapshot = json_decode((string) $response->getContent(), true)['data']['snapshot'];

        self::assertSame('[REDACTED]', $snapshot['YorvoLabsCanary.config.tokenMonitoring']);
        self::assertSame(100, $snapshot['YorvoLabsCanary.config.queueThreshold']);
    }

    #[Test]
    public function it_includes_validation_results_from_diagnostics_runner(): void
    {
        $check = $this->createStub(DiagnosticsInterface::class);
        $check->method('getName')->willReturn('app_env');
        $check->method('execute')->willReturn(DiagnosticsResult::pass('app_env', 'APP_ENV is prod'));

        $runner = new DiagnosticsRunner([$check]);

        $response = ($this->makeController($this->makeConnection(), $runner))(
            Request::create('/canary/diag/config', 'GET'),
        );

        $validation = json_decode((string) $response->getContent(), true)['data']['validation'];
        self::assertCount(1, $validation);
        self::assertSame('app_env', $validation[0]['name']);
        self::assertTrue($validation[0]['pass']);
    }

    #[Test]
    public function it_returns_empty_snapshot_when_db_throws(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchAllAssociative')->willThrowException(new \RuntimeException('no table'));

        $response = ($this->makeController($connection))(
            Request::create('/canary/diag/config', 'GET'),
        );

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame([], $body['data']['snapshot']);
    }
}
