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
 *      Missing or non-Bearer → TOKEN_MISSING.
 *   2. If neither the ADMIN token nor the required scope's token is configured,
 *      throw TokenNotConfiguredException (misconfiguration → 503).
 *   3. Compare the raw token against every configured token, without stopping
 *      at the first match, and decide the result only after the loop. The work
 *      done is the same whichever token (if any) matches.
 *   4. A matching token passes if its scope satisfies the required scope
 *      (ADMIN satisfies every scope); a matching token with another scope gives
 *      SCOPE_INSUFFICIENT; no match gives TOKEN_INVALID.
 *
 * Each comparison is constant-time (see ScopedToken::matches()).
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

        // Compare against every configured token; never return early. Track the
        // first satisfying scope and whether anything matched at all (to tell
        // TOKEN_INVALID from SCOPE_INSUFFICIENT), and decide after the loop.
        $passedScope = null;
        $validTokenFound = false;
        foreach ($this->tokens as $scopedToken) {
            $matches = $scopedToken->matches($raw);
            $validTokenFound = $validTokenFound || $matches;
            if ($matches && $passedScope === null && $scopedToken->scope->satisfies($requiredScope)) {
                $passedScope = $scopedToken->scope;
            }
        }

        if ($passedScope !== null) {
            return AuthResult::pass($passedScope);
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
