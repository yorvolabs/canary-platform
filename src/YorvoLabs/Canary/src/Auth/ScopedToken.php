<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

use YorvoLabs\Canary\Exception\RuntimeException;

/**
 * An immutable pair of (scope, raw token string).
 *
 * Constructed from environment variables by TokenAuthenticator.
 * Never constructed from user input — that goes through authenticate().
 */
final class ScopedToken
{
    public const MIN_LENGTH = 64;

    private function __construct(
        public readonly TokenScope $scope,
        private readonly string $token,
    ) {}

    /**
     * @throws RuntimeException if the token is too short
     */
    public static function fromEnv(TokenScope $scope, string $rawToken): self
    {
        if (strlen($rawToken) < self::MIN_LENGTH) {
            throw new RuntimeException(sprintf(
                'Token for scope "%s" is too short (minimum %d characters). '
                . 'Generate one with: bin/console canary:token:generate %s',
                $scope->value,
                self::MIN_LENGTH,
                $scope->value,
            ));
        }

        return new self($scope, $rawToken);
    }

    /**
     * Constant-time comparison — never use === for token comparison.
     */
    public function matches(string $candidate): bool
    {
        return hash_equals($this->token, $candidate);
    }

    /**
     * Returns a redacted representation safe for logging.
     * Shows the first 8 chars only: "abcdefgh..."
     */
    public function redacted(): string
    {
        return substr($this->token, 0, 8) . '...';
    }
}
