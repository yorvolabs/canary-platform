<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Health;

use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Customer\CustomerCollection;
use Shopware\Core\Checkout\Order\OrderCollection;
use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\CountResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\AggregationResult\Metric\SumResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\RangeFilter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Health\HealthStatus;
use YorvoLabs\Canary\Response\PrometheusFormatter;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/health/metrics
 *
 * Content-negotiates the response format via the Accept header:
 *   Accept: text/plain           → Prometheus text exposition (v0.0.4)
 *   Accept: application/json     → ResponseEnvelope JSON
 *   (default / *\/*)             → ResponseEnvelope JSON
 *
 * Infrastructure metrics (Phase 1):
 *   - health_check_{name}             (gauge: 1=pass, 2=warn, 0=fail)
 *   - health_check_{name}_duration_ms (gauge)
 *   - opcache_hit_rate                (gauge, when OPcache is enabled)
 *   - opcache_memory_used_bytes       (gauge)
 *   - opcache_memory_free_bytes       (gauge)
 *   - php_memory_usage_bytes          (gauge)
 *
 * Business metrics (Phase 2, param ?hours=N, default 24):
 *   - shop_orders_count       (gauge — orders created in the last N hours)
 *   - shop_customers_count    (gauge — customer registrations in the last N hours)
 *   - shop_carts_active       (gauge — carts started or updated in the last N hours)
 *   - shop_queue_depth        (gauge — pending messenger_messages)
 *   - shop_queue_failed       (gauge — failed messenger_messages)
 *
 * Business metrics fall back to 0 on any query error (missing table, etc.)
 * Requires MONITORING scope token.
 */
#[Route(
    '/health/metrics',
    name: 'canary.health.metrics',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class MetricsController
{
    /**
     * @param EntityRepository<OrderCollection>    $orderRepository
     * @param EntityRepository<CustomerCollection> $customerRepository
     * @param EntityRepository<ProductCollection>  $productRepository
     */
    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly ResponseFactory $responseFactory,
        private readonly PrometheusFormatter $prometheusFormatter,
        private readonly Connection $connection,
        private readonly MessengerQueueRepository $queueRepository,
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $customerRepository,
        private readonly EntityRepository $productRepository,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;
        $hours     = max(1, min(744, (int) ($request->query->get('hours') ?? 24))); // 744 = 31 days

        $runResult = $this->runner->run();
        $metrics   = $this->buildMetrics($runResult->results, $hours);

        if ($this->wantsPrometheus($request)) {
            return $this->responseFactory->prometheus(
                $this->prometheusFormatter->format($metrics),
                $requestId,
            );
        }

        $jsonPayload = [];
        foreach ($metrics as $name => $spec) {
            $jsonPayload[$name] = $spec['value'];
        }

        return $this->responseFactory->ok(['metrics' => $jsonPayload], $requestId);
    }

    /**
     * @param list<HealthCheckResult> $checks
     * @return array<string, array{value: int|float, help?: string, type?: string}>
     */
    private function buildMetrics(array $checks, int $hours): array
    {
        $metrics = [];

        foreach ($checks as $result) {
            $statusValue = match ($result->status) {
                HealthStatus::PASS => 1,
                HealthStatus::WARN => 2,
                HealthStatus::FAIL => 0,
            };

            $metrics['health_check_' . $result->name] = [
                'value' => $statusValue,
                'help'  => "Health check status for {$result->name}: 1=pass, 2=warn, 0=fail",
                'type'  => 'gauge',
            ];

            $metrics['health_check_' . $result->name . '_duration_ms'] = [
                'value' => $result->durationMs,
                'help'  => "Health check execution duration for {$result->name} in milliseconds",
                'type'  => 'gauge',
            ];
        }

        $this->appendOpcacheMetrics($metrics);

        $metrics['php_memory_usage_bytes'] = [
            'value' => memory_get_usage(true),
            'help'  => 'PHP real memory usage in bytes',
            'type'  => 'gauge',
        ];

        $this->appendBusinessMetrics($metrics, $hours);

        return $metrics;
    }

    /** @param array<string, array{value: int|float, help?: string, type?: string}> $metrics */
    private function appendOpcacheMetrics(array &$metrics): void
    {
        if (!function_exists('opcache_get_status')) {
            return;
        }

        $status = opcache_get_status(false);
        if (!is_array($status) || !$status['opcache_enabled']) {
            return;
        }

        $stats  = $status['opcache_statistics'] ?? [];
        $memory = $status['memory_usage'] ?? [];

        if (isset($stats['opcache_hit_rate'])) {
            $metrics['opcache_hit_rate'] = [
                'value' => round((float) $stats['opcache_hit_rate'], 4),
                'help'  => 'OPcache hit rate percentage (0–100)',
                'type'  => 'gauge',
            ];
        }

        if (isset($memory['used_memory'])) {
            $metrics['opcache_memory_used_bytes'] = [
                'value' => (int) $memory['used_memory'],
                'help'  => 'OPcache used memory in bytes',
                'type'  => 'gauge',
            ];
        }

        if (isset($memory['free_memory'])) {
            $metrics['opcache_memory_free_bytes'] = [
                'value' => (int) $memory['free_memory'],
                'help'  => 'OPcache free memory in bytes',
                'type'  => 'gauge',
            ];
        }
    }

    /** @param array<string, array{value: int|float, help?: string, type?: string}> $metrics */
    private function appendBusinessMetrics(array &$metrics, int $hours): void
    {
        [$orderCount, $orderRevenue] = $this->fetchOrderAggregates($hours);
        $customerCount               = $this->fetchCustomerCount($hours);

        $metrics['shop_orders_count'] = [
            'value' => $orderCount,
            'help'  => "Orders created in the last {$hours} hour(s)",
            'type'  => 'gauge',
        ];

        $metrics['shop_customers_count'] = [
            'value' => $customerCount,
            'help'  => "Customer registrations in the last {$hours} hour(s)",
            'type'  => 'gauge',
        ];

        $cartSince = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval("PT{$hours}H"))
            ->format('Y-m-d H:i:s');

        // cart has no EntityRepository (managed by CartPersister) — DBAL is correct here.
        $metrics['shop_carts_active'] = [
            'value' => $this->fetchCount('SELECT COUNT(*) FROM cart WHERE updated_at >= ?', [$cartSince]),
            'help'  => "Carts started or updated in the last {$hours} hour(s)",
            'type'  => 'gauge',
        ];

        try {
            $queueDepth  = $this->queueRepository->fetchPendingCount();
            $queueFailed = $this->queueRepository->fetchFailedCount();
        } catch (\Throwable) {
            $queueDepth  = 0;
            $queueFailed = 0;
        }

        $metrics['shop_queue_depth'] = [
            'value' => $queueDepth,
            'help'  => 'Pending messages in the async queue',
            'type'  => 'gauge',
        ];

        $metrics['shop_queue_failed'] = [
            'value' => $queueFailed,
            'help'  => 'Failed messages in the dead-letter queue',
            'type'  => 'gauge',
        ];

        $metrics['shop_revenue_total'] = [
            'value' => $orderRevenue,
            'help'  => "Total order revenue (amountTotal sum) in the last {$hours} hour(s)",
            'type'  => 'gauge',
        ];

        $newsletterSince = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->sub(new \DateInterval("PT{$hours}H"))
            ->format('Y-m-d H:i:s');

        $metrics['shop_newsletter_subscribers'] = [
            'value' => $this->fetchCount(
                "SELECT COUNT(*) FROM newsletter_recipient WHERE status = 'direct' AND created_at >= ?",
                [$newsletterSince],
            ),
            'help' => "New newsletter sign-ups (confirmed opt-in) in the last {$hours} hour(s)",
            'type' => 'gauge',
        ];

        $metrics['shop_products_count'] = [
            'value' => $this->fetchProductCount(),
            'help'  => 'Total main products (excluding variants)',
            'type'  => 'gauge',
        ];
    }

    /** @return array{int, float} [count, revenueSum] */
    private function fetchOrderAggregates(int $hours): array
    {
        try {
            $since    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval("PT{$hours}H"))
                ->format(\DateTimeInterface::ATOM);
            $criteria = new Criteria();
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $since]));
            $criteria->addAggregation(new CountAggregation('count', 'id'));
            $criteria->addAggregation(new SumAggregation('revenue', 'amountTotal'));

            $result      = $this->orderRepository->aggregate($criteria, Context::createDefaultContext());
            $countAgg    = $result->get('count');
            $revenueAgg  = $result->get('revenue');
            $count       = $countAgg instanceof CountResult ? (int) $countAgg->getCount() : 0;
            $revenue     = round($revenueAgg instanceof SumResult ? (float) $revenueAgg->getSum() : 0.0, 2);

            return [$count, $revenue];
        } catch (\Throwable) {
            return [0, 0.0];
        }
    }

    private function fetchCustomerCount(int $hours): int
    {
        try {
            $since    = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->sub(new \DateInterval("PT{$hours}H"))
                ->format(\DateTimeInterface::ATOM);
            $criteria = new Criteria();
            $criteria->addFilter(new RangeFilter('createdAt', [RangeFilter::GTE => $since]));
            $criteria->addAggregation(new CountAggregation('count', 'id'));

            $result   = $this->customerRepository->aggregate($criteria, Context::createDefaultContext());
            $countAgg = $result->get('count');

            return $countAgg instanceof CountResult ? (int) $countAgg->getCount() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function fetchProductCount(): int
    {
        try {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('parentId', null));
            $criteria->addAggregation(new CountAggregation('count', 'id'));
            $result   = $this->productRepository->aggregate($criteria, Context::createDefaultContext());
            $countAgg = $result->get('count');

            return $countAgg instanceof CountResult ? (int) $countAgg->getCount() : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @param list<mixed> $params */
    private function fetchCount(string $sql, array $params = []): int
    {
        try {
            $result = $this->connection->fetchOne($sql, $params);

            return is_numeric($result) ? (int) $result : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function wantsPrometheus(Request $request): bool
    {
        $accept = $request->headers->get('Accept') ?? '';
        // Only serve Prometheus when text/plain is explicitly preferred, not merely
        // present in a broad Accept header like Axios's default "application/json, text/plain, */*".
        return $accept === 'text/plain'
            || str_starts_with($accept, 'text/plain;')
            || str_starts_with($accept, 'text/plain,');
    }
}
