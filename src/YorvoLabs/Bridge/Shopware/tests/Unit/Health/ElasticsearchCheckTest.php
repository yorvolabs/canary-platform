<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Health;

use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Health\ElasticsearchCheck;
use YorvoLabs\Canary\Health\HealthStatus;

final class ElasticsearchCheckTest extends TestCase
{
    public function testGetName(): void
    {
        $check = new ElasticsearchCheck('');
        self::assertSame('elasticsearch', $check->getName());
    }

    public function testPassWithEmptyHostsString(): void
    {
        $check = new ElasticsearchCheck('');

        $result = $check->execute();

        self::assertSame(HealthStatus::PASS, $result->status);
        self::assertSame(0, $result->durationMs);
        self::assertTrue($result->detail['not_configured'] ?? false);
    }

    /** @param array{string, int} $expected */
    #[\PHPUnit\Framework\Attributes\DataProvider('parseHostProvider')]
    public function testParseFirstHostVariants(string $hosts, array $expected): void
    {
        $check = new ElasticsearchCheck($hosts);
        $method = new \ReflectionMethod(ElasticsearchCheck::class, 'parseFirstHost');
        $method->setAccessible(true);

        $prop = new \ReflectionProperty(ElasticsearchCheck::class, 'hosts');
        $prop->setAccessible(true);

        self::assertSame($expected, $method->invoke($check, $prop->getValue($check)));
    }

    /**
     * @return iterable<string, array{string, array{string, int}}>
     */
    public static function parseHostProvider(): iterable
    {
        yield 'plain host only'           => ['elasticsearch',                ['elasticsearch', 9200]];
        yield 'host with port'            => ['elasticsearch:9200',           ['elasticsearch', 9200]];
        yield 'http scheme'               => ['http://elasticsearch:9200',    ['elasticsearch', 9200]];
        yield 'https scheme'              => ['https://elasticsearch:9200',   ['elasticsearch', 9200]];
        yield 'first of comma list'       => ['host1:9200,host2:9200',        ['host1', 9200]];
        yield 'host with path stripped'   => ['http://host:9200/_cluster',    ['host', 9200]];
        yield 'bracketed IPv6 with port'  => ['[::1]:9200',                   ['::1', 9200]];
        yield 'bracketed IPv6 no port'    => ['[::1]',                        ['::1', 9200]];
        yield 'bracketed IPv6 in list'    => ['[::1]:9300,[::2]:9300',        ['::1', 9300]];
        yield 'whitespace trimmed'        => ['  elasticsearch:9200  ',       ['elasticsearch', 9200]];
    }

    public function testFailWhenHostUnreachable(): void
    {
        // Port 19200 on 127.0.0.1 is almost certainly not listening.
        $check = new ElasticsearchCheck('127.0.0.1:19200');

        $result = $check->execute();

        self::assertSame(HealthStatus::FAIL, $result->status);
        self::assertStringContainsString('127.0.0.1', (string) $result->error);
    }
}
