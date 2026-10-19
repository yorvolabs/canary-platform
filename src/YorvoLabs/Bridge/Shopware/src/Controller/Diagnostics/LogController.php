<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/diag/logs[?level=error&lines=50]
 *
 * Tails the most recent application log file, strips PII (email addresses,
 * IPv4 addresses), and optionally filters by log level.
 *
 * Query params:
 *   ?level=error   — filter by level (error, critical, warning, notice, info, debug)
 *   ?lines=50      — number of entries, clamped to [1, 500]
 *
 * Requires DIAGNOSTICS scope token.
 */
#[Route(
    '/diag/logs',
    name: 'canary.diag.logs',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class LogController
{
    private const MAX_LINES = 500;
    private const DEFAULT_LINES = 50;

    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly string $logDir,
        private readonly SensitiveValueMasker $masker,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;
        $lines     = max(1, min(self::MAX_LINES, (int) ($request->query->get('lines') ?? self::DEFAULT_LINES)));
        $level     = strtolower((string) ($request->query->get('level') ?? ''));

        $logFile = $this->findLatestLogFile();

        if ($logFile === null) {
            return $this->responseFactory->notFound($requestId);
        }

        [$format, $entries] = $this->readEntries($logFile, $lines, $level);

        return $this->responseFactory->ok([
            'file'          => basename($logFile),
            'format'        => $format,
            'lines_returned' => count($entries),
            'entries'       => $entries,
        ], $requestId);
    }

    /**
     * @return list<array{name: string, size: int, modified: string}>
     */
    public function listFiles(): array
    {
        return array_map(static fn(string $f): array => [
            'name'     => basename($f),
            'size'     => (int) filesize($f),
            'modified' => date(\DateTimeInterface::ATOM, (int) filemtime($f)),
        ], $this->sortedLogFiles());
    }

    private function findLatestLogFile(): string|null
    {
        $files = $this->sortedLogFiles();

        return $files !== [] ? $files[0] : null;
    }

    /** @return list<string> */
    private function sortedLogFiles(): array
    {
        $files = glob($this->logDir . '/*.log') ?: [];
        usort($files, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files;
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function readEntries(string $logFile, int $lines, string $level): array
    {
        $tail = $this->tailLines($logFile, $lines * 10); // over-read to allow level filtering

        if ($tail === null) {
            return ['text', []];
        }

        $format = $this->detectFormat($tail);

        $entries = [];
        foreach (array_reverse($tail) as $line) {
            if (count($entries) >= $lines) {
                break;
            }

            $entry = $format === 'json'
                ? $this->parseJsonLine($line)
                : $this->masker->maskText($line);

            if ($entry === null) {
                continue;
            }

            if ($level !== '' && !$this->matchesLevel($entry, $level, $format)) {
                continue;
            }

            $entries[] = $entry;
        }

        return [$format, array_reverse($entries)];
    }

    /**
     * Reads the last $maxLines non-empty lines from a file in 64 KB chunks,
     * never loading the whole file into memory.
     *
     * @return list<string>|null  null when the file cannot be opened
     */
    private function tailLines(string $logFile, int $maxLines): array|null
    {
        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $reversed  = [];
            $buffer    = '';
            $chunkSize = 65536;

            fseek($handle, 0, SEEK_END);
            $pos = ftell($handle);

            while ($pos > 0 && count($reversed) < $maxLines) {
                $read = min($chunkSize, $pos);
                $pos -= $read;
                fseek($handle, $pos);
                $chunk    = fread($handle, $read);
                $combined = $chunk . $buffer;
                $parts    = explode("\n", $combined);
                $buffer   = (string) array_shift($parts);

                foreach (array_reverse($parts) as $line) {
                    $trimmed = rtrim($line, "\r");
                    if ($trimmed !== '') {
                        $reversed[] = $trimmed;
                        if (count($reversed) >= $maxLines) {
                            break 2;
                        }
                    }
                }
            }

            if ($buffer !== '' && count($reversed) < $maxLines) {
                $trimmed = rtrim($buffer, "\r");
                if ($trimmed !== '') {
                    $reversed[] = $trimmed;
                }
            }

            return array_reverse($reversed);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param list<string> $lines
     */
    private function detectFormat(array $lines): string
    {
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['message'], $decoded['level'])) {
                return 'json';
            }
            break;
        }

        return 'text';
    }

    /** @return array<string, mixed>|null */
    private function parseJsonLine(string $line): array|null
    {
        $decoded = json_decode($line, true);

        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['message'])) {
            $decoded['message'] = $this->masker->maskText((string) $decoded['message']);
        }

        if (isset($decoded['context']) && is_array($decoded['context'])) {
            $decoded['context'] = array_map(
                fn(mixed $v): mixed => is_string($v) ? $this->masker->maskText($v) : $v,
                $decoded['context'],
            );
        }

        return $decoded;
    }

    private function matchesLevel(mixed $entry, string $level, string $format): bool
    {
        if ($format === 'json' && is_array($entry)) {
            $entryLevel = strtolower((string) ($entry['level'] ?? $entry['level_name'] ?? ''));
            return str_contains($entryLevel, $level);
        }

        return str_contains(strtolower((string) $entry), $level);
    }
}
