# Extending: Custom Deploy Hooks

Deploy hooks let you inject custom steps into the pre-deploy readiness check (`GET /deploy/pre`) and the post-deploy verification sequence (`POST /deploy/post`). Common uses: running database migrations, warming caches, sending Slack notifications, or verifying external service connectivity after a release.

## Interface

```php
namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Deploy\DeployHookResult;
use YorvoLabs\Canary\Deploy\DeployPhase;

interface DeployHookInterface
{
    /**
     * A short, stable snake_case identifier.
     * Appears in the response payload and in log entries.
     */
    public function getName(): string;

    /** The phase in which this hook runs. */
    public function getPhase(): DeployPhase;

    /**
     * Execute the hook and return a result.
     * Must never throw — catch all exceptions and return DeployHookResult::fail().
     */
    public function execute(): DeployHookResult;
}
```

## Deploy phases

```php
use YorvoLabs\Canary\Deploy\DeployPhase;

DeployPhase::PRE   // runs during GET /deploy/pre
DeployPhase::POST  // runs during POST /deploy/post
```

A single class implements exactly one phase. Create two separate classes if you need both.

## Result factory methods

```php
use YorvoLabs\Canary\Deploy\DeployHookResult;

DeployHookResult::pass(string $name, float $durationMs, string $detail = '');
DeployHookResult::fail(string $name, float $durationMs, string $detail = '');
```

## Example: PRE hook — verify external payment API is reachable

```php
<?php

declare(strict_types=1);

namespace Acme\Shopware\Deploy;

use YorvoLabs\Canary\Contract\DeployHookInterface;
use YorvoLabs\Canary\Deploy\DeployHookResult;
use YorvoLabs\Canary\Deploy\DeployPhase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PaymentApiPreCheck implements DeployHookInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $paymentApiUrl,
    ) {}

    public function getName(): string
    {
        return 'payment_api_reachable';
    }

    public function getPhase(): DeployPhase
    {
        return DeployPhase::PRE;
    }

    public function execute(): DeployHookResult
    {
        $start = hrtime(true);

        try {
            $response   = $this->http->request('GET', $this->paymentApiUrl . '/health');
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            if ($response->getStatusCode() !== 200) {
                return DeployHookResult::fail(
                    $this->getName(),
                    $durationMs,
                    'Payment API returned HTTP ' . $response->getStatusCode(),
                );
            }

            return DeployHookResult::pass($this->getName(), $durationMs, 'Payment API reachable');
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            return DeployHookResult::fail($this->getName(), $durationMs, $e->getMessage());
        }
    }
}
```

## Example: POST hook — send Slack release notification

```php
<?php

declare(strict_types=1);

namespace Acme\Shopware\Deploy;

use YorvoLabs\Canary\Contract\DeployHookInterface;
use YorvoLabs\Canary\Deploy\DeployHookResult;
use YorvoLabs\Canary\Deploy\DeployPhase;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SlackReleaseNotifier implements DeployHookInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $webhookUrl,
        private readonly string $appVersion,
    ) {}

    public function getName(): string
    {
        return 'slack_release_notification';
    }

    public function getPhase(): DeployPhase
    {
        return DeployPhase::POST;
    }

    public function execute(): DeployHookResult
    {
        $start = hrtime(true);

        try {
            $this->http->request('POST', $this->webhookUrl, [
                'json' => ['text' => "Deployed version {$this->appVersion} successfully."],
            ]);

            $durationMs = (hrtime(true) - $start) / 1_000_000;

            return DeployHookResult::pass($this->getName(), $durationMs, 'Notification sent');
        } catch (\Throwable $e) {
            $durationMs = (hrtime(true) - $start) / 1_000_000;

            // Non-critical: notification failure shouldn't block the deploy
            return DeployHookResult::pass(
                $this->getName(),
                $durationMs,
                'Notification skipped: ' . $e->getMessage(),
            );
        }
    }
}
```

## Registering in Shopware

Add both services to `services.xml` with the `canary.deploy_hook` tag:

```xml
<!-- src/Resources/config/services.xml -->
<service id="Acme\Shopware\Deploy\PaymentApiPreCheck">
    <argument type="service" id="http_client"/>
    <argument>%env(PAYMENT_API_URL)%</argument>
    <tag name="canary.deploy_hook"/>
</service>

<service id="Acme\Shopware\Deploy\SlackReleaseNotifier">
    <argument type="service" id="http_client"/>
    <argument>%env(SLACK_WEBHOOK_URL)%</argument>
    <argument>%env(APP_VERSION)%</argument>
    <tag name="canary.deploy_hook"/>
</service>
```

`DeployHookRunner` collects all tagged hooks, filters by phase, and executes them in registration order.

## How hooks appear in the response

PRE hook results appear in the `checks` array of `GET /deploy/pre`:

```json
{
  "pass": false,
  "checks": [
    { "name": "maintenance_not_active",  "pass": true,  "detail": {} },
    { "name": "queue_depth",             "pass": true,  "detail": {} },
    { "name": "payment_api_reachable",   "pass": false, "detail": "Payment API returned HTTP 503" }
  ]
}
```

POST hook results appear in the `steps` array of `POST /deploy/post`:

```json
{
  "pass": true,
  "steps": [
    { "name": "opcache_reset",              "pass": true, "duration_ms": 5  },
    { "name": "cache_clear",               "pass": true, "duration_ms": 95 },
    { "name": "readiness_check",           "pass": true, "duration_ms": 12 },
    { "name": "slack_release_notification","pass": true, "duration_ms": 220 }
  ]
}
```

## Rules

- **Never throw.** The runner does not catch exceptions from hooks — an uncaught exception would return a 500. Always wrap in try/catch.
- **PRE hook failure blocks deployment.** If a PRE hook returns `fail`, the top-level `"pass": false` signals the pipeline to abort.
- **POST hook failure is advisory.** A failed POST hook does not roll back the deployment. Use it for notifications or non-critical verification.
- **Names must be stable.** The name is used in CI pipeline assertions. Renaming breaks existing pipeline checks.
- **Measure duration yourself.** Use `hrtime(true)` inside the hook and pass the result to `DeployHookResult`.
