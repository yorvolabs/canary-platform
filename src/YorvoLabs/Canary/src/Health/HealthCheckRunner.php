<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Health;

use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Runs a collection of HealthCheckInterface implementations and
 * aggregates the results into a single HealthRunResult.
 *
 * Execution is sequential — checks are run in registration order.
 * Any check that throws (despite the contract forbidding it) is caught
 * here and recorded as FAIL so a buggy check can never take down the runner.
 */
final class HealthCheckRunner
{
    /** @var list<HealthCheckInterface> */
    private array $checks = [];

    /** @param iterable<HealthCheckInterface> $checks */
    public function __construct(iterable $checks = [])
    {
        foreach ($checks as $check) {
            $this->add($check);
        }
    }

    public function add(HealthCheckInterface $check): void
    {
        $this->checks[] = $check;
    }

    public function run(): HealthRunResult
    {
        $results = [];
        $overallStatus = HealthStatus::PASS;

        foreach ($this->checks as $check) {
            $start = Stopwatch::start();

            try {
                $result = $check->execute();
            } catch (\Throwable $e) {
                $durationMs = Stopwatch::elapsedMs($start);
                $result = HealthCheckResult::fail(
                    $check->getName(),
                    $durationMs,
                    'Check threw an unexpected exception: ' . $e->getMessage(),
                );
            }

            $results[] = $result;
            $overallStatus = $overallStatus->merge($result->status);
        }

        return new HealthRunResult($overallStatus, $results);
    }
}
