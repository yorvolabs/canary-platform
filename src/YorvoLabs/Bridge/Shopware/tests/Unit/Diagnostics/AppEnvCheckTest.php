<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Diagnostics\AppEnvCheck;

final class AppEnvCheckTest extends TestCase
{
    #[Test]
    public function it_has_stable_name(): void
    {
        self::assertSame('app_env', (new AppEnvCheck('prod'))->getName());
    }

    #[Test]
    public function it_passes_in_production(): void
    {
        $result = (new AppEnvCheck('prod'))->execute();

        self::assertTrue($result->pass);
        self::assertStringContainsString('prod', (string) $result->message);
    }

    #[Test]
    public function it_fails_when_env_is_dev(): void
    {
        $result = (new AppEnvCheck('dev'))->execute();

        self::assertFalse($result->pass);
        self::assertStringContainsString('dev', (string) $result->message);
    }

    #[Test]
    public function it_fails_when_env_is_test(): void
    {
        $result = (new AppEnvCheck('test'))->execute();

        self::assertFalse($result->pass);
    }
}
