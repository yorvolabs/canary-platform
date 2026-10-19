<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Deploy;

/**
 * Immutable result from a single DeployHookInterface execution.
 *
 * durationMs is measured by DeployHookRunner and set on the result after
 * the hook completes (or throws).
 */
final class DeployHookResult
{
    public function __construct(
        public readonly string $name,
        public readonly bool $pass,
        public readonly int $durationMs,
        public readonly string|null $message = null,
    ) {}

    public static function pass(string $name, int $durationMs, string|null $message = null): self
    {
        return new self($name, true, $durationMs, $message);
    }

    public static function fail(string $name, int $durationMs, string $message): self
    {
        return new self($name, false, $durationMs, $message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'name'        => $this->name,
            'pass'        => $this->pass,
            'duration_ms' => $this->durationMs,
        ];

        if ($this->message !== null) {
            $out['detail'] = ['message' => $this->message];
        }

        return $out;
    }
}
