<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Response;

use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthRunResult;
use YorvoLabs\Canary\Health\HealthStatus;

/**
 * RFC application/health+json response shape.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check
 *
 * Wire shape:
 * {
 *   "status": "pass",
 *   "checks": [
 *     { "name": "database", "status": "pass", "duration_ms": 3 }
 *   ],
 *   "meta": {
 *     "request_id": "canary-550e8400...",
 *     "duration_ms": 12
 *   }
 * }
 *
 * Use ResponseFactory::health() to turn this into a Symfony Response with
 * the correct Content-Type: application/health+json header.
 */
final class HealthResponse
{
    /** @param list<HealthCheckResult> $checks */
    public function __construct(
        public readonly HealthStatus $status,
        public readonly array $checks,
        public readonly string $requestId,
        public readonly int $durationMs,
    ) {}

    public static function fromRunResult(HealthRunResult $run, string $requestId, int $durationMs): self
    {
        return new self($run->status, $run->results, $requestId, $durationMs);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'checks' => array_map(fn(HealthCheckResult $r) => $r->toArray(), $this->checks),
            'meta'   => [
                'request_id' => $this->requestId,
                'duration_ms' => $this->durationMs,
            ],
        ];
    }
}
