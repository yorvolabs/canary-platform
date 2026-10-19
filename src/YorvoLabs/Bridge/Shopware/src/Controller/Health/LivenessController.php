<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Health;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/health/live
 *
 * Answers "is this PHP process alive?". No dependencies are checked.
 * A container orchestrator (k8s liveness probe) uses this to decide
 * whether to restart a crashed or deadlocked pod.
 *
 * Always returns 200 as long as PHP can handle requests.
 */
#[Route(
    '/health/live',
    name: 'canary.health.live',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class LivenessController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return $this->responseFactory->ok(
            ['status' => 'alive'],
            $request->attributes->getString('_canary_request_id') ?: null,
        );
    }
}
