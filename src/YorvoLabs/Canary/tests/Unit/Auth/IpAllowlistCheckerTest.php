<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Auth\IpAllowlistChecker;
use YorvoLabs\Canary\Exception\InvalidCidrException;

final class IpAllowlistCheckerTest extends TestCase
{
    #[Test]
    public function it_allows_all_ips_when_no_cidrs_are_configured(): void
    {
        $checker = new IpAllowlistChecker([]);
        self::assertTrue($checker->isAllowed('1.2.3.4'));
        self::assertTrue($checker->isAllowed('203.0.113.99'));
        self::assertFalse($checker->isConfigured());
    }

    #[Test]
    public function it_allows_an_exact_ipv4_host(): void
    {
        $checker = new IpAllowlistChecker(['203.0.113.50/32']);
        self::assertTrue($checker->isAllowed('203.0.113.50'));
        self::assertFalse($checker->isAllowed('203.0.113.51'));
    }

    #[Test]
    public function it_allows_ips_within_an_ipv4_subnet(): void
    {
        $checker = new IpAllowlistChecker(['10.0.0.0/8']);
        self::assertTrue($checker->isAllowed('10.0.0.1'));
        self::assertTrue($checker->isAllowed('10.255.255.255'));
        self::assertFalse($checker->isAllowed('11.0.0.1'));
        self::assertFalse($checker->isAllowed('9.255.255.255'));
    }

    #[Test]
    public function it_allows_ips_in_any_of_the_configured_ranges(): void
    {
        $checker = new IpAllowlistChecker(['127.0.0.1/32', '10.0.0.0/8', '203.0.113.50/32']);
        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('10.20.30.40'));
        self::assertTrue($checker->isAllowed('203.0.113.50'));
        self::assertFalse($checker->isAllowed('192.168.1.1'));
    }

    #[Test]
    public function it_allows_ipv6_loopback(): void
    {
        $checker = new IpAllowlistChecker(['::1/128']);
        self::assertTrue($checker->isAllowed('::1'));
        self::assertFalse($checker->isAllowed('::2'));
    }

    #[Test]
    public function it_handles_ipv6_subnet(): void
    {
        $checker = new IpAllowlistChecker(['2001:db8::/32']);
        self::assertTrue($checker->isAllowed('2001:db8::1'));
        self::assertTrue($checker->isAllowed('2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'));
        self::assertFalse($checker->isAllowed('2001:db9::1'));
    }

    #[Test]
    public function it_rejects_ipv4_address_against_ipv6_range(): void
    {
        $checker = new IpAllowlistChecker(['::1/128']);
        self::assertFalse($checker->isAllowed('127.0.0.1'));
    }

    #[Test]
    public function it_trims_whitespace_from_cidr_entries(): void
    {
        $checker = new IpAllowlistChecker(['  127.0.0.1/32  ', '  10.0.0.0/8  ']);
        self::assertTrue($checker->isAllowed('127.0.0.1'));
        self::assertTrue($checker->isAllowed('10.1.2.3'));
    }

    #[Test]
    #[DataProvider('malformedCidrs')]
    public function it_throws_on_malformed_cidr(string $cidr): void
    {
        $this->expectException(InvalidCidrException::class);
        new IpAllowlistChecker([$cidr]);
    }

    /** @return array<string, array{string}> */
    public static function malformedCidrs(): array
    {
        return [
            'missing prefix'      => ['10.0.0.0'],
            'invalid ip'          => ['not-an-ip/24'],
            'prefix too large v4' => ['10.0.0.0/33'],
            'prefix too large v6' => ['::1/129'],
            'negative prefix'     => ['10.0.0.0/-1'],
        ];
    }
}
