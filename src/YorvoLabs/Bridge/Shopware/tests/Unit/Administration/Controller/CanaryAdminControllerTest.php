<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Administration\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Plugin\PluginCollection;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Administration\CanaryControllerLocator;
use YorvoLabs\Canary\Bridge\Shopware\Administration\Controller\CanaryAdminController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\OpcacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\ShopwareCacheController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\ConfigController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\EnvController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\LogController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\PluginsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\QueueController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\MetricsController;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\ReadinessController;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Diagnostics\DiagnosticsRunner;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Response\PrometheusFormatter;
use YorvoLabs\Canary\Response\ResponseFactory;

final class CanaryAdminControllerTest extends TestCase
{
    private string $logDir;
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->logDir          = sys_get_temp_dir() . '/canary_admin_test_' . uniqid();
        mkdir($this->logDir, 0755, true);
        $this->responseFactory = new ResponseFactory();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir . '/*') ?: []);
        rmdir($this->logDir);
    }

    private function emptyConnection(): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturn([]);
        $connection->method('fetchAssociative')->willReturn(['cnt' => 0, 'oldest' => null]);
        $connection->method('fetchAllAssociative')->willReturn([]);
        return $connection;
    }

    /** @return EntityRepository<PluginCollection> */
    private function emptyPluginRepository(): EntityRepository
    {
        $collection   = new PluginCollection([]);
        $searchResult = $this->createStub(EntitySearchResult::class);
        $searchResult->method('getEntities')->willReturn($collection);

        $repo = $this->createStub(EntityRepository::class);
        $repo->method('search')->willReturn($searchResult);
        $repo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        return $repo;
    }

    private function makeController(): CanaryAdminController
    {
        $conn    = $this->emptyConnection();
        $masker  = new SensitiveValueMasker();
        $factory = $this->responseFactory;
        $runner  = new HealthCheckRunner([]);

        $pluginRepo = $this->emptyPluginRepository();

        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<OrderCollection> $orderRepo */
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<CustomerCollection> $customerRepo */
        $customerRepo = $this->createMock(EntityRepository::class);
        $customerRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<ProductCollection> $productRepo */
        $productRepo = $this->createMock(EntityRepository::class);
        $productRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        $queueRepository = new MessengerQueueRepository($this->createStub(Connection::class));

        $locator = new CanaryControllerLocator(
            new EnvController($factory, $masker, '6.6.8.0', 'test'),
            new ConfigController($factory, $masker, new DiagnosticsRunner(), $conn),
            new LogController($factory, $this->logDir, $masker),
            new QueueController($factory, $conn),
            new PluginsController($factory, $pluginRepo),
            new ReadinessController($runner, $factory),
            new MetricsController($runner, $factory, new PrometheusFormatter(), $conn, $queueRepository, $orderRepo, $customerRepo, $productRepo),
            new OpcacheController($factory),
            new ShopwareCacheController($factory, $this->createStub(CacheItemPoolInterface::class), null),
        );

        return new CanaryAdminController(
            $locator,
            Options::fromEnv(),
            new DeployHookRunner(),
            sys_get_temp_dir(),
            $factory,
            $this->logDir,
            'https://example.com',
            $masker,
        );
    }

    #[Test]
    public function diag_env_proxies_to_env_controller(): void
    {
        $response = $this->makeController()->diagEnv(Request::create('/api/_action/canary/diag/env'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('php', $body['data']);
        self::assertArrayHasKey('shopware', $body['data']);
        self::assertArrayHasKey('env', $body['data']);
    }

    #[Test]
    public function diag_config_proxies_to_config_controller(): void
    {
        $response = $this->makeController()->diagConfig(Request::create('/api/_action/canary/diag/config'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('namespace', $body['data']);
        self::assertArrayHasKey('snapshot', $body['data']);
        self::assertArrayHasKey('validation', $body['data']);
    }

    #[Test]
    public function diag_logs_proxies_to_log_controller(): void
    {
        // No log file → 404 from LogController (logDir is empty in setUp)
        $response = $this->makeController()->diagLogs(Request::create('/api/_action/canary/diag/logs'));

        self::assertContains($response->getStatusCode(), [200, 404]);
    }

    #[Test]
    public function diag_queue_proxies_to_queue_controller(): void
    {
        $response = $this->makeController()->diagQueue(Request::create('/api/_action/canary/diag/queue'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('queues', $body['data']);
        self::assertArrayHasKey('failed', $body['data']);
    }

    #[Test]
    public function diag_plugins_proxies_to_plugins_controller(): void
    {
        $response = $this->makeController()->diagPlugins(Request::create('/api/_action/canary/diag/plugins'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('plugins', $body['data']);
    }

    #[Test]
    public function audit_returns_tail_of_audit_log(): void
    {
        $lines = [
            '{"timestamp":"2026-04-25T10:00:00+00:00","ip":"127.0.0.1","scope":"monitoring","endpoint":"/canary/health/live","method":"GET","status_code":200}',
            '{"timestamp":"2026-04-25T10:01:00+00:00","ip":"10.0.0.1","scope":"admin","endpoint":"/canary/diag/env","method":"GET","status_code":200}',
        ];
        file_put_contents($this->logDir . '/canary_audit.log', implode("\n", $lines) . "\n");

        $response = $this->makeController()->audit(Request::create('/api/_action/canary/audit'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('canary_audit.log', $body['data']['file']);
        self::assertSame(2, $body['data']['lines_returned']);
        self::assertCount(2, $body['data']['entries']);
        foreach ($body['data']['entries'] as $entry) {
            self::assertStringNotContainsString('127.0.0.1', $entry);
            self::assertStringNotContainsString('10.0.0.1', $entry);
            self::assertStringContainsString('[IP]', $entry);
        }
    }

    #[Test]
    public function audit_returns_404_when_no_audit_log_exists(): void
    {
        $response = $this->makeController()->audit(Request::create('/api/_action/canary/audit'));

        self::assertSame(404, $response->getStatusCode());
    }
}
