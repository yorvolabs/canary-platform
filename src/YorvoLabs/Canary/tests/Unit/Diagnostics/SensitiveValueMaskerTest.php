<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;

final class SensitiveValueMaskerTest extends TestCase
{
    private SensitiveValueMasker $masker;

    protected function setUp(): void
    {
        $this->masker = new SensitiveValueMasker();
    }

    #[Test]
    public function it_redacts_default_sensitive_key_patterns(): void
    {
        $result = $this->masker->mask([
            'CANARY_TOKEN_MONITORING' => 'abc123',
            'DATABASE_PASSWORD'       => 'secret',
            'MAILER_DSN'              => 'smtp://user:pass@host',
            'JWT_SECRET'              => 'very-secret',
            'PRIVATE_KEY'             => 'rsa-key',
            'AUTH_TOKEN'              => 'bearer-xyz',
        ]);

        foreach ($result as $value) {
            self::assertSame('[REDACTED]', $value);
        }
    }

    #[Test]
    public function it_leaves_non_sensitive_keys_untouched(): void
    {
        $result = $this->masker->mask([
            'APP_ENV'              => 'prod',
            'SHOPWARE_ES_HOSTS'    => 'elasticsearch:9200',
            'PHP_VERSION'          => '8.4',
            'MEMORY_LIMIT'         => '256M',
        ]);

        self::assertSame('prod', $result['APP_ENV']);
        self::assertSame('elasticsearch:9200', $result['SHOPWARE_ES_HOSTS']);
        self::assertSame('8.4', $result['PHP_VERSION']);
        self::assertSame('256M', $result['MEMORY_LIMIT']);
    }

    #[Test]
    public function it_recurses_into_nested_arrays(): void
    {
        $result = $this->masker->mask([
            'shopware' => [
                'version' => '6.6.8.0',
                'db_password' => 'should-be-redacted',
            ],
        ]);

        self::assertSame('6.6.8.0', $result['shopware']['version']);
        self::assertSame('[REDACTED]', $result['shopware']['db_password']);
    }

    #[Test]
    public function it_redacts_non_scalar_values_under_sensitive_keys(): void
    {
        $result = $this->masker->mask([
            'auth_config' => ['username' => 'admin', 'password' => 'secret'],
        ]);

        self::assertSame('[REDACTED]', $result['auth_config']);
    }

    #[Test]
    public function it_applies_custom_additional_patterns(): void
    {
        $masker = new SensitiveValueMasker(['apikey', 'webhook']);

        $result = $masker->mask([
            'STRIPE_APIKEY'   => 'sk_live_abc',
            'WEBHOOK_SECRET'  => 'whsec_xyz',
            'APP_ENV'         => 'prod',
        ]);

        self::assertSame('[REDACTED]', $result['STRIPE_APIKEY']);
        self::assertSame('[REDACTED]', $result['WEBHOOK_SECRET']);
        self::assertSame('prod', $result['APP_ENV']);
    }

    #[Test]
    public function it_is_case_insensitive_for_key_matching(): void
    {
        $result = $this->masker->mask([
            'Token'    => 'a',
            'TOKEN'    => 'b',
            'token'    => 'c',
            'ToKeN'    => 'd',
        ]);

        foreach ($result as $value) {
            self::assertSame('[REDACTED]', $value);
        }
    }
}
