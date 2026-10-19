<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy;

use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Deploy\MaintenanceState;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Maintenance mode toggle and status endpoints.
 *
 * GET    {prefix}/deploy/maintenance  → current state
 * PUT    {prefix}/deploy/maintenance  → enable (body: {"reason": "..."} optional)
 * DELETE {prefix}/deploy/maintenance  → disable
 *
 * Maintenance state is stored via Shopware's SystemConfigService:
 *   core.basicInformation.maintenanceMode  (bool)  — native Shopware flag used by the storefront
 *   YorvoLabsCanary.config.maintenanceReason  (string) — optional reason persisted alongside the flag
 *
 * All three methods require DEPLOY scope token.
 */
final class MaintenanceController
{
    private const MODE_KEY   = 'core.basicInformation.maintenanceMode';
    private const REASON_KEY = 'YorvoLabsCanary.config.maintenanceReason';

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly SystemConfigService $systemConfig,
    ) {}

    #[Route(
        '/deploy/maintenance',
        name: 'canary.deploy.maintenance.status',
        methods: ['GET'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function status(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        return $this->responseFactory->ok($this->readState()->toArray(), $requestId);
    }

    #[Route(
        '/deploy/maintenance',
        name: 'canary.deploy.maintenance.enable',
        methods: ['PUT'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function enable(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $body   = json_decode((string) $request->getContent(), true);
        $reason = is_array($body) && isset($body['reason']) && is_string($body['reason'])
            ? $body['reason']
            : null;

        $this->systemConfig->set(self::MODE_KEY, true);
        $this->systemConfig->set(self::REASON_KEY, $reason);

        return $this->responseFactory->ok($this->readState()->toArray(), $requestId);
    }

    #[Route(
        '/deploy/maintenance',
        name: 'canary.deploy.maintenance.disable',
        methods: ['DELETE'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function disable(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $this->systemConfig->set(self::MODE_KEY, false);
        $this->systemConfig->set(self::REASON_KEY, null);

        return $this->responseFactory->ok($this->readState()->toArray(), $requestId);
    }

    private function readState(): MaintenanceState
    {
        $enabled = (bool) $this->systemConfig->get(self::MODE_KEY);
        $raw     = $this->systemConfig->get(self::REASON_KEY);
        $reason  = is_string($raw) && $raw !== '' ? $raw : null;

        return new MaintenanceState($enabled, $reason);
    }
}
