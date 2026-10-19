<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Response;

/**
 * Standard error envelope for all Canary failure responses.
 *
 * Wire shape:
 * {
 *   "ok": false,
 *   "error": {
 *     "code": "HTTPS_REQUIRED",
 *     "message": "HTTPS required in production"
 *   },
 *   "meta": {
 *     "request_id": "canary-550e8400...",
 *     "duration_ms": 1
 *   }
 * }
 *
 * Error codes are machine-readable uppercase strings.
 * Messages are human-readable but deliberately vague for auth failures
 * to avoid leaking which specific check failed.
 */
final class ErrorResponse
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly string $requestId,
        public readonly int $durationMs,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'ok' => false,
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
            ],
            'meta' => [
                'request_id' => $this->requestId,
                'duration_ms' => $this->durationMs,
            ],
        ];
    }
}
