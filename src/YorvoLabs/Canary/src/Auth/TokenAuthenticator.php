<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Exception\TokenNotConfiguredException;

/**
 * Resolves the Bearer token from a request and validates it against
 * the configured scoped tokens.
 *
 * Resolution order:
 *   1. Extract a raw token from the Authorization header (Bearer scheme only).
 *   2. Compare against ADMIN token first — it satisfies any scope.
 *   3. Compare against the token for the required scope.
 *   4. All comparisons via hash_equals() to prevent timing attacks.
 *
 * Tokens are injected as ScopedToken instances (built from env vars
 * by the DI container). A null entry means that scope is not configured.
 */
final class TokenAuthenticator
{
    /** @param array<string, ScopedToken> $tokens Keyed by TokenScope::value; absent key = scope not configured. */
    public function __construct(
        private readonly array $tokens,
    ) {}

    /**
     * Authenticate a request against a required scope.
     *
     * @throws TokenNotConfiguredException if neither the admin nor the required
     *                                     scope token is configured (misconfiguration)
     */
    public function authenticate(Request $request, TokenScope $requiredScope): AuthResult
    {
        $raw = $this->extractBearerToken($request);

        if ($raw === null) {
            return AuthResult::fail(AuthFailureReason::TOKEN_MISSING);
        }

        $adminToken = $this->tokens[TokenScope::ADMIN->value] ?? null;
        $scopeToken = $this->tokens[$requiredScope->value] ?? null;

        if ($adminToken === null && $scopeToken === null) {
            throw new TokenNotConfiguredException($requiredScope);
        }

        // Iterate all configured tokens. Track whether the raw value matches any
        // token at all — used to distinguish TOKEN_INVALID from SCOPE_INSUFFICIENT.
        $validTokenFound = false;
        foreach ($this->tokens as $scopeValue => $scopedToken) {
            $scope = TokenScope::from($scopeValue);
            if ($scopedToken->matches($raw)) {
                $validTokenFound = true;
                if ($scope->satisfies($requiredScope)) {
                    return AuthResult::pass($scope);
                }
            }
        }

        return $validTokenFound
            ? AuthResult::fail(AuthFailureReason::SCOPE_INSUFFICIENT)
            : AuthResult::fail(AuthFailureReason::TOKEN_INVALID);
    }

    private function extractBearerToken(Request $request): ?string
    {
        $header = $request->headers->get('Authorization') ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

}
