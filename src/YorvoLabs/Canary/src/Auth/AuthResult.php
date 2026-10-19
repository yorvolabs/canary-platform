<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

/**
 * Immutable result of an authentication check.
 *
 * The reason code is machine-readable for internal use (audit logging,
 * response mapping). It is NEVER sent to the client — all failures
 * surface as the same opaque 401/403 to prevent oracle attacks.
 */
final class AuthResult
{
    private function __construct(
        public readonly bool $passed,
        public readonly AuthFailureReason|null $reason,
        public readonly TokenScope|null $scope,
    ) {}

    public static function pass(TokenScope $scope): self
    {
        return new self(true, null, $scope);
    }

    public static function fail(AuthFailureReason $reason): self
    {
        return new self(false, $reason, null);
    }

    public function failed(): bool
    {
        return !$this->passed;
    }

    /**
     * HTTP status code for the failure.
     * 401 = missing credentials, 403 = present but rejected.
     */
    public function httpStatus(): int
    {
        if ($this->passed) {
            return 200;
        }

        return match ($this->reason) {
            AuthFailureReason::TOKEN_MISSING      => 401,
            AuthFailureReason::TOKEN_INVALID      => 401,
            AuthFailureReason::SCOPE_INSUFFICIENT => 403,
            AuthFailureReason::IP_NOT_ALLOWED     => 403,
            AuthFailureReason::HTTPS_REQUIRED     => 403,
            // The following two are handled directly in RequestGuardSubscriber before
            // AuthResult is consulted; listed here so the match remains exhaustive.
            AuthFailureReason::RATE_LIMITED         => 429,
            AuthFailureReason::TOKEN_NOT_CONFIGURED => 503,
            null                                    => 500,
        };
    }
}
