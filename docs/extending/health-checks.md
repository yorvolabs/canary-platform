# Extending: Custom Health Checks

Health checks appear in the `GET /health/ready` response and in the Overview tab of the admin UI. They let you register domain-specific checks (Redis connectivity, third-party API reachability, license validity, etc.) alongside the built-in Shopware checks.

## Interface

```php
namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Health\HealthCheckResult;

interface HealthCheckInterface
{
    /**
     * A short, stable snake_case identifier.
     * Appears in the response payload and in log entries.
     */
    public function getName(): string;

    /**
     * Perform the check and return a result.
     * Must never throw — catch all exceptions and return HealthCheckResult::fail().
     */
    public function execute(): HealthCheckResult;
}
```

## Result factory methods

```php
use YorvoLabs\Canary\Health\HealthCheckResult;

// All checks passed
HealthCheckResult::pass(string $name, float $durationMs, array $detail = []);

// Warning — degraded but not fully failed (e.g. latency elevated)
HealthCheckResult::warn(string $name, float $durationMs, string $message, array $detail = []);

// Failed — dependency unavailable
HealthCheckResult::fail(string $name, float $durationMs, string $message, array $detail = []);
```

`$durationMs` should reflect the actual time spent in the check. Use `hrtime(true)` for high-resolution measurement:

```php
$start = hrtime(true);
// ... check logic ...
$durationMs = (hrtime(true) - $start) / 1_000_000;
```

## Example: Redis connectivity check

```php
<?php

declare(strict_types=1);

namespace Acme\Shopware\Canary;

use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use Redis;

final class RedisHealthCheck implements HealthCheckInterface
{
    public function __construct(private readonly Redis $redis) {}

    public function getName(): string
    {
        return 'redis';
    }

    public function execute(): HealthCheckResult
    {
        $start = hrtime(true);

        try {
            $pong      = $this->redis->ping();
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            if ($pong !== '+PONG' && $pong !== true) {
                return HealthCheckResult::fail($this->getName(), $durationMs, 'Unexpected PING response');
            }

            return HealthCheckResult::pass($this->getName(), $durationMs);
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            return HealthCheckResult::fail($this->getName(), $durationMs, $e->getMessage());
        }
    }
}
```

## Registering in Shopware

Add the service to your plugin's `services.xml` with the `canary.health_check` tag:

```xml
<!-- src/Resources/config/services.xml -->
<service id="Acme\Shopware\Canary\RedisHealthCheck">
    <argument type="service" id="snc_redis.default"/>
    <tag name="canary.health_check"/>
</service>
```

That's all — `HealthCheckRunner` collects all tagged services via a `tagged_iterator` and executes them in registration order.

## Rules

- **Never throw.** If your check throws, the runner's catch-all will produce a FAIL result, but the stack trace will not appear in the response. Catch exceptions yourself to control the error message.
- **Always measure duration.** Pass a real `$durationMs` value; do not hard-code zero.
- **Keep names stable.** The name appears in monitoring dashboards and alert rules. Changing it after deployment will break existing alerts.
- **Use `warn` for degraded states.** A `warn` result makes the `/health/ready` response return `HTTP 200` (not 503), which is appropriate for elevated latency or partial availability that does not warrant taking the instance out of rotation.

## Built-in Shopware checks

The bridge ships these checks out of the box:

| Name | What it checks |
|---|---|
| `database` | `SELECT 1` against the primary database |
| `cache_adapter` | Write/read/delete against the Shopware cache pool |
| `filesystem` | Write permission on `var/`, `public/`, and `config/` directories |
| `elasticsearch` | TCP socket to the configured Elasticsearch host:port |
| `message_queue` | Queue depth vs. configurable threshold |
