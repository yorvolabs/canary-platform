# Authentication

## Overview

Every Canary endpoint requires a Bearer token in the `Authorization` header. Tokens are scoped: each scope grants access to exactly one group of endpoints. There is no token hierarchy except for the `ADMIN` supertoken, which satisfies all scope checks.

## Token scopes

| Scope | Env var | Endpoints covered | Mutates state? |
|---|---|---|---|
| `MONITORING` | `CANARY_TOKEN_MONITORING` | `/health/*` | No |
| `CACHE` | `CANARY_TOKEN_CACHE` | `/cache/*` | Yes |
| `DIAGNOSTICS` | `CANARY_TOKEN_DIAGNOSTICS` | `/diag/*` | No |
| `DEPLOY` | `CANARY_TOKEN_DEPLOY` | `/deploy/*` | Yes |
| `ADMIN` | `CANARY_TOKEN_ADMIN` | All of the above | Yes |

Scopes are **not hierarchical**: a CACHE token cannot call `/diag/*` and vice-versa. Only `ADMIN` crosses scope boundaries.

## Bearer scheme

Include the token in every request:

```
Authorization: Bearer <token>
```

There is no fallback to query parameters or cookies. This is intentional — it prevents tokens from appearing in server access logs or browser history.

## Token requirements

- Minimum length: **64 characters** (32 bytes of entropy)
- Comparison is constant-time (`hash_equals`) to prevent timing attacks
- Tokens are stored as plain strings in env vars or Shopware's `system_config` table

Generate tokens with:

```bash
bin/console canary:token:generate <scope>
# or in any shell:
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

## Authentication flow

Every request passes through `RequestGuardSubscriber` (a Symfony kernel event subscriber at priority 256), which enforces the following checks in order:

1. **HTTPS enforcement** — If `APP_ENV=prod` and the request is not secure, returns `403 Forbidden`.
2. **Feature flag** — If the endpoint group is disabled via `CANARY_FEATURES`, returns `404 Not Found` (prevents existence leak).
3. **Rate limiting** — If the IP has exceeded the rate limit for the endpoint group, returns `429 Too Many Requests` with a `Retry-After` header.
4. **Token presence** — If the `Authorization` header is missing or malformed, returns `401 Unauthorized`.
5. **Token configured** — If no token is configured for the required scope, returns `503 Service Unavailable`.
6. **Token match** — Constant-time comparison against the configured token. Returns `403 Forbidden` on mismatch.
7. **Scope check** — Verifies the token's scope satisfies the required scope. Returns `403 Forbidden` on failure.
8. **IP allowlist** — If `CANARY_ALLOWED_CIDRS` is set, verifies the client IP is in the list. Returns `403 Forbidden` otherwise.

Requests that pass all checks proceed to the controller. All attempts (pass and fail) are written to the audit log.

## IP allowlist

Set `CANARY_ALLOWED_CIDRS` to a comma-separated list of CIDR ranges to restrict access by IP:

```dotenv
CANARY_ALLOWED_CIDRS=10.0.0.0/8,172.16.0.0/12,::1/128
```

Both IPv4 and IPv6 ranges are supported. An empty value (the default) disables IP filtering and allows requests from any IP.

**Production recommendation:** Always set `CANARY_ALLOWED_CIDRS` to your monitoring infrastructure's egress ranges. Relying solely on token authentication is acceptable for shared hosting where IP context is unreliable, but defense-in-depth is preferable.

## Security headers

Every Canary response includes these headers:

```
X-Canary-RequestId: canary-<uuid>
X-Content-Type-Options: nosniff
Cache-Control: no-store, private
```

## HTTPS enforcement

When `APP_ENV=prod`, any non-HTTPS request is rejected with `403 Forbidden` before the token is even checked. This prevents bearer tokens from being transmitted in plaintext.

In non-production environments (`APP_ENV=dev`, `APP_ENV=test`, etc.) HTTP is allowed, which is useful for local development. **Do not set `APP_ENV=dev` on a production server.**

## Audit logging

Every request — authenticated or not — is written to the Canary audit log channel (`var/log/canary_audit-*.log`). Each entry includes:

- Timestamp
- Client IP
- Scope attempted
- Endpoint path and HTTP method
- Response status code
- Duration in milliseconds
- Failure reason (if applicable)

Sensitive values (tokens, passwords) are never written to the audit log.

## Token rotation

To rotate a token:
1. Generate a new token: `bin/console canary:token:generate <scope>`
2. Deploy the new token value in your secret manager / env vars
3. Remove or override the old token
4. Run `bin/console cache:clear`

There is no built-in grace period for old tokens — the old token is invalid immediately after the new one is deployed.
