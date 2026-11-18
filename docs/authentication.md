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

- Minimum length: **64 characters**. The check measures length only; generate tokens as 32 random bytes, hex-encoded (commands below), so that they are also random
- Comparison uses `hash_equals()` over SHA-256 digests and checks every configured token, designed to avoid timing differences (not measured)
- Tokens are stored as plain strings in env vars or Shopware's `system_config` table

Generate tokens with:

```bash
# Shopware plugin:
bin/console canary:token:generate <scope>
# any shell:
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

## Authentication flow

Every request under the Canary route prefix passes through `RequestGuardSubscriber` (a Symfony `kernel.request` subscriber at priority 256). It runs the checks below in this order; the first check that fails decides the response.

| # | Check | Fails when | Response |
|---|---|---|---|
| 1 | **Route group** | The path matches no endpoint group (`/health/`, `/cache/`, `/diag/`, `/deploy/`), or its group is disabled via `CANARY_FEATURES` | `404 Not Found` (not audited; both cases give the same response) |
| 2 | **HTTPS** | `APP_ENV=prod` and the request is not secure | `403 Forbidden` |
| 3 | **IP allowlist** | `CANARY_ALLOWED_CIDRS` is set and the client IP is not in it | `403 Forbidden` |
| 4 | **Rate limit** | The client IP (IPv6: its /64) has used up the limit for the endpoint group | `429 Too Many Requests` with `Retry-After` |
|   |  | The rate limiter cannot read or write its state | `/health/*`: allowed; other groups: `503 Service Unavailable` |
| 5 | **Token** | No `Authorization: Bearer` header, or an empty value | `401 Unauthorized` |
|   |  | Neither the scope's token nor the `ADMIN` token is configured | `503 Service Unavailable` |
|   |  | The token matches no configured token | `401 Unauthorized` |
|   |  | The token matches a configured token whose scope does not cover the endpoint | `403 Forbidden` |

Requests that pass all checks proceed to the controller.

Every `401` carries `WWW-Authenticate: Bearer` (RFC 9110 §15.5.2, RFC 6750 §3).

**What the `404` hides.** A path under the prefix that matches no endpoint group and a path in a disabled group get the same `404` (same body shape and headers), so a caller cannot tell a disabled group from a route that does not exist. It does not hide which groups are enabled: an enabled group answers `401` to a caller without a token. The group names are listed in these docs anyway; the main purpose of check 1 is that code in a disabled group is never reached. The `404` is Canary's own JSON error with `X-Canary-RequestId`, not the host application's `404` page: it does not hide that Canary is installed under the prefix, which the guard's other refusals and Canary's own endpoint responses show anyway.

**Failed attempts count toward the rate limit.** The rate limit (check 4) runs before the token check, so every request that gets past the allowlist uses up one request from the limit for its endpoint group and IP, whether its token is right or wrong. Repeated wrong tokens from one IP end in `429`, and after that the same IP is limited for every token, including correct ones, until the window frees up.

**Why the allowlist comes before the rate limit.** Requests from IPs outside the allowlist are rejected before the rate limiter runs, so they never create or update rate-limit state (with the default `FileBasedRateLimiter`, a file per bucket). Blocked IPs cannot reach the token check whatever their request rate.

**The `ADMIN` exception.** Scopes are matched exactly, with one exception: the `ADMIN` token satisfies every scope (`TokenScope::satisfies()`). Check 5 compares the presented token against every configured token (constant-time, over SHA-256 digests, without stopping at the first match) and only then picks the result.

### Audit coverage

One audit record is written for every main request that passes check 1:

- Rejected requests are recorded when the guard rejects them, with the guard's status code. A `429` record has `scope: null`, because the token has not been checked yet.
- Requests that pass every check are recorded on `kernel.response`, with the status code of the response actually sent: the controller's status, or the error response's status if the controller threw.

Not audited:

- `404`s from check 1: paths that match no endpoint group and paths in a disabled group.
- Sub-requests. The guard only inspects main requests; a sub-request is neither checked nor recorded.
- A passed request for which the kernel never dispatches `kernel.response`, for example after a fatal PHP error.

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

Requests are written to the `canary_audit` Monolog channel; see [Audit coverage](#audit-coverage) for exactly which. The plugin's default handler writes to `var/log/canary_audit.log`. Each entry includes:

- Timestamp
- Client IP
- Scope that authenticated the request (`null` for rejected requests)
- Endpoint path and HTTP method
- Response status code (for passed requests, the status the controller returned)
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
