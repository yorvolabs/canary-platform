<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\RateLimit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\RateLimit\IpBucket;

final class IpBucketTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function addresses(): iterable
    {
        yield 'IPv4'                    => ['192.0.2.1', '192.0.2.1'];
        yield 'IPv6 keyed by /64'       => ['2001:db8:1:2:aaaa:bbbb:cccc:dddd', '2001:db8:1:2::/64'];
        yield 'IPv6 short form'         => ['2001:db8::1', '2001:db8::/64'];
        yield 'IPv6 loopback'           => ['::1', '::/64'];
        yield 'IPv4-mapped IPv6'        => ['::ffff:192.0.2.1', '192.0.2.1'];
        yield 'not an IP'               => ['unknown', 'unknown'];
        yield 'IPv6 with zone id'       => ['fe80::1%eth0', 'fe80::1%eth0'];
    }

    #[Test]
    #[DataProvider('addresses')]
    public function it_maps_an_address_to_its_bucket(string $ip, string $bucket): void
    {
        self::assertSame($bucket, IpBucket::of($ip));
    }

    #[Test]
    public function addresses_in_one_ipv6_64_share_a_bucket_and_other_64s_do_not(): void
    {
        self::assertSame(IpBucket::of('2001:db8:1:2::1'), IpBucket::of('2001:db8:1:2:ffff:ffff:ffff:ffff'));
        self::assertNotSame(IpBucket::of('2001:db8:1:2::1'), IpBucket::of('2001:db8:1:3::1'));
    }
}
