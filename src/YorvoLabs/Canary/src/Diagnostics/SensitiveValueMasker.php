<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Diagnostics;

/**
 * Replaces values whose keys contain sensitive-sounding substrings with [REDACTED].
 *
 * Operates on flat and nested arrays. Non-scalar values under a sensitive key
 * are also redacted — structure must not be revealed either.
 */
final class SensitiveValueMasker
{
    private const DEFAULT_PATTERNS = [
        'token', 'password', 'pass', 'secret', 'key', 'dsn',
        'auth', 'credential', 'private', 'jwt',
    ];

    private const REDACTED = '[REDACTED]';

    /** Patterns applied by maskText() to scrub PII from free-text log content. */
    private const TEXT_PATTERNS = [
        '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/' => '[EMAIL]',
        '/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/'           => '[IP]',
    ];

    /** @var list<string> */
    private readonly array $patterns;

    /** @param list<string> $additionalPatterns */
    public function __construct(array $additionalPatterns = [])
    {
        $this->patterns = array_values(array_unique([
            ...self::DEFAULT_PATTERNS,
            ...array_map('strtolower', $additionalPatterns),
        ]));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function mask(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $result[$key] = self::REDACTED;
            } elseif (is_array($value)) {
                $result[$key] = $this->mask($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);

        foreach ($this->patterns as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** Scrubs PII patterns (emails, IPv4 addresses) from free-text log content. */
    public function maskText(string $text): string
    {
        foreach (self::TEXT_PATTERNS as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
