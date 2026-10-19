<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Diagnostics;

use YorvoLabs\Canary\Contract\DiagnosticsInterface;

/**
 * Runs a collection of DiagnosticsInterface implementations sequentially.
 *
 * Any check that throws (despite the contract forbidding it) is caught here
 * and recorded as a failing result so a buggy check cannot abort the runner.
 */
final class DiagnosticsRunner
{
    /** @var list<DiagnosticsInterface> */
    private array $checks = [];

    /** @param iterable<DiagnosticsInterface> $checks */
    public function __construct(iterable $checks = [])
    {
        foreach ($checks as $check) {
            $this->add($check);
        }
    }

    public function add(DiagnosticsInterface $check): void
    {
        $this->checks[] = $check;
    }

    /** @return list<DiagnosticsResult> */
    public function run(): array
    {
        $results = [];

        foreach ($this->checks as $check) {
            try {
                $results[] = $check->execute();
            } catch (\Throwable $e) {
                $results[] = DiagnosticsResult::fail(
                    $check->getName(),
                    'Check threw an unexpected exception: ' . $e->getMessage(),
                );
            }
        }

        return $results;
    }
}
