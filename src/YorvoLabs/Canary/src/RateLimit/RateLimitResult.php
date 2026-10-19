<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\RateLimit;

/**
 * Result of a single rate limit check.
 */
final class RateLimitResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly int $retryAfterSeconds,
    ) {}

    public static function allow(int $remaining): self
    {
        return new self(true, $remaining, 0);
    }

    public static function deny(int $retryAfterSeconds): self
    {
        return new self(false, 0, $retryAfterSeconds);
    }
}
