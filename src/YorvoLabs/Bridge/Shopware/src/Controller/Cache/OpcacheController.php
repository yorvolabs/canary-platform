<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Cache;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * OPcache status and reset endpoints.
 *
 * GET  {prefix}/cache/opcache              → status snapshot (hit rate, memory, script count)
 * POST {prefix}/cache/opcache/reset        → invalidate all compiled scripts via opcache_reset()
 *                                        requires ?confirm=true to prevent accidental calls;
 *                                        forces a full recompile spike on next requests
 *
 * Both routes require CACHE scope (enforced by RequestGuardSubscriber).
 * Named action methods are used here (rather than __invoke) because this controller
 * exposes two distinct operations at different paths.
 */
final class OpcacheController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
    ) {}

    #[Route(
        '/cache/opcache',
        name: 'canary.cache.opcache.status',
        methods: ['GET'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function status(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        if (!function_exists('opcache_get_status')) {
            return $this->responseFactory->ok([
                'enabled' => false,
                'reason' => 'OPcache extension not loaded',
            ], $requestId);
        }

        $raw = opcache_get_status(false);

        if ($raw === false) {
            return $this->responseFactory->ok([
                'enabled' => false,
                'reason' => 'OPcache is disabled',
            ], $requestId);
        }

        $stats = $raw['opcache_statistics'] ?? [];
        $memory = $raw['memory_usage'] ?? [];

        return $this->responseFactory->ok([
            'enabled' => $raw['opcache_enabled'],
            'cache_full' => $raw['cache_full'] ?? false,
            'restart_pending' => $raw['restart_pending'] ?? false,
            'memory' => [
                'used_bytes' => (int) ($memory['used_memory'] ?? 0),
                'free_bytes' => (int) ($memory['free_memory'] ?? 0),
                'wasted_bytes' => (int) ($memory['wasted_memory'] ?? 0),
                'wasted_percent' => round((float) ($memory['current_wasted_percentage'] ?? 0.0), 2),
            ],
            'statistics' => [
                'cached_scripts' => (int) ($stats['num_cached_scripts'] ?? 0),
                'hits' => (int) ($stats['hits'] ?? 0),
                'misses' => (int) ($stats['misses'] ?? 0),
                'hit_rate' => round((float) ($stats['opcache_hit_rate'] ?? 0.0), 4),
            ],
        ], $requestId);
    }

    #[Route(
        '/cache/opcache/reset',
        name: 'canary.cache.opcache.reset',
        methods: ['POST'],
        defaults: ['_routeScope' => ['api'], 'auth_required' => false]
    )]
    public function reset(Request $request): JsonResponse
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        if ($request->query->get('confirm') !== 'true') {
            return $this->responseFactory->badRequest(
                'Pass ?confirm=true to reset OPcache — this forces a full recompile spike',
                $requestId,
            );
        }

        return $this->doReset($requestId);
    }

    public function doReset(?string $requestId = null): JsonResponse
    {
        if (!function_exists('opcache_reset')) {
            return $this->responseFactory->serviceUnavailable('OPcache extension not available', $requestId);
        }

        return $this->responseFactory->ok(['reset' => opcache_reset()], $requestId);
    }
}
