<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Auth;

use YorvoLabs\Canary\Exception\InvalidCidrException;

/**
 * Validates a request IP against a list of CIDR ranges.
 *
 * Supports both IPv4 and IPv6. An empty allowlist means "allow all"
 * (suitable for shared hosting where IP context is unreliable).
 *
 * All CIDR parsing is done at construction time — if a range is malformed,
 * it fails fast rather than silently allowing traffic through.
 */
final class IpAllowlistChecker
{
    /** @var list<array{network: string, prefix: int, version: 4|6}> */
    private readonly array $parsedRanges;

    /**
     * @param list<string> $cidrs e.g. ["127.0.0.1/32", "10.0.0.0/8", "::1/128"]
     * @throws InvalidCidrException if any CIDR is malformed
     */
    public function __construct(array $cidrs)
    {
        $this->parsedRanges = array_values(array_map(
            fn(string $cidr) => $this->parseCidr(trim($cidr)),
            array_filter($cidrs, fn(string $s) => $s !== ''),
        ));
    }

    /**
     * Returns true if $ip is within any configured range, or if no ranges
     * are configured (allowlist disabled).
     */
    public function isAllowed(string $ip): bool
    {
        if ($this->parsedRanges === []) {
            return true;
        }

        $ip = trim($ip);

        foreach ($this->parsedRanges as $range) {
            if ($this->ipMatchesCidr($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    public function isConfigured(): bool
    {
        return $this->parsedRanges !== [];
    }

    /** @return array{network: string, prefix: int, version: 4|6} */
    private function parseCidr(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            throw new InvalidCidrException("Missing prefix length in CIDR: \"{$cidr}\"");
        }

        [$network, $prefixStr] = explode('/', $cidr, 2);

        if (!filter_var($network, FILTER_VALIDATE_IP)) {
            throw new InvalidCidrException("Invalid IP address in CIDR: \"{$cidr}\"");
        }

        $prefix = (int) $prefixStr;
        $isV6 = str_contains($network, ':');
        $maxPrefix = $isV6 ? 128 : 32;

        if ($prefix < 0 || $prefix > $maxPrefix) {
            throw new InvalidCidrException(
                "Prefix length {$prefix} out of range for " . ($isV6 ? 'IPv6' : 'IPv4') . " in: \"{$cidr}\""
            );
        }

        return [
            'network' => $network,
            'prefix' => $prefix,
            'version' => $isV6 ? 6 : 4,
        ];
    }

    /** @param array{network: string, prefix: int, version: 4|6} $range */
    private function ipMatchesCidr(string $ip, array $range): bool
    {
        $ipIsV6 = str_contains($ip, ':');
        $rangeIsV6 = $range['version'] === 6;

        // Version mismatch — can never match.
        if ($ipIsV6 !== $rangeIsV6) {
            return false;
        }

        $ipBin = inet_pton($ip);
        $networkBin = inet_pton($range['network']);

        if ($ipBin === false || $networkBin === false) {
            return false;
        }

        $prefix = $range['prefix'];
        $totalBits = $rangeIsV6 ? 128 : 32;
        $totalBytes = $totalBits >> 3;

        // Build a binary mask.
        $mask = str_repeat("\xff", (int) ($prefix >> 3));

        $remainingBits = $prefix & 7;
        if ($remainingBits > 0) {
            $mask .= chr((0xff << (8 - $remainingBits)) & 0xff);
        }

        $mask = str_pad($mask, $totalBytes, "\x00");

        return ($ipBin & $mask) === ($networkBin & $mask);
    }
}
