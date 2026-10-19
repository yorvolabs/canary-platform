<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Administration\Controller;

use Shopware\Core\Framework\Routing\ApiRouteScope;
use Shopware\Core\PlatformRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Bridge\Shopware\Administration\CanaryControllerLocator;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Configuration\RateLimitGroup;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Server-side proxy for the Shopware Administration UI.
 *
 * Vue components call these /api/_action/canary/* routes, protected by
 * Shopware's admin JWT. This controller forwards requests to the underlying
 * services directly — no HTTP round-trip, no Canary token in the browser.
 *
 * ACL permission required: canary:viewer
 */
#[Route(defaults: [
    PlatformRequest::ATTRIBUTE_ROUTE_SCOPE => [ApiRouteScope::ID],
    'auth_required'                        => true,
])]
final class CanaryAdminController
{
    private const MAX_AUDIT_LINES     = 500;
    private const DEFAULT_AUDIT_LINES = 100;

    public function __construct(
        private readonly CanaryControllerLocator $controllers,
        private readonly Options $options,
        private readonly DeployHookRunner $deployHookRunner,
        private readonly string $rateLimitDir,
        private readonly ResponseFactory $responseFactory,
        private readonly string $logDir,
        private readonly string $appUrl,
        private readonly SensitiveValueMasker $masker,
    ) {}

    #[Route(
        path: '/api/_action/canary/diag/env',
        name: 'api.action.canary.diag.env',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagEnv(Request $request): Response
    {
        return ($this->controllers->env)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/config',
        name: 'api.action.canary.diag.config',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagConfig(Request $request): Response
    {
        return ($this->controllers->config)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/logs',
        name: 'api.action.canary.diag.logs',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagLogs(Request $request): Response
    {
        $fileParam = $request->query->get('file');

        if ($fileParam !== null) {
            return $this->readSpecificLogFile($request, $fileParam);
        }

        return ($this->controllers->log)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/log-files',
        name: 'api.action.canary.diag.log_files',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagLogFiles(Request $request): Response
    {
        return $this->responseFactory->ok(['files' => $this->controllers->log->listFiles()]);
    }

    #[Route(
        path: '/api/_action/canary/diag/queue',
        name: 'api.action.canary.diag.queue',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagQueue(Request $request): Response
    {
        return ($this->controllers->queue)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/plugins',
        name: 'api.action.canary.diag.plugins',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagPlugins(Request $request): Response
    {
        return ($this->controllers->plugins)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/readiness',
        name: 'api.action.canary.diag.readiness',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagReadiness(Request $request): Response
    {
        // ReadinessController returns health+json (non-standard envelope) — re-wrap it.
        $inner   = ($this->controllers->readiness)($request);
        $decoded = json_decode((string) $inner->getContent(), true) ?? [];

        return $this->responseFactory->ok($decoded);
    }

    #[Route(
        path: '/api/_action/canary/diag/metrics',
        name: 'api.action.canary.diag.metrics',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagMetrics(Request $request): Response
    {
        return ($this->controllers->metrics)($request);
    }

    #[Route(
        path: '/api/_action/canary/diag/opcache',
        name: 'api.action.canary.diag.opcache',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function diagOpcache(Request $request): Response
    {
        return $this->controllers->opcache->status($request);
    }

    #[Route(
        path: '/api/_action/canary/cache/opcache/reset',
        name: 'api.action.canary.cache.opcache.reset',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['POST']
    )]
    public function cacheOpcacheReset(Request $request): Response
    {
        return $this->controllers->opcache->doReset();
    }

    #[Route(
        path: '/api/_action/canary/cache/shopware',
        name: 'api.action.canary.cache.shopware',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['POST']
    )]
    public function cacheShopwareClear(Request $request): Response
    {
        return $this->controllers->shopwareCache->clearAll($request);
    }

    #[Route(
        path: '/api/_action/canary/info',
        name: 'api.action.canary.info',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function info(Request $request): Response
    {
        $tokenStatus = [];
        foreach (TokenScope::cases() as $scope) {
            $tokenStatus[$scope->value] = [
                'label'      => $scope->label(),
                'configured' => $this->options->getToken($scope) !== null,
            ];
        }

        $appUrl = rtrim($this->appUrl, '/');

        return $this->responseFactory->ok([
            'app_url'       => $appUrl,
            'route_prefix'  => $this->options->getRoutePrefix(),
            'tokens'        => $tokenStatus,
            'rate_limits'   => [
                'health'   => $this->options->getRateLimit(RateLimitGroup::HEALTH),
                'mutation' => $this->options->getRateLimit(RateLimitGroup::MUTATION),
            ],
            'allowed_cidrs' => $this->options->getAllowedCidrs(),
        ]);
    }

    #[Route(
        path: '/api/_action/canary/deploy-hooks',
        name: 'api.action.canary.deploy_hooks',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function deployHooks(Request $request): Response
    {
        return $this->responseFactory->ok([
            'hooks' => $this->deployHookRunner->getHookDescriptions(),
        ]);
    }

    #[Route(
        path: '/api/_action/canary/rate-limit-stats',
        name: 'api.action.canary.rate_limit_stats',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function rateLimitStats(Request $request): Response
    {
        $files       = glob($this->rateLimitDir . '/*.json') ?: [];
        $windowStart = time() - 60;
        $active      = 0;
        $totalHits   = 0;

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($content === false || $content === '') {
                continue;
            }
            $timestamps = json_decode($content, true);
            if (!is_array($timestamps)) {
                continue;
            }
            $inWindow = array_filter($timestamps, fn(int $ts): bool => $ts > $windowStart);
            if ($inWindow !== []) {
                $active++;
                $totalHits += count($inWindow);
            }
        }

        return $this->responseFactory->ok([
            'configured'             => [
                'health'   => $this->options->getRateLimit(RateLimitGroup::HEALTH),
                'mutation' => $this->options->getRateLimit(RateLimitGroup::MUTATION),
            ],
            'active_buckets'         => $active,
            'total_hits_last_minute' => $totalHits,
            'window_seconds'         => 60,
        ]);
    }

    #[Route(
        path: '/api/_action/canary/audit',
        name: 'api.action.canary.audit',
        defaults: [PlatformRequest::ATTRIBUTE_ACL => ['canary:viewer']],
        methods: ['GET']
    )]
    public function audit(Request $request): Response
    {
        $lines   = max(1, min(self::MAX_AUDIT_LINES, (int) ($request->query->get('lines') ?? self::DEFAULT_AUDIT_LINES)));
        $logFile = $this->logDir . '/canary_audit.log';

        if (!is_file($logFile)) {
            return $this->responseFactory->notFound();
        }

        $allLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $allLines = $allLines !== false ? $allLines : [];
        $tail     = array_slice($allLines, -$lines);
        $entries  = array_map(fn(string $line): string => $this->masker->maskText($line), $tail);

        return $this->responseFactory->ok([
            'file'           => 'canary_audit.log',
            'lines_returned' => count($entries),
            'entries'        => $entries,
        ]);
    }

    private function readSpecificLogFile(Request $request, string $fileName): Response
    {
        $safe = basename($fileName);

        if (!preg_match('/^[\w\-\.]+\.log$/', $safe)) {
            return $this->responseFactory->badRequest('Invalid file name');
        }

        $path = $this->logDir . '/' . $safe;

        if (!is_file($path)) {
            return $this->responseFactory->notFound();
        }

        $lines    = max(1, min(self::MAX_AUDIT_LINES, (int) ($request->query->get('lines') ?? self::DEFAULT_AUDIT_LINES)));
        $allLines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $tail     = array_slice($allLines, -$lines);
        $entries  = array_map(fn(string $l): string => $this->masker->maskText($l), $tail);

        return $this->responseFactory->ok([
            'file'           => $safe,
            'format'         => 'text',
            'lines_returned' => count($entries),
            'entries'        => $entries,
        ]);
    }

}
