<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Configuration\RoutePrefix;

final class RoutePrefixTest extends TestCase
{
    #[Test]
    public function it_normalizes_a_plain_prefix(): void
    {
        $prefix = RoutePrefix::fromString('ops');

        self::assertSame('/ops', $prefix->withLeadingSlash());
        self::assertSame('ops', $prefix->value());
    }

    #[Test]
    public function it_strips_leading_slash(): void
    {
        $prefix = RoutePrefix::fromString('/ops');

        self::assertSame('/ops', $prefix->withLeadingSlash());
        self::assertSame('ops', $prefix->value());
    }

    #[Test]
    public function it_falls_back_to_default_on_empty_string(): void
    {
        $prefix = RoutePrefix::fromString('');

        self::assertSame('/' . RoutePrefix::DEFAULT, $prefix->withLeadingSlash());
    }

    #[Test]
    public function it_falls_back_to_default_on_whitespace_only(): void
    {
        $prefix = RoutePrefix::fromString('   ');

        self::assertSame('/' . RoutePrefix::DEFAULT, $prefix->withLeadingSlash());
    }

    #[Test]
    public function it_falls_back_to_default_on_slash_only(): void
    {
        $prefix = RoutePrefix::fromString('/');

        self::assertSame('/' . RoutePrefix::DEFAULT, $prefix->withLeadingSlash());
    }

    #[Test]
    public function it_reads_from_env(): void
    {
        $prev = $_ENV['CANARY_ROUTE_PREFIX'] ?? null;

        try {
            $_ENV['CANARY_ROUTE_PREFIX'] = 'monitoring';
            self::assertSame('/monitoring', RoutePrefix::fromEnv()->withLeadingSlash());
        } finally {
            if ($prev === null) {
                unset($_ENV['CANARY_ROUTE_PREFIX']);
            } else {
                $_ENV['CANARY_ROUTE_PREFIX'] = $prev;
            }
        }
    }

    #[Test]
    public function it_falls_back_to_default_when_env_is_absent(): void
    {
        $prevEnv = $_ENV['CANARY_ROUTE_PREFIX'] ?? null;
        $prevGetenv = getenv('CANARY_ROUTE_PREFIX');

        try {
            unset($_ENV['CANARY_ROUTE_PREFIX']);
            putenv('CANARY_ROUTE_PREFIX');

            self::assertSame('/' . RoutePrefix::DEFAULT, RoutePrefix::fromEnv()->withLeadingSlash());
        } finally {
            if ($prevEnv !== null) {
                $_ENV['CANARY_ROUTE_PREFIX'] = $prevEnv;
            }
            if ($prevGetenv !== false) {
                putenv('CANARY_ROUTE_PREFIX' . '=' . $prevGetenv);
            }
        }
    }
}
