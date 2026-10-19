<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\EnvController;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

final class EnvControllerTest extends TestCase
{
    private function makeController(string $shopwareVersion = '6.6.8.0'): EnvController
    {
        return new EnvController(
            new ResponseFactory(),
            new SensitiveValueMasker(),
            $shopwareVersion,
            'test',
        );
    }

    #[Test]
    public function it_returns_200_with_three_top_level_sections(): void
    {
        $response = ($this->makeController())(Request::create('/canary/diag/env', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('php', $body['data']);
        self::assertArrayHasKey('shopware', $body['data']);
        self::assertArrayHasKey('env', $body['data']);
    }

    #[Test]
    public function it_includes_php_version_in_response(): void
    {
        $response = ($this->makeController())(Request::create('/canary/diag/env', 'GET'));
        $body     = json_decode((string) $response->getContent(), true);
        $php      = $body['data']['php'];

        self::assertArrayHasKey('version', $php);
        self::assertArrayHasKey('memory_limit', $php);
        self::assertArrayHasKey('max_execution_time', $php);
        self::assertArrayHasKey('extensions', $php);
        self::assertIsArray($php['extensions']);
    }

    #[Test]
    public function it_reports_shopware_version(): void
    {
        $response = ($this->makeController('6.5.0.0'))(Request::create('/canary/diag/env', 'GET'));
        $body     = json_decode((string) $response->getContent(), true);

        self::assertSame('6.5.0.0', $body['data']['shopware']['version']);
    }

    #[Test]
    public function it_masks_sensitive_env_keys(): void
    {
        // Inject a known sensitive value into $_ENV before the controller runs
        $_ENV['CANARY_TEST_TOKEN_FOR_ENV_CTRL'] = 'supersecret-' . str_repeat('x', 36);

        try {
            $response = ($this->makeController())(Request::create('/canary/diag/env', 'GET'));
            $body     = json_decode((string) $response->getContent(), true);
            $env      = $body['data']['env'];

            if (isset($env['CANARY_TEST_TOKEN_FOR_ENV_CTRL'])) {
                self::assertSame('[REDACTED]', $env['CANARY_TEST_TOKEN_FOR_ENV_CTRL']);
            }
        } finally {
            unset($_ENV['CANARY_TEST_TOKEN_FOR_ENV_CTRL']);
        }
    }

    #[Test]
    public function it_excludes_lowercase_and_http_prefixed_keys_from_env(): void
    {
        $response = ($this->makeController())(Request::create('/canary/diag/env', 'GET'));
        $body     = json_decode((string) $response->getContent(), true);
        $env      = $body['data']['env'];

        foreach (array_keys($env) as $key) {
            self::assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]*$/', (string) $key, "Key '{$key}' should be ALL_CAPS_UNDERSCORE only");
        }
    }
}
