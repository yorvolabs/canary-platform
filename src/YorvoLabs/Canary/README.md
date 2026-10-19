# yorvolabs/canary

[![CI](https://github.com/yorvolabs/canary/actions/workflows/ci.yml/badge.svg)](https://github.com/yorvolabs/canary/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/yorvolabs/canary)](https://packagist.org/packages/yorvolabs/canary)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Secure, auditable HTTP endpoints for health monitoring, cache management, and deployment automation in Symfony-based applications.

## Features

- **Health probes** — liveness and readiness endpoints suitable for Kubernetes / load balancer checks
- **Prometheus metrics** — business KPIs and infrastructure metrics via `Accept: text/plain` content negotiation
- **Cache management** — OPcache reset and application cache clear via authenticated API calls
- **Deploy lifecycle** — pre-deploy readiness checks, post-deploy verification, maintenance mode toggle
- **Diagnostics** — environment snapshot, config validation, log tail (PII-masked), queue depth, plugin list
- **Token-scoped auth** — isolated Bearer tokens per endpoint group; constant-time comparison; IP allowlist
- **Rate limiting** — sliding-window per-IP limiter (file-based or bring your own Redis implementation)
- **Audit log** — every request (pass and fail) recorded with IP, scope, endpoint, status, duration
- **Extensible** — tagged-service interfaces for health checks, deploy hooks, diagnostic checks, config providers, audit loggers

## Requirements

- PHP 8.2+
- Symfony 7.4+ (`http-foundation`, `http-kernel`, `event-dispatcher`)

## Installation

```bash
composer require yorvolabs/canary
```

For Shopware 6.6+, use the bridge package instead:

```bash
composer require yorvolabs/canary-bridge-shopware
```

## Quick start

1. **Generate a token**

```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

2. **Set the environment variable**

```dotenv
CANARY_TOKEN_MONITORING=<your-64-char-token>
```

3. **Wire the event subscriber** (in your Symfony DI config)

```yaml
YorvoLabs\Canary\Auth\RequestGuardSubscriber:
    tags: [kernel.event_subscriber]
```

4. **Make a request**

```bash
curl -H "Authorization: Bearer $CANARY_TOKEN_MONITORING" \
     https://your-app.com/canary/health/live
```

```json
{"ok": true, "data": {"status": "alive"}, "meta": {"request_id": "canary-...", "duration_ms": 1}}
```

## Documentation

Full documentation including all endpoints, configuration options, and extension guides:
[github.com/yorvolabs/canary/tree/main/docs](https://github.com/yorvolabs/canary/tree/main/docs)

| Page | Description |
|---|---|
| [Getting Started](docs/getting-started.md) | Install, configure, first request |
| [API Reference](docs/api-reference.md) | All endpoints, request/response shapes |
| [Authentication](docs/authentication.md) | Token scopes, IP allowlist, security model |
| [Configuration](docs/configuration.md) | All environment variables |
| [Extending: Health Checks](docs/extending/health-checks.md) | Custom health checks |
| [Extending: Deploy Hooks](docs/extending/deploy-hooks.md) | Custom pre/post-deploy hooks |
| [Extending: New Bridge](docs/extending/new-bridge.md) | Wire into a non-Shopware framework |
| [Deployment Checklist](docs/deployment/checklist.md) | Pre-production checklist |
| [CI/CD Integration](docs/deployment/ci-cd.md) | GitHub Actions, GitLab CI examples |

## License

MIT — see [LICENSE](LICENSE).
