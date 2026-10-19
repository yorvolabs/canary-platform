<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Configuration;

final class RoutePrefix
{
    public const DEFAULT = 'canary';

    private function __construct(private readonly string $prefix) {}

    /**
     * Normalize a raw string: strip leading slashes, trim whitespace.
     * Falls back to DEFAULT if the result is empty.
     */
    public static function fromString(string $raw): self
    {
        $normalized = ltrim(trim($raw), '/');

        return new self($normalized !== '' ? $normalized : self::DEFAULT);
    }

    /**
     * Read from the environment ($_ENV takes priority, then getenv()).
     * Falls back to DEFAULT when the variable is absent or empty.
     */
    public static function fromEnv(): self
    {
        $envValue = getenv('CANARY_ROUTE_PREFIX');
        $raw = $_ENV['CANARY_ROUTE_PREFIX'] ?? ($envValue !== false ? $envValue : null) ?? self::DEFAULT;

        return self::fromString((string) $raw);
    }

    /** For routing: '/canary', '/ops' */
    public function withLeadingSlash(): string
    {
        return '/' . $this->prefix;
    }

    /** For Shopware API prefix list (no leading slash): 'canary', 'ops' */
    public function value(): string
    {
        return $this->prefix;
    }
}
