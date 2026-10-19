<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Health;

use Doctrine\DBAL\Connection;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Verifies database reachability with a minimal SELECT 1 query.
 *
 * Uses the same Doctrine DBAL connection Shopware uses for all ORM operations.
 * A FAIL here means no part of the application can serve data-driven requests.
 */
final class DatabaseCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function getName(): string
    {
        return 'database';
    }

    public function execute(): HealthCheckResult
    {
        $start = Stopwatch::start();

        try {
            $this->connection->executeQuery('SELECT 1');
            $durationMs = Stopwatch::elapsedMs($start);

            return HealthCheckResult::pass($this->getName(), $durationMs);
        } catch (\Throwable $e) {
            $durationMs = Stopwatch::elapsedMs($start);

            return HealthCheckResult::fail(
                $this->getName(),
                $durationMs,
                'Database unreachable: ' . $e->getMessage(),
            );
        }
    }
}
