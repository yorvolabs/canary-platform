<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Audit;

use YorvoLabs\Canary\Auth\AuthFailureReason;
use YorvoLabs\Canary\Auth\TokenScope;

/**
 * Immutable record of a single Canary request.
 *
 * Captured for every request — authenticated or not — and written
 * to the audit log via AuditLoggerInterface.
 */
final class AuditRecord
{
    public function __construct(
        public readonly \DateTimeImmutable $timestamp,
        public readonly string $ip,
        public readonly TokenScope|null $scope,
        public readonly string $endpoint,
        public readonly string $method,
        public readonly int $statusCode,
        public readonly int $durationMs,
        public readonly AuthFailureReason|null $failureReason,
    ) {}

    public function isFailure(): bool
    {
        return $this->failureReason !== null;
    }

    /**
     * Flat array representation for structured log drivers.
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp->format(\DateTimeInterface::ATOM),
            'ip' => $this->ip,
            'scope' => $this->scope?->value,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'failure_reason' => $this->failureReason?->value,
        ];
    }
}
