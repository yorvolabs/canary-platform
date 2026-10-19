# yorvolabs/canary-bridge-shopware

[![CI](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/yorvolabs/canary-bridge-shopware)](https://packagist.org/packages/yorvolabs/canary-bridge-shopware)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Shopware 6.6+ plugin that wires [yorvolabs/canary](https://github.com/yorvolabs/canary) into Shopware — providing secure, auditable HTTP endpoints for health monitoring, cache management, and deployment automation, plus a full admin UI dashboard.

## Features

- **Health probes** (`/canary/health/live`, `/canary/health/ready`) — Kubernetes/load balancer ready
- **Prometheus metrics** (`/canary/health/metrics`) — OPcache, PHP memory, business KPIs (orders, revenue, customers, carts, newsletter, queue)
- **Cache management** — OPcache reset and Shopware cache pool clearing via API
- **Deploy lifecycle** — pre-deploy readiness checks, post-deploy verification (OPcache + cache clear + health check), maintenance mode toggle
- **Diagnostics** — PHP/Shopware runtime info, config validation, log tail (PII-masked), queue depth, plugin list
- **Admin UI** — full Vue.js dashboard in Shopware Administration (Settings → YorvoLabs Canary) with live health status, Commerce Pulse metrics, log viewer, and tools
- **Extensible** — add custom health checks, deploy hooks, and diagnostic checks via Symfony service tags

## Requirements

- PHP 8.2+
- Shopware 6.6+

## Installation

```bash
composer require yorvolabs/canary-bridge-shopware
bin/console plugin:install --activate YorvoLabsCanary
bin/console cache:clear
```

## Quick start

**1. Generate tokens** for each scope you need:

```bash
bin/console canary:token:generate monitoring
bin/console canary:token:generate cache
bin/console canary:token:generate deploy
```

**2. Configure** via environment variables or the admin panel (**Settings → YorvoLabs Canary**):

```dotenv
CANARY_TOKEN_MONITORING=<your-64-char-token>
CANARY_TOKEN_CACHE=<your-64-char-token>
CANARY_TOKEN_DEPLOY=<your-64-char-token>
```

**3. Verify:**

```bash
curl -H "Authorization: Bearer $CANARY_TOKEN_MONITORING" \
     https://your-shop.com/canary/health/live
```

```json
{"ok": true, "data": {"status": "alive"}, "meta": {"request_id": "canary-...", "duration_ms": 1}}
```

## Default endpoints

All endpoints use a configurable prefix (`CANARY_ROUTE_PREFIX`, default: `canary`):

| Method | Path | Scope | Description |
|---|---|---|---|
| GET | `/canary/health/live` | MONITORING | Liveness probe |
| GET | `/canary/health/ready` | MONITORING | Readiness probe (all health checks) |
| GET | `/canary/health/metrics` | MONITORING | Prometheus/JSON metrics |
| GET | `/canary/cache/opcache` | CACHE | OPcache status |
| POST | `/canary/cache/opcache/reset?confirm=true` | CACHE | Reset OPcache |
| POST | `/canary/cache/shopware` | CACHE | Clear all Shopware cache pools |
| GET | `/canary/deploy/pre` | DEPLOY | Pre-deploy readiness check |
| POST | `/canary/deploy/post` | DEPLOY | Post-deploy verification |
| PUT/DELETE | `/canary/deploy/maintenance` | DEPLOY | Toggle maintenance mode |
| GET | `/canary/diag/env` | DIAGNOSTICS | PHP/Shopware runtime info |
| GET | `/canary/diag/logs` | DIAGNOSTICS | Tailed application logs |
| GET | `/canary/diag/queue` | DIAGNOSTICS | Queue depth + failed messages |

## Documentation

Full documentation:

| Page | Description |
|---|---|
| [Getting Started](docs/getting-started.md) | Install, configure, first request |
| [API Reference](docs/api-reference.md) | All endpoints, full request/response shapes |
| [Authentication](docs/authentication.md) | Token scopes, IP allowlist, security model |
| [Configuration](docs/configuration.md) | All environment variables and admin config fields |
| [Admin UI](docs/admin-ui.md) | Admin panel walkthrough |
| [Extending: Health Checks](docs/extending/health-checks.md) | Custom health checks |
| [Extending: Deploy Hooks](docs/extending/deploy-hooks.md) | Custom pre/post-deploy hooks |
| [Deployment Checklist](docs/deployment/checklist.md) | Pre-production checklist |
| [CI/CD Integration](docs/deployment/ci-cd.md) | GitHub Actions, GitLab CI, Kubernetes examples |

## License

MIT — see [LICENSE](LICENSE).
