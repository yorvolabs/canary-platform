<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Exception;

use YorvoLabs\Canary\Auth\TokenScope;

/**
 * Thrown when an endpoint requires a scope whose token is not configured.
 *
 * This is a misconfiguration, not a client error.
 * The subscriber catches it and returns 503 — never a stack trace to the client.
 */
final class TokenNotConfiguredException extends RuntimeException
{
    public function __construct(TokenScope $scope)
    {
        parent::__construct(sprintf(
            'No token configured for scope "%s". '
            . 'Set the %s environment variable (minimum 64 characters). '
            . 'Generate one with: bin/console canary:token:generate %s',
            $scope->value,
            $scope->envVar(),
            $scope->value,
        ));
    }
}
