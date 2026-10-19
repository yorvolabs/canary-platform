# Extending: Custom Diagnostic Checks

Diagnostic checks are lightweight validations that run as part of `GET /diag/config`. They verify configuration completeness and correctness — for example, that a required env var is set, that a DSN is reachable, or that a feature flag is in the expected state.

Unlike health checks (which run on every readiness probe), diagnostic checks are typically called on-demand by operators or in CI validation jobs.

## Interface

```php
namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

interface DiagnosticsInterface
{
    /**
     * A short, stable snake_case identifier.
     * Appears in the validation array of GET /diag/config.
     */
    public function getName(): string;

    /**
     * Perform the check and return a result.
     * Must never throw.
     */
    public function execute(): DiagnosticsResult;
}
```

## Result factory methods

```php
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

DiagnosticsResult::pass(string $name, string $message);
DiagnosticsResult::fail(string $name, string $message);
```

## Example: verify a required API key is set

```php
<?php

declare(strict_types=1);

namespace Acme\Shopware\Diagnostics;

use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

final class StripeApiKeyCheck implements DiagnosticsInterface
{
    public function __construct(private readonly string $stripeKey) {}

    public function getName(): string
    {
        return 'stripe_api_key';
    }

    public function execute(): DiagnosticsResult
    {
        if ($this->stripeKey === '') {
            return DiagnosticsResult::fail($this->getName(), 'STRIPE_API_KEY is not set');
        }

        if (!str_starts_with($this->stripeKey, 'sk_')) {
            return DiagnosticsResult::fail($this->getName(), 'STRIPE_API_KEY does not look like a live key');
        }

        return DiagnosticsResult::pass($this->getName(), 'STRIPE_API_KEY is configured');
    }
}
```

## Registering in Shopware

```xml
<!-- src/Resources/config/services.xml -->
<service id="Acme\Shopware\Diagnostics\StripeApiKeyCheck">
    <argument>%env(STRIPE_API_KEY)%</argument>
    <tag name="canary.diagnostic"/>
</service>
```

`DiagnosticsRunner` collects all `canary.diagnostic`-tagged services and executes them.

## How results appear in the response

```json
{
  "namespace": "YorvoLabsCanary",
  "snapshot": { ... },
  "validation": [
    { "name": "app_env",       "pass": true,  "message": "APP_ENV is 'prod'" },
    { "name": "stripe_api_key","pass": false, "message": "STRIPE_API_KEY is not set" }
  ]
}
```

## Built-in Shopware checks

| Name | What it checks |
|---|---|
| `app_env` | `APP_ENV` is set to `prod` |
| `queue_transport` | `MESSENGER_TRANSPORT_DSN` is set |
| `mailer_transport` | `MAILER_DSN` is set |
