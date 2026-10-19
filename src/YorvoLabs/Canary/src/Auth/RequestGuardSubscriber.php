<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Configuration\Feature;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Configuration\RateLimitGroup;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use YorvoLabs\Canary\Contract\RateLimiterInterface;
use YorvoLabs\Canary\Exception\TokenNotConfiguredException;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Guards all Canary routes.
 *
 * Runs at priority 256 — well above Shopware's routing layer — so that
 * unauthorized requests are rejected before any controller is instantiated.
 *
 * Layer execution order (first failure terminates):
 *   0. Feature flag check — disabled groups return 404, no audit entry
 *   1. HTTPS enforcement (production only)
 *   2. IP allowlist check
 *   3. Token authentication + scope validation
 *   4. Rate limiting
 *
 * Path → (feature, scope, rate-limit group) resolution is centralised in
 * resolvePath() — a single place to update when new endpoint groups are added.
 *
 * Every request that reaches layer 1 or beyond is written to the audit log.
 * Disabled-feature 404s are intentionally not audited to avoid noise.
 *
 * A single request ID is generated at the start of every ops request and
 * threaded through all error responses. On success it is stored as the
 * '_canary_request_id' request attribute for controllers to use.
 */
final class RequestGuardSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Options $config,
        private readonly TokenAuthenticator $authenticator,
        private readonly IpAllowlistChecker $ipChecker,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ResponseFactory $responseFactory,
        private readonly AuditLoggerInterface $auditLogger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 256],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $prefix = $this->config->getRoutePrefix();

        if (!str_starts_with($path, $prefix . '/')) {
            return;
        }

        // Reset the factory timer so elapsed() measures this request, not DI boot.
        $this->responseFactory->resetTimer();

        // One request ID threaded through every response for this request.
        $requestId = $this->responseFactory->generateRequestId();

        $resolved = $this->resolvePath($path);

        // Layer 0: Feature flag — disabled endpoint groups return 404.
        // Intentionally not audited: revealing that an endpoint exists but is
        // disabled would expose configuration details to unauthenticated callers.
        if ($resolved['feature'] !== null && !$this->config->isFeatureEnabled($resolved['feature'])) {
            $event->setResponse($this->responseFactory->notFound($requestId));
            return;
        }

        $startedAt = microtime(true);
        $ip = $request->getClientIp() ?? '0.0.0.0';

        // Layer 1: HTTPS enforcement in production.
        if ($this->config->isProduction() && !$request->isSecure()) {
            $this->audit($ip, null, $path, $request->getMethod(), 403, $startedAt, AuthFailureReason::HTTPS_REQUIRED);
            $event->setResponse($this->responseFactory->forbidden('HTTPS required in production', $requestId));
            return;
        }

        // Layer 2: IP allowlist.
        if (!$this->ipChecker->isAllowed($ip)) {
            $this->audit($ip, null, $path, $request->getMethod(), 403, $startedAt, AuthFailureReason::IP_NOT_ALLOWED);
            $event->setResponse($this->responseFactory->forbidden('IP not in allowlist', $requestId));
            return;
        }

        // Layer 3: Token authentication + scope.
        try {
            $authResult = $this->authenticator->authenticate($request, $resolved['scope']);
        } catch (TokenNotConfiguredException) {
            $this->audit($ip, null, $path, $request->getMethod(), 503, $startedAt, AuthFailureReason::TOKEN_NOT_CONFIGURED);
            $event->setResponse($this->responseFactory->serviceUnavailable('Token not configured', $requestId));
            return;
        }

        if ($authResult->failed()) {
            $httpStatus = $authResult->httpStatus();
            $this->audit($ip, null, $path, $request->getMethod(), $httpStatus, $startedAt, $authResult->reason);
            $event->setResponse(
                $httpStatus === 401
                    ? $this->responseFactory->unauthorized($requestId)
                    : $this->responseFactory->forbidden('Authentication failed', $requestId),
            );
            return;
        }

        // Layer 4: Rate limiting.
        $rateLimit = $this->config->getRateLimit($resolved['rateGroup']);
        $rateLimitKey = "{$resolved['rateGroup']->value}:{$ip}";
        $rateLimitResult = $this->rateLimiter->check($rateLimitKey, $rateLimit);

        if (!$rateLimitResult->allowed) {
            $this->audit($ip, $authResult->scope, $path, $request->getMethod(), 429, $startedAt, AuthFailureReason::RATE_LIMITED);
            $event->setResponse($this->responseFactory->tooManyRequests($rateLimitResult->retryAfterSeconds, $requestId));
            return;
        }

        // All layers passed — store resolved scope and request ID for controllers.
        $request->attributes->set('_canary_scope', $authResult->scope);
        $request->attributes->set('_canary_request_id', $requestId);

        $this->audit($ip, $authResult->scope, $path, $request->getMethod(), 200, $startedAt, null);
    }

    /**
     * Maps a path to its feature gate, required token scope, and rate-limit group.
     * Single place to update when adding new endpoint groups.
     *
     * @return array{feature: Feature|null, scope: TokenScope, rateGroup: RateLimitGroup}
     */
    private function resolvePath(string $path): array
    {
        $p = $this->config->getRoutePrefix();

        return match (true) {
            str_starts_with($path, $p . '/health/')  => ['feature' => Feature::HEALTH,       'scope' => TokenScope::MONITORING,   'rateGroup' => RateLimitGroup::HEALTH],
            str_starts_with($path, $p . '/cache/')   => ['feature' => Feature::CACHE,         'scope' => TokenScope::CACHE,         'rateGroup' => RateLimitGroup::MUTATION],
            str_starts_with($path, $p . '/diag/')    => ['feature' => Feature::DIAGNOSTICS,   'scope' => TokenScope::DIAGNOSTICS,   'rateGroup' => RateLimitGroup::MUTATION],
            str_starts_with($path, $p . '/deploy/')  => ['feature' => Feature::DEPLOY,         'scope' => TokenScope::DEPLOY,        'rateGroup' => RateLimitGroup::MUTATION],
            default                                   => ['feature' => null,                   'scope' => TokenScope::ADMIN,         'rateGroup' => RateLimitGroup::MUTATION],
        };
    }

    private function audit(
        string $ip,
        TokenScope|null $scope,
        string $endpoint,
        string $method,
        int $statusCode,
        float $startedAt,
        AuthFailureReason|null $failureReason,
    ): void {
        $this->auditLogger->log(new AuditRecord(
            timestamp: new \DateTimeImmutable(),
            ip: $ip,
            scope: $scope,
            endpoint: $endpoint,
            method: $method,
            statusCode: $statusCode,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            failureReason: $failureReason,
        ));
    }
}
