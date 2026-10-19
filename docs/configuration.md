# Configuration

Configuration can be provided via environment variables or, in the Shopware bridge, via the admin UI at **Settings → YorvoLabs Canary**. Admin UI values take precedence over environment variables; leave an admin field empty to use the environment variable (or the built-in default if that is unset too).

After changing any environment variable, run `bin/console cache:clear`.

---

## Tokens

| Env var | Admin config key | Description |
|---|---|---|
| `CANARY_TOKEN_MONITORING` | `tokenMonitoring` | Token for `/health/*` endpoints |
| `CANARY_TOKEN_CACHE` | `tokenCache` | Token for `/cache/*` endpoints |
| `CANARY_TOKEN_DIAGNOSTICS` | `tokenDiagnostics` | Token for `/diag/*` endpoints |
| `CANARY_TOKEN_DEPLOY` | `tokenDeploy` | Token for `/deploy/*` endpoints |
| `CANARY_TOKEN_ADMIN` | `tokenAdmin` | Supertoken — satisfies all scope checks |

All tokens must be at least 64 characters (32 bytes of hex entropy). Generate with:

```bash
bin/console canary:token:generate <scope>
```

Only the scopes you need have to be configured. Calling an endpoint whose token is not set returns `503 Service Unavailable`.

---

## Access control

| Env var | Admin config key | Default | Description |
|---|---|---|---|
| `CANARY_ALLOWED_CIDRS` | `allowedCidrs` | _(empty — allow all)_ | Comma-separated CIDR ranges allowed to call the API |

Examples:

```dotenv
# Single IP
CANARY_ALLOWED_CIDRS=203.0.113.42/32

# Private network + monitoring SaaS
CANARY_ALLOWED_CIDRS=10.0.0.0/8,172.16.0.0/12,198.51.100.0/24,::1/128
```

An empty value disables IP filtering. In production, setting this is strongly recommended.

---

## Rate limiting

| Env var | Admin config key | Default | Description |
|---|---|---|---|
| `CANARY_RATE_LIMIT_HEALTH` | `rateLimitHealth` | `60` | Max requests per minute for `/health/*` per IP |
| `CANARY_RATE_LIMIT_MUTATION` | `rateLimitMutation` | `10` | Max requests per minute for `/cache/*`, `/diag/*`, `/deploy/*` per IP |

When a limit is exceeded, the response is `429 Too Many Requests` with a `Retry-After: <seconds>` header.

The default rate limiter uses JSON files in `var/canary/rate-limit/` (requires write access). For high-traffic deployments, implement a custom `RateLimiterInterface` backed by Redis. See [Extending: New Bridge](extending/new-bridge.md#custom-rate-limiter).

---

## Deploy thresholds

These control when the `GET /deploy/pre` check fails.

| Env var | Admin config key | Default | Description |
|---|---|---|---|
| `CANARY_DEPLOY_QUEUE_THRESHOLD` | `queueThreshold` | `100` | Max queue depth allowed before deployment |
| `CANARY_DEPLOY_FAILED_THRESHOLD` | `failedThreshold` | `0` | Max failed messages allowed before deployment |

---

## Routing

| Env var | Admin config key | Default | Description |
|---|---|---|---|
| `CANARY_ROUTE_PREFIX` | — (env only) | `canary` | URL prefix for all Canary endpoints |

Examples:

| Value | Liveness URL |
|---|---|
| `canary` (default) | `/canary/health/live` |
| `ops` | `/ops/health/live` |
| _(empty string)_ | `/canary/health/live` (falls back to the default) |

Changing the prefix requires `bin/console cache:clear` to regenerate the route table. The Shopware bridge also bakes the prefix into the compiled container (so the storefront's sales-channel lookup skips Canary paths); until the cache is cleared, requests on the new prefix are answered by the storefront instead of Canary.

---

## Feature flags

| Env var | Default | Description |
|---|---|---|
| `CANARY_FEATURES` | _(empty — all enabled)_ | Comma-separated list of enabled endpoint groups |

Valid group names: `health`, `cache`, `deploy`, `diagnostics`.

Disabled groups return `404 Not Found` (before auth checks, to avoid leaking endpoint existence).

Examples:

```dotenv
# Enable only health and diagnostics; disable cache and deploy
CANARY_FEATURES=health,diagnostics

# Enable all (default when empty)
CANARY_FEATURES=
```

---

## Maintenance mode

| Admin config key | Description |
|---|---|
| `defaultMaintenanceReason` | Default reason text shown when maintenance is enabled without an explicit reason |

Maintenance mode state is stored in Shopware's `system_config` table and persists across deploys.

---

## Filesystem requirements

| Path | Purpose | Required by |
|---|---|---|
| `var/canary/rate-limit/` | Rate limit sliding-window files | `FileBasedRateLimiter` |
| `var/log/canary_audit-*.log` | Audit log | `MonologAuditLogger` |

Both paths are relative to the Shopware project root. The PHP process must have read/write access to both.

Log rotation for the audit channel is handled by Monolog's `RotatingFileHandler` (default: 30-day retention). Standard OS log rotation (`logrotate`) will also work if configured.
