<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Response;

/**
 * Standard success envelope for all Canary responses.
 *
 * Wire shape:
 * {
 *   "ok": true,
 *   "data": { ... },
 *   "meta": {
 *     "request_id": "canary-550e8400...",
 *     "duration_ms": 12
 *   }
 * }
 */
final class ResponseEnvelope
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly array $data,
        public readonly string $requestId,
        public readonly int $durationMs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => true,
            'data' => $this->data,
            'meta' => [
                'request_id' => $this->requestId,
                'duration_ms' => $this->durationMs,
            ],
        ];
    }
}
