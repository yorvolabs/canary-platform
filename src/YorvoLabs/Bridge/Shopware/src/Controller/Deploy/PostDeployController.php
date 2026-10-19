<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Deploy\DeployPhase;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Health\HealthStatus;
use YorvoLabs\Canary\Response\ResponseFactory;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * POST {prefix}/deploy/post
 *
 * Runs the post-deployment step sequence and returns a consolidated result.
 * Steps execute in order; all steps run regardless of individual failures so
 * the caller gets a complete picture in a single request.
 *
 * Steps:
 *   1. opcache_reset    — invalidates all compiled scripts via opcache_reset()
 *   2. cache_clear      — flushes the Shopware object cache pool (and HTTP pool if wired)
 *   3. readiness_check  — runs all registered HealthCheckInterface implementations
 *
 * The overall pass/fail is the AND of all step results.
 * Requires DEPLOY scope token.
 */
#[Route(
    '/deploy/post',
    name: 'canary.deploy.post',
    methods: ['POST'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class PostDeployController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly HealthCheckRunner $healthCheckRunner,
        private readonly CacheItemPoolInterface $objectPool,
        private readonly CacheItemPoolInterface|null $httpPool,
        private readonly DeployHookRunner $hookRunner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $steps = [
            $this->stepOpcacheReset(),
            $this->stepCacheClear(),
            $this->stepReadinessCheck(),
        ];

        foreach ($this->hookRunner->run(DeployPhase::POST) as $hookResult) {
            $steps[] = [
                'name'        => $hookResult->name,
                'pass'        => $hookResult->pass,
                'duration_ms' => $hookResult->durationMs,
                'detail'      => $hookResult->message !== null ? ['message' => $hookResult->message] : [],
            ];
        }

        $pass = array_reduce($steps, fn(bool $carry, array $s) => $carry && (bool) $s['pass'], true);

        return $this->responseFactory->ok(['pass' => $pass, 'steps' => $steps], $requestId);
    }

    /** @return array{name: string, pass: bool, duration_ms: int, detail: array<string, mixed>} */
    private function stepOpcacheReset(): array
    {
        $start = Stopwatch::start();

        if (!function_exists('opcache_reset')) {
            return $this->step('opcache_reset', true, $start, ['skipped' => true, 'reason' => 'OPcache extension not loaded']);
        }

        $result = opcache_reset();

        // opcache_reset() returns false when OPcache is not enabled (e.g. CLI mode).
        // Treat as a successful skip — no compiled scripts means nothing to invalidate.
        if ($result === false) {
            return $this->step('opcache_reset', true, $start, ['skipped' => true, 'reason' => 'OPcache not enabled']);
        }

        return $this->step('opcache_reset', true, $start, ['reset' => true]);
    }

    /** @return array{name: string, pass: bool, duration_ms: int, detail: array<string, mixed>} */
    private function stepCacheClear(): array
    {
        $start   = hrtime(true);
        $cleared = [];
        $pass    = true;

        try {
            $this->objectPool->clear();
            $cleared[] = 'object';
        } catch (\Throwable $e) {
            $pass = false;
            $cleared[] = 'object (failed: ' . $e->getMessage() . ')';
        }

        if ($this->httpPool !== null) {
            try {
                $this->httpPool->clear();
                $cleared[] = 'http';
            } catch (\Throwable $e) {
                $pass = false;
                $cleared[] = 'http (failed: ' . $e->getMessage() . ')';
            }
        }

        return $this->step('cache_clear', $pass, $start, ['pools_cleared' => $cleared]);
    }

    /** @return array{name: string, pass: bool, duration_ms: int, detail: array<string, mixed>} */
    private function stepReadinessCheck(): array
    {
        $start     = hrtime(true);
        $runResult = $this->healthCheckRunner->run();
        $pass      = $runResult->status !== HealthStatus::FAIL;

        $detail = array_map(
            fn($r) => ['name' => $r->name, 'status' => $r->status->value, 'duration_ms' => $r->durationMs],
            $runResult->results,
        );

        return $this->step('readiness_check', $pass, $start, [
            'status' => $runResult->status->value,
            'checks' => $detail,
        ]);
    }

    /**
     * @param array<string, mixed> $detail
     * @return array{name: string, pass: bool, duration_ms: int, detail: array<string, mixed>}
     */
    private function step(string $name, bool $pass, int $startNs, array $detail): array
    {
        return [
            'name'        => $name,
            'pass'        => $pass,
            'duration_ms' => Stopwatch::elapsedMs($startNs),
            'detail'      => $detail,
        ];
    }
}
