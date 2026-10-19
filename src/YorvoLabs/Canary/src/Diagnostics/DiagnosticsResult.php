<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Diagnostics;

final class DiagnosticsResult
{
    /**
     * @param array<string, mixed> $detail
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $pass,
        public readonly string|null $message = null,
        public readonly array $detail = [],
    ) {}

    /** @param array<string, mixed> $detail */
    public static function pass(string $name, string|null $message = null, array $detail = []): self
    {
        return new self($name, true, $message, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function fail(string $name, string $message, array $detail = []): self
    {
        return new self($name, false, $message, $detail);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'name' => $this->name,
            'pass' => $this->pass,
        ];

        if ($this->message !== null) {
            $out['message'] = $this->message;
        }

        if ($this->detail !== []) {
            $out['detail'] = $this->detail;
        }

        return $out;
    }
}
