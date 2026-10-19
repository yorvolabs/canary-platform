# API Reference

All endpoints share a configurable URL prefix (default: `canary`, set via `CANARY_ROUTE_PREFIX`). Examples below use the default.

## Standard envelopes

### Success

```json
{
  "ok": true,
  "data": { ... },
  "meta": {
    "request_id": "canary-550e8400-e29b-41d4-a716-446655440000",
    "duration_ms": 12
  }
}
```

### Error

```json
{
  "ok": false,
  "error": {
    "code": "UNAUTHORIZED",
    "message": "Missing or invalid Authorization header",
    "request_id": "canary-...",
    "elapsed_ms": 1
  }
}
```

### Error codes

| HTTP | Code | Meaning |
|---|---|---|
| 400 | `BAD_REQUEST` | Invalid input (e.g. missing `confirm=true`) |
| 401 | `UNAUTHORIZED` | Token missing or malformed |
| 403 | `FORBIDDEN` | Token wrong, scope mismatch, IP not in allowlist, or HTTP in production |
| 404 | `NOT_FOUND` | Endpoint group disabled via `CANARY_FEATURES` |
| 429 | `RATE_LIMITED` | Rate limit exceeded; `Retry-After: N` header present |
| 503 | `SERVICE_UNAVAILABLE` | Token for scope not configured |

---

## Health endpoints

**Required scope:** `MONITORING`

### `GET /canary/health/live`

Liveness probe. Confirms the PHP process is running. Does **not** execute health checks against dependencies.

**Response 200:**

```json
{
  "ok": true,
  "data": { "status": "alive" },
  "meta": { "request_id": "...", "duration_ms": 1 }
}
```

---

### `GET /canary/health/ready`

Readiness probe. Runs all registered health checks and aggregates results.

**Response 200 (PASS or WARN):**

```json
{
  "status": "pass",
  "checks": [
    {
      "name": "database",
      "status": "pass",
      "duration_ms": 4,
      "detail": {},
      "error": null
    },
    {
      "name": "cache_adapter",
      "status": "pass",
      "duration_ms": 1,
      "detail": {},
      "error": null
    }
  ],
  "request_id": "canary-...",
  "elapsed_ms": 12
}
```

**Response 503 (FAIL):** Same shape, `"status": "fail"`, at least one check has `"status": "fail"`.

Status values: `pass`, `warn`, `fail`.

The response `Content-Type` is `application/health+json` (RFC 8908).

**Kubernetes probe example:**

```yaml
livenessProbe:
  httpGet:
    path: /canary/health/live
    httpHeaders:
      - name: Authorization
        value: Bearer $(CANARY_TOKEN_MONITORING)
readinessProbe:
  httpGet:
    path: /canary/health/ready
    httpHeaders:
      - name: Authorization
        value: Bearer $(CANARY_TOKEN_MONITORING)
  failureThreshold: 3
  periodSeconds: 10
```

---

### `GET /canary/health/metrics`

Infrastructure metrics (OPcache, PHP memory, health check durations) plus Shopware business metrics.

**Query parameters:**

| Param | Default | Max | Description |
|---|---|---|---|
| `hours` | `24` | `744` | Look-back window for business metrics (hours) |

**Accept: application/json** (default) — wrapped envelope:

```json
{
  "ok": true,
  "data": {
    "metrics": {
      "opcache_enabled":              { "value": 1,     "help": "...", "type": "gauge" },
      "opcache_hit_rate":             { "value": 98.4,  "help": "...", "type": "gauge" },
      "opcache_memory_used_bytes":    { "value": 45678, "help": "...", "type": "gauge" },
      "opcache_memory_free_bytes":    { "value": 98765, "help": "...", "type": "gauge" },
      "opcache_cached_scripts":       { "value": 312,   "help": "...", "type": "gauge" },
      "php_memory_usage_bytes":       { "value": 31457, "help": "...", "type": "gauge" },
      "health_check_database_ms":     { "value": 4,     "help": "...", "type": "gauge" },
      "shop_orders_count":            { "value": 42,    "help": "Orders created in the last 24 hour(s)", "type": "gauge" },
      "shop_customers_count":         { "value": 8,     "help": "Customer registrations in the last 24 hour(s)", "type": "gauge" },
      "shop_revenue_total":           { "value": 3812.5,"help": "Total order revenue ... in the last 24 hour(s)", "type": "gauge" },
      "shop_carts_active":            { "value": 3,     "help": "Carts started or updated in the last 24 hour(s)", "type": "gauge" },
      "shop_queue_depth":             { "value": 0,     "help": "Pending messages in the async queue", "type": "gauge" },
      "shop_queue_failed":            { "value": 0,     "help": "Failed messages in the dead-letter queue", "type": "gauge" },
      "shop_newsletter_subscribers":  { "value": 6,     "help": "New newsletter sign-ups ... in the last 24 hour(s)", "type": "gauge" },
      "shop_products_count":          { "value": 240,   "help": "Total main products (excluding variants)", "type": "gauge" }
    }
  },
  "meta": { "request_id": "...", "duration_ms": 18 }
}
```

**Accept: text/plain** — Prometheus exposition format (version 0.0.4):

```
# HELP opcache_enabled 1 if OPcache is enabled
# TYPE opcache_enabled gauge
opcache_enabled 1
# HELP shop_orders_count Orders created in the last 24 hour(s)
# TYPE shop_orders_count gauge
shop_orders_count 42
...
```

**Prometheus scrape config example:**

```yaml
- job_name: shopware_canary
  bearer_token: <CANARY_TOKEN_MONITORING>
  static_configs:
    - targets: [your-shop.com]
  metrics_path: /canary/health/metrics
  params:
    hours: ["1"]
```

---

## Cache endpoints

**Required scope:** `CACHE`

### `GET /canary/cache/opcache`

Returns a snapshot of the current OPcache state. Does not modify anything.

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "enabled": true,
    "cache_full": false,
    "restart_pending": false,
    "memory": {
      "used_bytes": 45678901,
      "free_bytes": 102345678,
      "wasted_bytes": 0,
      "wasted_percent": 0.0
    },
    "statistics": {
      "cached_scripts": 312,
      "hits": 1234567,
      "misses": 890,
      "hit_rate": 99.93
    }
  },
  "meta": { "request_id": "...", "duration_ms": 1 }
}
```

---

### `POST /canary/cache/opcache/reset?confirm=true`

Clears the OPcache script cache. The `confirm=true` query parameter is required to prevent accidental resets.

**Query parameters:**

| Param | Required | Value |
|---|---|---|
| `confirm` | Yes | `true` |

**Response 200:**

```json
{
  "ok": true,
  "data": { "reset": true },
  "meta": { "request_id": "...", "duration_ms": 5 }
}
```

**Response 400** if `confirm=true` is missing.

---

### `POST /canary/cache/shopware`

Clears all Shopware cache pools (object cache + HTTP cache).

**Response 200:**

```json
{
  "ok": true,
  "data": { "cleared": ["object", "http"] },
  "meta": { "request_id": "...", "duration_ms": 120 }
}
```

---

### `POST /canary/cache/shopware/{pool}`

Clears a single named cache pool.

**Path parameters:**

| Param | Values |
|---|---|
| `pool` | `object`, `http` |

**Response 200:**

```json
{
  "ok": true,
  "data": { "cleared": ["object"] },
  "meta": { "request_id": "...", "duration_ms": 80 }
}
```

---

## Deploy endpoints

**Required scope:** `DEPLOY`

### `GET /canary/deploy/pre`

Runs pre-deploy readiness checks. Use this in your CI pipeline **before** deploying. A `"pass": false` result means the shop is not ready to accept a deployment (e.g. queue is too deep, or maintenance mode is already active from another process).

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "pass": true,
    "checks": [
      { "name": "maintenance_not_active", "pass": true, "detail": { "enabled": false } },
      { "name": "queue_depth",            "pass": true, "detail": { "depth": 3, "threshold": 100 } },
      { "name": "failed_messages",        "pass": true, "detail": { "count": 0, "threshold": 0 } }
    ]
  },
  "meta": { "request_id": "...", "duration_ms": 8 }
}
```

If any registered pre-deploy hook fails, its result appears in `checks` with `"pass": false` and the top-level `"pass"` becomes `false`.

---

### `POST /canary/deploy/post`

Runs post-deploy steps. Use this in your CI pipeline **after** deploying. Executes in order: OPcache reset, Shopware cache clear, readiness check, then any registered post-deploy hooks.

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "pass": true,
    "steps": [
      { "name": "opcache_reset",    "pass": true, "duration_ms": 5,  "detail": {} },
      { "name": "cache_clear",      "pass": true, "duration_ms": 95, "detail": {} },
      { "name": "readiness_check",  "pass": true, "duration_ms": 12, "detail": { "status": "pass" } }
    ]
  },
  "meta": { "request_id": "...", "duration_ms": 115 }
}
```

---

### `GET /canary/deploy/maintenance`

Returns the current maintenance mode state.

**Response 200:**

```json
{
  "ok": true,
  "data": { "enabled": false, "reason": null },
  "meta": { "request_id": "...", "duration_ms": 2 }
}
```

---

### `PUT /canary/deploy/maintenance`

Enables maintenance mode.

**Request body** (optional JSON):

```json
{ "reason": "Scheduled database migration" }
```

**Response 200:**

```json
{
  "ok": true,
  "data": { "enabled": true, "reason": "Scheduled database migration" },
  "meta": { "request_id": "...", "duration_ms": 5 }
}
```

---

### `DELETE /canary/deploy/maintenance`

Disables maintenance mode.

**Response 200:**

```json
{
  "ok": true,
  "data": { "enabled": false, "reason": null },
  "meta": { "request_id": "...", "duration_ms": 4 }
}
```

---

## Diagnostics endpoints

**Required scope:** `DIAGNOSTICS`

### `GET /canary/diag/env`

Returns PHP runtime info, Shopware version, and sanitised environment variables. Sensitive values (`TOKEN`, `PASSWORD`, `SECRET`, `KEY`, `DSN`, etc.) are replaced with `[REDACTED]`.

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "php": {
      "version": "8.4.0",
      "memory_limit": "256M",
      "max_execution_time": 30,
      "extensions": ["opcache", "mbstring", "pdo_mysql", "..."]
    },
    "shopware": {
      "version": "6.6.3.0",
      "app_env": "prod"
    },
    "env": {
      "APP_ENV": "prod",
      "DATABASE_URL": "[REDACTED]",
      "CANARY_TOKEN_MONITORING": "[REDACTED]"
    }
  },
  "meta": { "request_id": "...", "duration_ms": 3 }
}
```

---

### `GET /canary/diag/config`

Returns a Shopware system config snapshot for a given namespace, plus built-in validation checks.

**Query parameters:**

| Param | Default | Description |
|---|---|---|
| `namespace` | `YorvoLabsCanary` | Config key namespace prefix to snapshot |

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "namespace": "YorvoLabsCanary",
    "snapshot": {
      "YorvoLabsCanary.config.tokenMonitoring": "[REDACTED]",
      "YorvoLabsCanary.config.allowedCidrs": "10.0.0.0/8"
    },
    "validation": [
      { "name": "app_env",               "pass": true,  "message": "APP_ENV is 'prod'" },
      { "name": "queue_transport",       "pass": true,  "message": "MESSENGER_TRANSPORT_DSN is set" },
      { "name": "mailer_transport",      "pass": false, "message": "MAILER_DSN is not set" }
    ]
  },
  "meta": { "request_id": "...", "duration_ms": 5 }
}
```

---

### `GET /canary/diag/logs`

Tails the most recent application log file. Email addresses and IPv4 addresses in log entries are masked to `[EMAIL]` and `[IP]`.

**Query parameters:**

| Param | Default | Range | Description |
|---|---|---|---|
| `lines` | `50` | 1–500 | Number of log entries to return |
| `level` | _(all)_ | `error`, `critical`, `warning`, `notice`, `info`, `debug` | Filter by severity |

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "file": "shopware-2025-05-07.log",
    "format": "json",
    "lines_returned": 10,
    "entries": [
      {
        "message": "Order placed by [EMAIL]",
        "level": "info",
        "level_name": "INFO",
        "datetime": "2025-05-07T12:34:56+00:00",
        "context": {}
      }
    ]
  },
  "meta": { "request_id": "...", "duration_ms": 8 }
}
```

`format` is either `json` (Monolog structured) or `text` (plain log lines).

---

### `GET /canary/diag/queue`

Returns queue depth, failed message count, and worker detection status.

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "queues": [
      {
        "name": "async",
        "depth": 3,
        "oldest_age_seconds": 45
      }
    ],
    "failed": {
      "count": 0,
      "oldest_failed_at": null
    },
    "workers": {
      "detected": true,
      "note": "Process detection via /proc (Linux only)"
    }
  },
  "meta": { "request_id": "...", "duration_ms": 6 }
}
```

---

### `GET /canary/diag/plugins`

Lists all installed Shopware plugins.

**Response 200:**

```json
{
  "ok": true,
  "data": {
    "plugins": [
      {
        "name": "YorvoLabsCanary",
        "label": "YorvoLabs Canary",
        "version": "1.0.0",
        "active": true,
        "installed_at": "2025-05-01T10:00:00+00:00",
        "upgrade_version": null
      }
    ]
  },
  "meta": { "request_id": "...", "duration_ms": 4 }
}
```

---

## Admin UI proxy endpoints

These endpoints are only accessible from inside the Shopware admin panel. They are protected by Shopware's own JWT authentication (`canary:viewer` ACL privilege) rather than a Canary bearer token.

| Method | Path | Description |
|---|---|---|
| `GET` | `/api/_action/canary/diag/env` | Proxy to `GET /diag/env` |
| `GET` | `/api/_action/canary/diag/config` | Proxy to `GET /diag/config` |
| `GET` | `/api/_action/canary/diag/logs` | Proxy to `GET /diag/logs` |
| `GET` | `/api/_action/canary/diag/queue` | Proxy to `GET /diag/queue` |
| `GET` | `/api/_action/canary/diag/plugins` | Proxy to `GET /diag/plugins` |
| `GET` | `/api/_action/canary/cache/opcache` | Proxy to `GET /cache/opcache` |
| `POST` | `/api/_action/canary/cache/opcache/reset` | Proxy to `POST /cache/opcache/reset` |
| `POST` | `/api/_action/canary/cache/shopware` | Proxy to `POST /cache/shopware` |
| `GET` | `/api/_action/canary/info` | Token status + config summary |
| `GET` | `/api/_action/canary/deploy-hooks` | List registered hooks |
| `GET` | `/api/_action/canary/rate-limit-stats` | Current rate limit usage |
| `GET` | `/api/_action/canary/audit` | Tail audit log (`?lines=N`, max 500) |

The admin proxy calls the underlying action methods directly (no HTTP round-trip), so the Canary bearer tokens are **not** required for admin UI access.
