<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Health;

/**
 * Aggregated result of running all registered health checks.
 *
 * Shapes the RFC application/health+json response body.
 *
 * @see https://datatracker.ietf.org/doc/html/draft-inadarei-api-health-check
 */
final class HealthRunResult
{
    /**
     * @param list<HealthCheckResult> $results
     */
    public function __construct(
        public readonly HealthStatus $status,
        /** @var list<HealthCheckResult> */
        public readonly array $results,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'checks' => array_map(fn(HealthCheckResult $r) => $r->toArray(), $this->results),
        ];
    }
}
