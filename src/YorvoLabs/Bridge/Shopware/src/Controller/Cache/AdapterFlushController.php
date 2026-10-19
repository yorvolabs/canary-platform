<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * POST {prefix}/cache/adapters?confirm=true
 *
 * Nuclear cache flush — clears the underlying cache adapter completely.
 * Unlike ShopwareCacheController (CACHE scope), this requires ADMIN scope
 * because flushing all adapters may affect unrelated application components.
 *
 * Two-factor guard:
 *   1. ADMIN token scope (checked here — RequestGuardSubscriber only enforces
 *      CACHE scope for the {prefix}/cache/ prefix)
 *   2. ?confirm=true query parameter prevents accidental calls
 */
#[Route(
    '/cache/adapters',
    name: 'canary.cache.adapters.flush',
    defaults: ['_routeScope' => ['api'], 'auth_required' => false],
    methods: ['POST']
)]
final class AdapterFlushController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly CacheItemPoolInterface $cachePool,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $scope = $request->attributes->get('_canary_scope');
        if ($scope !== TokenScope::ADMIN) {
            return $this->responseFactory->forbidden('ADMIN scope required for adapter flush', $requestId);
        }

        if ($request->query->get('confirm') !== 'true') {
            return $this->responseFactory->badRequest(
                'Pass ?confirm=true to proceed with adapter flush',
                $requestId,
            );
        }

        $this->cachePool->clear();

        return $this->responseFactory->ok(['flushed' => true], $requestId);
    }
}
