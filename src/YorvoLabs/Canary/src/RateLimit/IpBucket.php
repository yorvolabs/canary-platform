<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\RateLimit;

/**
 * Maps a client IP to the address its rate-limit bucket is keyed by.
 *
 * - IPv4: the address itself.
 * - IPv4-mapped IPv6 (::ffff:192.0.2.1): the IPv4 address.
 * - IPv6: the /64 network, because one host or site usually controls a whole
 *   /64 and could otherwise get a fresh bucket for every address in it.
 * - Anything that is not a valid IP: unchanged.
 */
final class IpBucket
{
    public static function of(string $ip): string
    {
        $binary = @inet_pton($ip);
        if ($binary === false) {
            return $ip;
        }

        if (strlen($binary) === 4) {
            return (string) inet_ntop($binary);
        }

        if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
            return (string) inet_ntop(substr($binary, 12));
        }

        return inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
