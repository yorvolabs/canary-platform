# YorvoLabs Canary — Documentation

Canary is a set of secure, auditable HTTP endpoints for health monitoring, cache management, and deployment automation in Shopware 6.6+ (and any Symfony 7.4+ application via the core library).

## Packages

| Package | Composer name | Purpose |
|---|---|---|
| **Core** | `yorvolabs/canary` | Framework-agnostic library: auth, health, deploy, diagnostics contracts, rate limiting |
| **Shopware bridge** | `yorvolabs/canary-bridge-shopware` | Shopware 6.6+ plugin wiring core into the Shopware DI container, routing, and admin UI |

## Documentation pages

| Page | What it covers |
|---|---|
| [Getting Started](getting-started.md) | Installation, first token, first request |
| [Authentication](authentication.md) | Token scopes, Bearer scheme, IP allowlist, HTTPS enforcement |
| [API Reference](api-reference.md) | Every endpoint: method, path, params, request/response shapes, status codes |
| [Configuration](configuration.md) | All environment variables and Shopware admin config fields |
| [Admin UI](admin-ui.md) | Shopware admin panel walkthrough |
| [Extending: Health Checks](extending/health-checks.md) | Adding custom health checks |
| [Extending: Deploy Hooks](extending/deploy-hooks.md) | Adding custom pre/post-deploy hooks |
| [Extending: Diagnostic Checks](extending/diagnostic-checks.md) | Adding custom diagnostic checks |
| [Extending: New Bridge](extending/new-bridge.md) | Wiring the core library into a non-Shopware framework |
| [Deployment Checklist](deployment/checklist.md) | Pre-production checklist |
| [CI/CD Integration](deployment/ci-cd.md) | Automating pre/post-deploy calls in pipelines |

## Quick reference

```
GET  /{prefix}/health/live          → liveness probe (no token for monitoring scope)
GET  /{prefix}/health/ready         → readiness (all health checks)
GET  /{prefix}/health/metrics       → Prometheus or JSON metrics

GET  /{prefix}/cache/opcache        → OPcache snapshot
POST /{prefix}/cache/opcache/reset  → reset OPcache
POST /{prefix}/cache/shopware       → clear all Shopware cache pools

GET  /{prefix}/deploy/pre           → pre-deploy readiness check
POST /{prefix}/deploy/post          → post-deploy verification
GET|PUT|DELETE /{prefix}/deploy/maintenance → maintenance mode

GET  /{prefix}/diag/env             → PHP + Shopware runtime info
GET  /{prefix}/diag/config          → Shopware config snapshot
GET  /{prefix}/diag/logs            → tailed application logs (PII-masked)
GET  /{prefix}/diag/queue           → queue depth + failed messages
GET  /{prefix}/diag/plugins         → installed plugin list
```

Default prefix is `canary` (configurable via `CANARY_ROUTE_PREFIX`).
