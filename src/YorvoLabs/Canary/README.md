# yorvolabs/canary

[![CI](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/yorvolabs/canary)](https://packagist.org/packages/yorvolabs/canary)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Secure, auditable HTTP endpoints for health monitoring, cache management, and deployment automation in Symfony-based applications.

This package is the framework-agnostic core: the request guard (token scopes, IP allowlist, rate limit, audit), the health/deploy/diagnostics runners and the response factory. It ships no controllers or routes. For Shopware 6.6+, the bridge package below provides the endpoints; in another Symfony application you write thin controllers yourself (see [Extending: New Bridge](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/new-bridge.md)).

## Features

- **Health probes** — liveness and readiness endpoints suitable for Kubernetes / load balancer checks
- **Prometheus metrics** — business KPIs and infrastructure metrics via `Accept: text/plain` content negotiation
- **Cache management** — OPcache reset and application cache clear via authenticated API calls
- **Deploy lifecycle** — pre-deploy readiness checks, post-deploy verification, maintenance mode toggle
- **Diagnostics** — environment snapshot, config validation, log tail (PII-masked), queue depth, plugin list
- **Token-scoped auth** — isolated Bearer tokens per endpoint group; constant-time comparison; IP allowlist
- **Rate limiting** — sliding-window per-IP limiter (file-based or bring your own Redis implementation)
- **Audit log** — passed and rejected requests recorded with IP, scope, endpoint, status, duration (404s for unknown or disabled paths and sub-requests are not; see the authentication docs)
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

4. **Route a controller** under the prefix, for example `/canary/health/live` returning `ResponseFactory::ok(['status' => 'alive'])` (the Shopware bridge ships these controllers; for your own, see [Extending: New Bridge](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/new-bridge.md))

5. **Make a request**

```bash
curl -H "Authorization: Bearer $CANARY_TOKEN_MONITORING" \
     https://your-app.com/canary/health/live
```

```json
{"ok": true, "data": {"status": "alive"}, "meta": {"request_id": "canary-...", "duration_ms": 1}}
```

## Documentation

Full documentation including all endpoints, configuration options, and extension guides:
[github.com/yorvolabs/canary-platform/tree/main/docs](https://github.com/yorvolabs/canary-platform/tree/main/docs)

| Page | Description |
|---|---|
| [Getting Started](https://github.com/yorvolabs/canary-platform/blob/main/docs/getting-started.md) | Install, configure, first request |
| [API Reference](https://github.com/yorvolabs/canary-platform/blob/main/docs/api-reference.md) | All endpoints, request/response shapes |
| [Authentication](https://github.com/yorvolabs/canary-platform/blob/main/docs/authentication.md) | Token scopes, IP allowlist, security model |
| [Configuration](https://github.com/yorvolabs/canary-platform/blob/main/docs/configuration.md) | All environment variables |
| [Extending: Health Checks](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/health-checks.md) | Custom health checks |
| [Extending: Deploy Hooks](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/deploy-hooks.md) | Custom pre/post-deploy hooks |
| [Extending: Diagnostic Checks](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/diagnostic-checks.md) | Custom diagnostic checks |
| [Extending: New Bridge](https://github.com/yorvolabs/canary-platform/blob/main/docs/extending/new-bridge.md) | Wire into a non-Shopware framework |
| [Deployment Checklist](https://github.com/yorvolabs/canary-platform/blob/main/docs/deployment/checklist.md) | Pre-production checklist |
| [CI/CD Integration](https://github.com/yorvolabs/canary-platform/blob/main/docs/deployment/ci-cd.md) | GitHub Actions, GitLab CI examples |

## License

MIT — see [LICENSE](LICENSE).
