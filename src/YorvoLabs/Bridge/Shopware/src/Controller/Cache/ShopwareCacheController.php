<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Cache;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Shopware cache-clearing endpoints.
 *
 * POST {prefix}/cache/shopware          → clear all configured pools
 * POST {prefix}/cache/shopware/{pool}   → clear a named pool (object | http)
 *
 * The HTTP pool ($httpPool) is optional: if the cache.http service is not wired
 * in DI (e.g. HTTP caching disabled), that argument will be null and the pool is
 * silently skipped in the full-clear response.
 *
 * Both routes require CACHE scope (enforced by RequestGuardSubscriber).
 */
final class ShopwareCacheController
{
    /** @var array<string, CacheItemPoolInterface> */
    private array $pools;

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        CacheItemPoolInterface $objectPool,
        CacheItemPoolInterface|null $httpPool,
    ) {
        $this->pools = ['object' => $objectPool];

        if ($httpPool !== null) {
            $this->pools['http'] = $httpPool;
        }
    }

    #[Route(
        '/cache/shopware',
        name: 'canary.cache.shopware.clear',
        methods: ['POST'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function clearAll(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        $results = [];
        foreach ($this->pools as $name => $pool) {
            $pool->clear();
            $results[$name] = true;
        }

        return $this->responseFactory->ok(['cleared' => $results], $requestId);
    }

    #[Route(
        '/cache/shopware/{pool}',
        name: 'canary.cache.shopware.clear_pool',
        methods: ['POST'],
        requirements: ['pool' => 'object|http'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function clearPool(Request $request, string $pool): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        if (!isset($this->pools[$pool])) {
            return $this->responseFactory->notFound($requestId);
        }

        $this->pools[$pool]->clear();

        return $this->responseFactory->ok(['pool' => $pool, 'cleared' => true], $requestId);
    }
}
