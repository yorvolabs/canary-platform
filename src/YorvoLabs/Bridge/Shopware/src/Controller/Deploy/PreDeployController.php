<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Configuration\DeployThreshold;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Deploy\DeployPhase;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/deploy/pre
 *
 * Read-only pre-deploy readiness check. No state is mutated.
 * Returns a pass/fail verdict for each condition so CI/CD pipelines
 * can decide whether it is safe to start a deployment.
 *
 * Checks (all non-fatal — the overall verdict is the AND of all):
 *   1. maintenance_not_active — deployment should not start if maintenance is already on
 *   2. queue_depth            — pending messages ≤ CANARY_DEPLOY_QUEUE_THRESHOLD (default 100)
 *   3. failed_messages        — dead-letter count ≤ CANARY_DEPLOY_FAILED_THRESHOLD (default 0)
 *
 * Queue queries use try/catch so a missing messenger_messages table
 * (e.g. synchronous transport) counts as depth 0 rather than an error.
 *
 * Requires DEPLOY scope token.
 */
#[Route(
    '/deploy/pre',
    name: 'canary.deploy.pre',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class PreDeployController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly SystemConfigService $systemConfig,
        private readonly MessengerQueueRepository $queueRepository,
        private readonly Options $options,
        private readonly DeployHookRunner $hookRunner,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $checks = [
            $this->checkMaintenanceNotActive(),
            $this->checkQueueDepth(),
            $this->checkFailedMessages(),
        ];

        foreach ($this->hookRunner->run(DeployPhase::PRE) as $hookResult) {
            $checks[] = [
                'name'   => $hookResult->name,
                'pass'   => $hookResult->pass,
                'detail' => $hookResult->message !== null ? ['message' => $hookResult->message] : [],
            ];
        }

        $pass = array_reduce($checks, fn(bool $carry, array $c) => $carry && (bool) $c['pass'], true);

        return $this->responseFactory->ok(['pass' => $pass, 'checks' => $checks], $requestId);
    }

    /** @return array{name: string, pass: bool, detail: array<string, mixed>} */
    private function checkMaintenanceNotActive(): array
    {
        $enabled = (bool) $this->systemConfig->get('core.basicInformation.maintenanceMode');

        return [
            'name'   => 'maintenance_not_active',
            'pass'   => !$enabled,
            'detail' => ['maintenance_enabled' => $enabled],
        ];
    }

    /** @return array{name: string, pass: bool, detail: array<string, mixed>} */
    private function checkQueueDepth(): array
    {
        $threshold = $this->options->getDeployThreshold(DeployThreshold::QUEUE);
        $depth     = $this->queryPendingMessages();

        return [
            'name'   => 'queue_depth',
            'pass'   => $depth <= $threshold,
            'detail' => ['depth' => $depth, 'threshold' => $threshold],
        ];
    }

    /** @return array{name: string, pass: bool, detail: array<string, mixed>} */
    private function checkFailedMessages(): array
    {
        $threshold = $this->options->getDeployThreshold(DeployThreshold::FAILED);
        $count     = $this->queryFailedMessages();

        return [
            'name'   => 'failed_messages',
            'pass'   => $count <= $threshold,
            'detail' => ['count' => $count, 'threshold' => $threshold],
        ];
    }

    private function queryPendingMessages(): int
    {
        try {
            return $this->queueRepository->fetchPendingCount();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function queryFailedMessages(): int
    {
        try {
            return $this->queueRepository->fetchFailedCount();
        } catch (\Throwable) {
            return 0;
        }
    }
}
