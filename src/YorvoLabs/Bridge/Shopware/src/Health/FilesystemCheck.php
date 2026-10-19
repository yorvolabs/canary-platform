<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Health;

use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Checks that the directories Shopware writes to at runtime are accessible.
 *
 * A missing or read-only var/cache or var/log will cause silent failures:
 * the cache will fall back to in-memory (no persistence across requests)
 * and log writes will be silently dropped.
 */
final class FilesystemCheck implements HealthCheckInterface
{
    /**
     * @param list<string> $paths Absolute paths to check; when empty, defaults to
     *                            [$projectDir/var/cache, $projectDir/var/log]
     */
    public function __construct(
        private readonly string $projectDir,
        /** @var list<string> */
        private readonly array $paths = [],
    ) {}

    public function getName(): string
    {
        return 'filesystem';
    }

    public function execute(): HealthCheckResult
    {
        $start = Stopwatch::start();

        $targets = $this->paths !== []
            ? $this->paths
            : [$this->projectDir . '/var/cache', $this->projectDir . '/var/log'];

        $failures = [];

        foreach ($targets as $dir) {
            if (!is_dir($dir)) {
                $failures[] = $dir . ' (not found)';
            } elseif (!is_writable($dir)) {
                $failures[] = $dir . ' (not writable)';
            }
        }

        $durationMs = Stopwatch::elapsedMs($start);

        if ($failures !== []) {
            return HealthCheckResult::fail(
                $this->getName(),
                $durationMs,
                'Filesystem check failed: ' . implode(', ', $failures),
            );
        }

        return HealthCheckResult::pass($this->getName(), $durationMs);
    }
}
