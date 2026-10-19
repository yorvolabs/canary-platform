# Getting Started

## Requirements

- PHP 8.2+
- Shopware 6.6+ (for the Shopware bridge)
- Writable directories: `var/log/` and `var/canary/rate-limit/`

## Installation

### Shopware plugin

```bash
composer require yorvolabs/canary-bridge-shopware
bin/console plugin:install --activate YorvoLabsCanary
bin/console cache:clear
```

The plugin ships the core library (`yorvolabs/canary`) as a dependency; you do not install it separately.

### Standalone (Symfony application)

```bash
composer require yorvolabs/canary
```

Register `RequestGuardSubscriber` as a kernel event subscriber and wire the controllers into your routing. See [Extending: New Bridge](extending/new-bridge.md) for the full wiring guide.

## Generating tokens

Each API scope requires its own token. Generate them with the built-in CLI command:

```bash
bin/console canary:token:generate monitoring
bin/console canary:token:generate cache
bin/console canary:token:generate diagnostics
bin/console canary:token:generate deploy
bin/console canary:token:generate admin      # supertoken — grants all scopes
```

The command prints a cryptographically random 64-character hex token. Copy each one before closing the terminal — they are not stored.

## Configuring tokens

You can provide tokens via environment variables or via the Shopware admin UI. Environment variables take lower precedence than the admin UI config (admin UI values overlay env vars).

**Environment variables:**

```dotenv
CANARY_TOKEN_MONITORING=<64-char hex>
CANARY_TOKEN_CACHE=<64-char hex>
CANARY_TOKEN_DIAGNOSTICS=<64-char hex>
CANARY_TOKEN_DEPLOY=<64-char hex>
CANARY_TOKEN_ADMIN=<64-char hex>   # optional supertoken
```

**Admin UI:** Settings → YorvoLabs Canary → Authentication Tokens.

After setting env vars, run `bin/console cache:clear`.

## First request

Test the liveness endpoint (requires the monitoring token):

```bash
curl -s \
  -H "Authorization: Bearer $CANARY_TOKEN_MONITORING" \
  https://your-shop.com/canary/health/live | jq .
```

Expected response:

```json
{
  "ok": true,
  "data": { "status": "alive" },
  "meta": { "request_id": "canary-550e8400...", "duration_ms": 1 }
}
```

If you receive `503 Service Unavailable` with `"code": "SERVICE_UNAVAILABLE"`, the token for that scope is not configured yet.

If you receive `401 Unauthorized`, the token is wrong or missing.

## Verifying the installation

```bash
# Liveness (requires MONITORING token)
curl -H "Authorization: Bearer $TOKEN" https://your-shop.com/canary/health/live

# Readiness with all health checks
curl -H "Authorization: Bearer $TOKEN" https://your-shop.com/canary/health/ready

# Business metrics for the last 24 hours
curl -H "Authorization: Bearer $TOKEN" https://your-shop.com/canary/health/metrics

# Prometheus scrape format
curl -H "Authorization: Bearer $TOKEN" \
     -H "Accept: text/plain" \
     https://your-shop.com/canary/health/metrics
```

## Next steps

- Read [Authentication](authentication.md) to understand token scopes and IP allowlisting.
- Read [API Reference](api-reference.md) for all endpoints and their full request/response shapes.
- Read [Configuration](configuration.md) for all available settings.
- Read [Deployment Checklist](deployment/checklist.md) before going to production.
