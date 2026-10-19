<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Diagnostics;

use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

final class AppEnvCheck implements DiagnosticsInterface
{
    public function __construct(private readonly string $appEnv) {}

    public function getName(): string
    {
        return 'app_env';
    }

    public function execute(): DiagnosticsResult
    {
        if ($this->appEnv === 'prod') {
            return DiagnosticsResult::pass($this->getName(), "APP_ENV is {$this->appEnv}");
        }

        return DiagnosticsResult::fail(
            $this->getName(),
            "APP_ENV is {$this->appEnv} — expected prod in production",
        );
    }
}
