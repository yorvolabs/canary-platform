<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

/**
 * Typed reason codes for authentication failures.
 *
 * Used internally for audit logging and response mapping.
 * Never exposed in API responses.
 */
enum AuthFailureReason: string
{
    /** No Authorization header was present */
    case TOKEN_MISSING = 'token_missing';

    /** Authorization header was present but token did not match any configured token */
    case TOKEN_INVALID = 'token_invalid';

    /** Token is valid, but its scope does not satisfy the required scope */
    case SCOPE_INSUFFICIENT = 'scope_insufficient';

    /** Request IP is not in the CANARY_ALLOWED_CIDRS allowlist */
    case IP_NOT_ALLOWED = 'ip_not_allowed';

    /** Non-HTTPS request in production environment */
    case HTTPS_REQUIRED = 'https_required';

    /** Request exceeded the rate limit for this endpoint group */
    case RATE_LIMITED = 'rate_limited';

    /** No token is configured for the required scope (misconfiguration) */
    case TOKEN_NOT_CONFIGURED = 'token_not_configured';
}
