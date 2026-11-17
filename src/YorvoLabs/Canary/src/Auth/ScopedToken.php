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

    /** SHA-256 of the token, so comparisons always run over 32 bytes. */
    private readonly string $digest;

    private function __construct(
        public readonly TokenScope $scope,
        private readonly string $token,
    ) {
        $this->digest = hash('sha256', $token, true);
    }

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
     *
     * Compares SHA-256 digests rather than the raw strings: hash_equals() returns
     * immediately when the lengths differ, which would reveal the configured
     * token's length. Digests are always 32 bytes.
     */
    public function matches(string $candidate): bool
    {
        return hash_equals($this->digest, hash('sha256', $candidate, true));
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
