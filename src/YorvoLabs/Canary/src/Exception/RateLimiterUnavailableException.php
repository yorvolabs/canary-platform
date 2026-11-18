<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Exception;

/**
 * Thrown by a rate limiter that cannot read or write its state.
 *
 * RequestGuardSubscriber fails open for the health group (monitoring keeps
 * working) and closed for the other groups (503).
 */
final class RateLimiterUnavailableException extends RuntimeException {}
