# Extending: Writing a New Bridge

A "bridge" is a thin integration layer that wires the `yorvolabs/canary` core library into a specific framework's DI container, routing system, and service conventions. The Shopware bridge (`yorvolabs/canary-bridge-shopware`) is the reference implementation. This page explains how to write a bridge for a different framework (Laravel, Slim, vanilla Symfony, etc.).

## What the core library provides

`yorvolabs/canary` (the core) contains:

| Component | Class | Role |
|---|---|---|
| Auth guard | `RequestGuardSubscriber` | Symfony kernel event subscriber; enforces tokens, IP allowlist, HTTPS, rate limits |
| Token authenticator | `TokenAuthenticator` | Token validation and scope checking |
| IP allowlist | `IpAllowlistChecker` | CIDR-based IP filtering |
| Rate limiter | `RateLimiterInterface` + `FileBasedRateLimiter` | Sliding-window per-IP rate limiting |
| Health runner | `HealthCheckRunner` | Aggregates `HealthCheckInterface` implementations |
| Deploy runner | `DeployHookRunner` | Aggregates `DeployHookInterface` implementations |
| Diagnostics runner | `DiagnosticsRunner` | Aggregates `DiagnosticsInterface` implementations |
| Response factory | `ResponseFactory` | Produces standardised JSON envelopes and error responses |
| Prometheus formatter | `PrometheusFormatter` | Formats metrics in Prometheus exposition format |
| Configuration | `Options` | Immutable value object holding all runtime config |
| Sensitive masker | `SensitiveValueMasker` | Redacts secrets in strings |

The core does **not** contain any framework-specific controller base classes, routing annotations, or ORM code. It depends only on `symfony/http-foundation`, `symfony/http-kernel`, and `symfony/event-dispatcher`.

## Contracts to implement in your bridge

### Required

| Interface | Where used | Notes |
|---|---|---|
| `HealthCheckInterface` | `/health/ready`, `/health/metrics` | Implement for each dependency you want to check |
| `ConfigProviderInterface` | `Options` construction | Read config from your framework's config system |
| `AuditLoggerInterface` | `RequestGuardSubscriber` | Write audit records to your logging backend |

### Optional

| Interface | Default | When to replace |
|---|---|---|
| `RateLimiterInterface` | `FileBasedRateLimiter` | Replace with Redis when `var/` is not writable or you need cluster-safe rate limiting |

## Step-by-step: Symfony application bridge

### 1. Require the core library

```bash
composer require yorvolabs/canary
```

### 2. Implement `ConfigProviderInterface`

Read your application's config and return a flat key-value array. Return only keys that are set — empty strings are ignored.

```php
<?php

declare(strict_types=1);

namespace Acme\Canary;

use YorvoLabs\Canary\Contract\ConfigProviderInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

final class AppConfigProvider implements ConfigProviderInterface
{
    public function __construct(private readonly ParameterBagInterface $params) {}

    public function getConfig(): array
    {
        return array_filter([
            'CANARY_TOKEN_MONITORING'  => $this->params->get('canary.token.monitoring'),
            'CANARY_TOKEN_CACHE'       => $this->params->get('canary.token.cache'),
            'CANARY_TOKEN_DEPLOY'      => $this->params->get('canary.token.deploy'),
            'CANARY_TOKEN_DIAGNOSTICS' => $this->params->get('canary.token.diagnostics'),
            'CANARY_ALLOWED_CIDRS'     => $this->params->get('canary.allowed_cidrs'),
        ], static fn(mixed $v): bool => is_string($v) && $v !== '');
    }
}
```

### 3. Implement `AuditLoggerInterface`

```php
<?php

declare(strict_types=1);

namespace Acme\Canary;

use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use Psr\Log\LoggerInterface;

final class PsrAuditLogger implements AuditLoggerInterface
{
    public function __construct(private readonly LoggerInterface $logger) {}

    public function log(AuditRecord $record): void
    {
        $this->logger->info('canary_audit', [
            'ip'             => $record->ip,
            'scope'          => $record->scope?->value,
            'endpoint'       => $record->endpoint,
            'method'         => $record->method,
            'status'         => $record->statusCode,
            'duration_ms'    => $record->durationMs,
            'failure_reason' => $record->failureReason,
        ]);
    }
}
```

### 4. Build `Options`

`Options` is constructed from environment variables with optional overlay from your `ConfigProviderInterface`:

```php
use YorvoLabs\Canary\Configuration\Options;

// From env only
$options = Options::fromEnv();

// With config provider overlay (provider values take precedence)
$options = Options::fromProvider($configProvider);
```

### 5. Wire services in DI

```yaml
# config/services.yaml
services:
  YorvoLabs\Canary\Configuration\Options:
    factory: ['YorvoLabs\Canary\Configuration\Options', 'fromProvider']
    arguments: ['@Acme\Canary\AppConfigProvider']

  YorvoLabs\Canary\Auth\TokenAuthenticator:
    arguments: ['@YorvoLabs\Canary\Configuration\Options']

  YorvoLabs\Canary\Auth\IpAllowlistChecker:
    arguments: ['@YorvoLabs\Canary\Configuration\Options']

  YorvoLabs\Canary\Contract\RateLimiterInterface:
    alias: 'YorvoLabs\Canary\RateLimit\FileBasedRateLimiter'

  YorvoLabs\Canary\RateLimit\FileBasedRateLimiter:
    arguments: ['%kernel.project_dir%/var/canary/rate-limit']

  YorvoLabs\Canary\Auth\RequestGuardSubscriber:
    arguments:
      - '@YorvoLabs\Canary\Auth\TokenAuthenticator'
      - '@YorvoLabs\Canary\Auth\IpAllowlistChecker'
      - '@YorvoLabs\Canary\Contract\RateLimiterInterface'
      - '@YorvoLabs\Canary\Contract\AuditLoggerInterface'
      - '@YorvoLabs\Canary\Configuration\Options'
    tags: [kernel.event_subscriber]

  YorvoLabs\Canary\Health\HealthCheckRunner:
    arguments: [!tagged_iterator canary.health_check]

  YorvoLabs\Canary\Deploy\DeployHookRunner:
    arguments: [!tagged_iterator canary.deploy_hook]

  YorvoLabs\Canary\Response\ResponseFactory: ~

  # Your health checks
  Acme\Canary\DatabaseHealthCheck:
    arguments: ['@doctrine.dbal.default_connection']
    tags: [canary.health_check]
```

### 6. Add routes

Register the controllers from the bridge (or write your own thin controllers that call the core runners directly):

```yaml
# config/routes/canary.yaml
canary_health_live:
  path: /canary/health/live
  controller: Acme\Canary\Controller\LivenessController
  methods: [GET]

canary_health_ready:
  path: /canary/health/ready
  controller: Acme\Canary\Controller\ReadinessController
  methods: [GET]
```

The `RequestGuardSubscriber` fires on `kernel.request` (priority 256) and handles all auth before any controller runs. Your controllers do not need to call any auth code themselves.

### 7. Write controllers

Controllers are straightforward — inject the runner and return a response from `ResponseFactory`:

```php
<?php

declare(strict_types=1);

namespace Acme\Canary\Controller;

use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Response\ResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class ReadinessController
{
    public function __construct(
        private readonly HealthCheckRunner $runner,
        private readonly ResponseFactory $responseFactory,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;
        $result    = $this->runner->run();

        return $this->responseFactory->readiness($result, $requestId);
    }
}
```

## Custom rate limiter

To use Redis instead of files:

```php
<?php

declare(strict_types=1);

namespace Acme\Canary;

use YorvoLabs\Canary\Contract\RateLimiterInterface;
use YorvoLabs\Canary\RateLimit\RateLimitResult;
use Predis\ClientInterface;

final class RedisRateLimiter implements RateLimiterInterface
{
    private const WINDOW_SECONDS = 60;

    public function __construct(private readonly ClientInterface $redis) {}

    public function check(string $key, int $limit): RateLimitResult
    {
        $redisKey = 'canary_rl:' . hash('sha256', $key);
        $now      = time();

        $this->redis->zremrangebyscore($redisKey, 0, $now - self::WINDOW_SECONDS);
        $count = (int) $this->redis->zcard($redisKey);

        if ($count >= $limit) {
            return RateLimitResult::limited($limit, 0, self::WINDOW_SECONDS);
        }

        $this->redis->zadd($redisKey, [$now . '_' . random_int(0, PHP_INT_MAX) => $now]);
        $this->redis->expire($redisKey, self::WINDOW_SECONDS * 2);

        return RateLimitResult::allowed($limit, $limit - $count - 1);
    }
}
```

Register as the alias for `RateLimiterInterface`:

```yaml
YorvoLabs\Canary\Contract\RateLimiterInterface:
  alias: 'Acme\Canary\RedisRateLimiter'
```

## Route prefix integration

`Options::getRoutePrefix()` returns the configured prefix string. Use it when registering routes so that changing `CANARY_ROUTE_PREFIX` reconfigures all URLs without touching route files. In Shopware, `RegisterApiPrefixPass` is a compiler pass that does exactly this. Replicate the pattern for your framework.

## Checklist for a new bridge

- [ ] `ConfigProviderInterface` implemented and wired
- [ ] `AuditLoggerInterface` implemented and wired
- [ ] At least a `DatabaseHealthCheck` (or equivalent) registered
- [ ] `RequestGuardSubscriber` registered as a kernel event subscriber (priority 256)
- [ ] `RateLimiterInterface` alias pointing to a concrete implementation
- [ ] All controllers registered with correct routes
- [ ] Route prefix read from `Options::getRoutePrefix()`
- [ ] `var/canary/rate-limit/` (or equivalent) writable
- [ ] Tests: unit test your `ConfigProviderInterface` implementation; integration test the `/health/live` endpoint end-to-end
