<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Health;

/**
 * Immutable result from a single HealthCheckInterface implementation.
 */
final class HealthCheckResult
{
    /**
     * @param array<string, mixed> $detail Optional key-value pairs for additional context.
     *                                     E.g. ["response_time_ms" => 3, "host" => "redis:6379"]
     *                                     Never include credentials or internal IPs.
     */
    public function __construct(
        public readonly string $name,
        public readonly HealthStatus $status,
        public readonly int $durationMs,
        public readonly array $detail = [],
        public readonly string|null $error = null,
    ) {}

    /** @param array<string, mixed> $detail */
    public static function pass(string $name, int $durationMs, array $detail = []): self
    {
        return new self($name, HealthStatus::PASS, $durationMs, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function warn(string $name, int $durationMs, string $error, array $detail = []): self
    {
        return new self($name, HealthStatus::WARN, $durationMs, $detail, $error);
    }

    /** @param array<string, mixed> $detail */
    public static function fail(string $name, int $durationMs, string $error, array $detail = []): self
    {
        return new self($name, HealthStatus::FAIL, $durationMs, $detail, $error);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'status' => $this->status->value,
            'duration_ms' => $this->durationMs,
        ];

        if ($this->detail !== []) {
            $out['detail'] = $this->detail;
        }

        if ($this->error !== null) {
            $out['error'] = $this->error;
        }

        return $out;
    }
}
