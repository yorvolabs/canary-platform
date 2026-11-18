<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
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
 *   0. Route group — a path under the prefix that matches no endpoint group,
 *      or matches a disabled group, returns 404, no audit entry
 *   1. HTTPS enforcement (production only) — 403
 *   2. IP allowlist — 403
 *   3. Rate limiting — 429 (counted per group and IP, before authentication,
 *      so failed token attempts consume the limit)
 *   4. Token authentication + scope — 401 missing, 503 not configured,
 *      401 wrong token, 403 insufficient scope
 *
 * The allowlist runs before the rate limiter so that requests from blocked
 * IPs are rejected without touching rate-limiter state: they cannot create
 * buckets or cause rate-limiter writes.
 *
 * Path → (feature, scope, rate-limit group) resolution is centralised in
 * resolvePath() — a single place to update when new endpoint groups are added.
 *
 * Audit: every main request that reaches layer 1 or beyond produces exactly one
 * audit record. Rejections are recorded with the guard's status code when they
 * happen. Requests that pass every layer are recorded on kernel.response with
 * the status code of the response actually sent (controller result, or the
 * error response if the controller threw). Not audited: layer-0 404s (unknown
 * path or disabled group), sub-requests, and passed requests for which the kernel never
 * dispatches kernel.response (e.g. a fatal PHP error).
 *
 * A single request ID is generated at the start of every ops request and
 * threaded through all error responses. On success it is stored as the
 * '_canary_request_id' request attribute for controllers to use.
 */
final class RequestGuardSubscriber implements EventSubscriberInterface
{
    /**
     * Requests that passed every layer and still need their audit record,
     * written once the real response status is known.
     *
     * @var \WeakMap<Request, array{ip: string, scope: TokenScope, startedAt: float}>
     */
    private \WeakMap $pendingAudits;

    public function __construct(
        private readonly Options $config,
        private readonly TokenAuthenticator $authenticator,
        private readonly IpAllowlistChecker $ipChecker,
        private readonly RateLimiterInterface $rateLimiter,
        private readonly ResponseFactory $responseFactory,
        private readonly AuditLoggerInterface $auditLogger,
    ) {
        $this->pendingAudits = new \WeakMap();
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST  => ['onKernelRequest', 256],
            // Lowest priority: record the status after every other listener has
            // had the chance to change the response.
            KernelEvents::RESPONSE => ['onKernelResponse', -1024],
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

        // Layer 0: Route group — a path that matches no endpoint group and a path
        // in a disabled group get the same 404, before any other check and
        // without an audit record, so a caller cannot tell a disabled group from
        // a route that does not exist.
        if ($resolved === null || !$this->config->isFeatureEnabled($resolved['feature'])) {
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

        // Layer 3: Rate limiting — before authentication, so every attempt that
        // reaches the token check counts, including failed ones. The scope is not
        // known yet, so the audit record carries scope: null.
        $rateLimit = $this->config->getRateLimit($resolved['rateGroup']);
        $rateLimitKey = "{$resolved['rateGroup']->value}:{$ip}";
        $rateLimitResult = $this->rateLimiter->check($rateLimitKey, $rateLimit);

        if (!$rateLimitResult->allowed) {
            $this->audit($ip, null, $path, $request->getMethod(), 429, $startedAt, AuthFailureReason::RATE_LIMITED);
            $event->setResponse($this->responseFactory->tooManyRequests($rateLimitResult->retryAfterSeconds, $requestId));
            return;
        }

        // Layer 4: Token authentication + scope.
        try {
            $authResult = $this->authenticator->authenticate($request, $resolved['scope']);
        } catch (TokenNotConfiguredException) {
            $this->audit($ip, null, $path, $request->getMethod(), 503, $startedAt, AuthFailureReason::TOKEN_NOT_CONFIGURED);
            $event->setResponse($this->responseFactory->serviceUnavailable('Token not configured', $requestId));
            return;
        }

        if ($authResult->failed() || $authResult->scope === null) {
            $httpStatus = $authResult->httpStatus();
            $this->audit($ip, null, $path, $request->getMethod(), $httpStatus, $startedAt, $authResult->reason);
            $event->setResponse(
                $httpStatus === 401
                    ? $this->responseFactory->unauthorized($requestId)
                    : $this->responseFactory->forbidden('Authentication failed', $requestId),
            );
            return;
        }

        // All layers passed — store resolved scope and request ID for controllers.
        $request->attributes->set('_canary_scope', $authResult->scope);
        $request->attributes->set('_canary_request_id', $requestId);

        // The audit record is written in onKernelResponse(), with the real status.
        $this->pendingAudits[$request] = ['ip' => $ip, 'scope' => $authResult->scope, 'startedAt' => $startedAt];
    }

    /**
     * Writes the audit record for a request that passed the guard, using the
     * status code of the response that is actually sent.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $pending = $this->pendingAudits[$request] ?? null;

        if ($pending === null) {
            return;
        }

        unset($this->pendingAudits[$request]);

        $this->audit(
            $pending['ip'],
            $pending['scope'],
            $request->getPathInfo(),
            $request->getMethod(),
            $event->getResponse()->getStatusCode(),
            $pending['startedAt'],
            null,
        );
    }

    /**
     * Maps a path to its feature gate, required token scope, and rate-limit group.
     * Single place to update when adding new endpoint groups.
     *
     * Returns null for a path under the prefix that belongs to no group: there
     * are no routes outside the groups, so such a path is answered with 404.
     *
     * @return array{feature: Feature, scope: TokenScope, rateGroup: RateLimitGroup}|null
     */
    private function resolvePath(string $path): array|null
    {
        $p = $this->config->getRoutePrefix();

        return match (true) {
            str_starts_with($path, $p . '/health/')  => ['feature' => Feature::HEALTH,       'scope' => TokenScope::MONITORING,   'rateGroup' => RateLimitGroup::HEALTH],
            str_starts_with($path, $p . '/cache/')   => ['feature' => Feature::CACHE,         'scope' => TokenScope::CACHE,         'rateGroup' => RateLimitGroup::MUTATION],
            str_starts_with($path, $p . '/diag/')    => ['feature' => Feature::DIAGNOSTICS,   'scope' => TokenScope::DIAGNOSTICS,   'rateGroup' => RateLimitGroup::MUTATION],
            str_starts_with($path, $p . '/deploy/')  => ['feature' => Feature::DEPLOY,         'scope' => TokenScope::DEPLOY,        'rateGroup' => RateLimitGroup::MUTATION],
            default                                   => null,
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
