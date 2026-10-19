<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Response;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Central factory for all Canary HTTP responses.
 *
 * Guarantees that every response — success or failure — carries the
 * mandatory security headers defined in the PRD:
 *   - X-Canary-RequestId   (correlation, set from $requestId param)
 *   - Cache-Control: no-store
 *   - X-Content-Type-Options: nosniff
 *
 * Controllers and RequestGuardSubscriber both go through this factory.
 * Never construct JsonResponse directly in a controller.
 */
final class ResponseFactory
{
    private float $startedAt;

    public function __construct()
    {
        $this->startedAt = microtime(true);
    }

    // ------------------------------------------------------------------
    // Success responses
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    public function ok(array $data, string|null $requestId = null): JsonResponse
    {
        $requestId ??= $this->generateRequestId();
        $envelope = new ResponseEnvelope($data, $requestId, $this->elapsed());

        return $this->json($envelope->toArray(), 200, $requestId);
    }

    public function noContent(string|null $requestId = null): Response
    {
        $response = new Response('', 204);
        $this->applyHeaders($response, $requestId ?? $this->generateRequestId());
        return $response;
    }

    // ------------------------------------------------------------------
    // Error responses (auth failures, rate limiting, etc.)
    // ------------------------------------------------------------------

    public function unauthorized(string|null $requestId = null): JsonResponse
    {
        return $this->error('UNAUTHORIZED', 'Authentication required', 401, $requestId);
    }

    public function forbidden(string $reason, string|null $requestId = null): JsonResponse
    {
        // Deliberately vague — do not leak which check failed.
        return $this->error('FORBIDDEN', 'Access denied', 403, $requestId);
    }

    public function notFound(string|null $requestId = null): JsonResponse
    {
        return $this->error('NOT_FOUND', 'Endpoint not found', 404, $requestId);
    }

    public function tooManyRequests(int $retryAfterSeconds, string|null $requestId = null): JsonResponse
    {
        $requestId ??= $this->generateRequestId();
        $response = $this->error('RATE_LIMITED', 'Too many requests', 429, $requestId);
        $response->headers->set('Retry-After', (string) $retryAfterSeconds);
        return $response;
    }

    public function serviceUnavailable(string $detail, string|null $requestId = null): JsonResponse
    {
        return $this->error('SERVICE_UNAVAILABLE', $detail, 503, $requestId);
    }

    public function badRequest(string $detail, string|null $requestId = null): JsonResponse
    {
        return $this->error('BAD_REQUEST', $detail, 400, $requestId);
    }

    // ------------------------------------------------------------------
    // Health response (RFC application/health+json)
    // ------------------------------------------------------------------

    public function health(HealthResponse $healthResponse): JsonResponse
    {
        $response = $this->json(
            $healthResponse->toArray(),
            $healthResponse->status->httpStatus(),
            $healthResponse->requestId,
        );
        $response->headers->set('Content-Type', 'application/health+json');
        return $response;
    }

    // ------------------------------------------------------------------
    // Prometheus text response
    // ------------------------------------------------------------------

    public function prometheus(string $body, string|null $requestId = null): Response
    {
        $requestId ??= $this->generateRequestId();
        $response = new Response($body, 200);
        $response->headers->set('Content-Type', 'text/plain; version=0.0.4; charset=utf-8');
        $this->applyHeaders($response, $requestId);
        return $response;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    public function generateRequestId(): string
    {
        return 'canary-' . bin2hex(random_bytes(8));
    }

    public function resetTimer(): void
    {
        $this->startedAt = microtime(true);
    }

    private function elapsed(): int
    {
        return (int) round((microtime(true) - $this->startedAt) * 1000);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status, string $requestId): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $this->applyHeaders($response, $requestId);
        return $response;
    }

    private function error(
        string $code,
        string $message,
        int $status,
        string|null $requestId,
    ): JsonResponse {
        $requestId ??= $this->generateRequestId();
        $error = new ErrorResponse($code, $message, $requestId, $this->elapsed());

        return $this->json($error->toArray(), $status, $requestId);
    }

    private function applyHeaders(Response $response, string $requestId): void
    {
        $response->headers->set('X-Canary-RequestId', $requestId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->remove('Server');

        // Symfony's ResponseHeaderBag::computeCacheControlValue() always appends
        // ', private' to any value that doesn't explicitly include 'public' or
        // 'private'. Set both directives explicitly so the header is a stable
        // 'no-store, private': ops responses must not be cached anywhere and are
        // scoped to a single authenticated user.
        $response->headers->remove('Cache-Control');
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->addCacheControlDirective('private');
    }
}
