<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Response\HealthResponse;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/health/ready
 *
 * Runs all registered HealthCheckInterface implementations and returns
 * an RFC application/health+json response. HTTP status is 200 on PASS/WARN
 * and 503 on FAIL, following the RFC draft convention.
 *
 * A container orchestrator (k8s readiness probe) uses this to decide
 * whether to route traffic to this pod.
 *
 * Health checks are injected via the canary.health_check service tag
 * and collected by HealthCheckRunner.
 */
#[Route(
    '/health/ready',
    name: 'canary.health.ready',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class ReadinessController
{
    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id')
            ?: $this->responseFactory->generateRequestId();

        $start = microtime(true);
        $runResult = $this->runner->run();
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        return $this->responseFactory->health(
            HealthResponse::fromRunResult($runResult, $requestId, $durationMs),
        );
    }
}
