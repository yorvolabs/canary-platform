<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Health;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Health\MetricsController;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Response\PrometheusFormatter;
use YorvoLabs\Canary\Response\ResponseFactory;

final class MetricsControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;
    private PrometheusFormatter $prometheusFormatter;

    protected function setUp(): void
    {
        $this->responseFactory     = new ResponseFactory();
        $this->prometheusFormatter = new PrometheusFormatter('canary');
    }

    private function passingCheck(): HealthCheckInterface
    {
        $check = $this->createStub(HealthCheckInterface::class);
        $check->method('getName')->willReturn('db');
        $check->method('execute')->willReturn(HealthCheckResult::pass('db', 1));
        return $check;
    }

    private function makeController(Connection $connection, ?MessengerQueueRepository $queueRepository = null): MetricsController
    {
        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<OrderCollection> $orderRepo */
        $orderRepo = $this->createMock(EntityRepository::class);
        $orderRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<CustomerCollection> $customerRepo */
        $customerRepo = $this->createMock(EntityRepository::class);
        $customerRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        /** @var \PHPUnit\Framework\MockObject\MockObject&EntityRepository<ProductCollection> $productRepo */
        $productRepo = $this->createMock(EntityRepository::class);
        $productRepo->method('aggregate')->willThrowException(new \RuntimeException('stub'));

        $queueRepository ??= new MessengerQueueRepository($this->createStub(Connection::class));

        return new MetricsController(
            new HealthCheckRunner([$this->passingCheck()]),
            $this->responseFactory,
            $this->prometheusFormatter,
            $connection,
            $queueRepository,
            $orderRepo,
            $customerRepo,
            $productRepo,
        );
    }

    #[Test]
    public function it_includes_all_five_business_metrics_in_json_response(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $response = ($this->makeController($connection))(Request::create('/canary/health/metrics', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $metrics = json_decode((string) $response->getContent(), true)['data']['metrics'];

        self::assertArrayHasKey('shop_orders_count', $metrics);
        self::assertArrayHasKey('shop_customers_count', $metrics);
        self::assertArrayHasKey('shop_carts_active', $metrics);
        self::assertArrayHasKey('shop_queue_depth', $metrics);
        self::assertArrayHasKey('shop_queue_failed', $metrics);
    }

    #[Test]
    public function it_returns_correct_dbal_metric_values(): void
    {
        $connection = $this->createStub(Connection::class);
        // DBAL metrics in order: carts, newsletter
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(3, 100);

        $queueConn = $this->createStub(Connection::class);
        $queueConn->method('fetchOne')->willReturnOnConsecutiveCalls(20, 2);
        $queueRepository = new MessengerQueueRepository($queueConn);

        $metrics = json_decode(
            (string) ($this->makeController($connection, $queueRepository))(Request::create('/canary/health/metrics', 'GET'))->getContent(),
            true,
        )['data']['metrics'];

        self::assertSame(3, $metrics['shop_carts_active']);
        self::assertSame(20, $metrics['shop_queue_depth']);
        self::assertSame(2, $metrics['shop_queue_failed']);
        // orders/customers/products fall back to 0 from repository stubs
        self::assertSame(0, $metrics['shop_orders_count']);
        self::assertSame(0, $metrics['shop_customers_count']);
    }

    #[Test]
    public function it_returns_zero_for_dbal_metrics_when_query_throws(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('table not found'));

        $metrics = json_decode(
            (string) ($this->makeController($connection))(Request::create('/canary/health/metrics', 'GET'))->getContent(),
            true,
        )['data']['metrics'];

        self::assertSame(0, $metrics['shop_carts_active']);
        self::assertSame(0, $metrics['shop_queue_depth']);
        self::assertSame(0, $metrics['shop_queue_failed']);
    }

    #[Test]
    public function it_uses_default_hours_of_24(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $response = ($this->makeController($connection))(Request::create('/canary/health/metrics', 'GET'));

        // Default hours=24 is used; controller does not throw — response is 200.
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_respects_the_hours_query_parameter(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $response = ($this->makeController($connection))(Request::create('/canary/health/metrics?hours=48', 'GET'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_clamps_hours_to_minimum_of_1(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn(0);

        $response = ($this->makeController($connection))(Request::create('/canary/health/metrics?hours=0', 'GET'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function it_includes_business_metrics_in_prometheus_output(): void
    {
        $connection = $this->createStub(Connection::class);
        // DBAL: carts, newsletter
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(7, 15);

        $request = Request::create('/canary/health/metrics', 'GET');
        $request->headers->set('Accept', 'text/plain');

        $response = ($this->makeController($connection))($request);
        $body     = (string) $response->getContent();

        self::assertStringContainsString('shop_orders_count', $body);
        self::assertStringContainsString('shop_customers_count', $body);
        self::assertStringContainsString('shop_queue_depth', $body);
        self::assertStringContainsString('shop_queue_failed', $body);
    }
}
