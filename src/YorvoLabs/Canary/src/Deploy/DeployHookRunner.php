<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Deploy;

use YorvoLabs\Canary\Contract\DeployHookInterface;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Runs all DeployHookInterface implementations registered for a given phase.
 *
 * Hooks for the requested phase are executed in registration order.
 * Any hook that throws (despite the contract forbidding it) is caught and
 * recorded as a failing result so a buggy hook cannot abort the deploy sequence.
 *
 * Timing is measured externally so hook implementations do not need to track it.
 */
final class DeployHookRunner
{
    /** @var list<DeployHookInterface> */
    private array $hooks = [];

    /** @param iterable<DeployHookInterface> $hooks */
    public function __construct(iterable $hooks = [])
    {
        foreach ($hooks as $hook) {
            $this->add($hook);
        }
    }

    public function add(DeployHookInterface $hook): void
    {
        $this->hooks[] = $hook;
    }

    /** @return list<array{name: string, phase: string}> */
    public function getHookDescriptions(): array
    {
        return array_map(
            fn(DeployHookInterface $h): array => [
                'name'  => $h->getName(),
                'phase' => $h->getPhase()->value,
            ],
            $this->hooks,
        );
    }

    /** @return list<DeployHookResult> */
    public function run(DeployPhase $phase): array
    {
        $results = [];

        foreach ($this->hooks as $hook) {
            if ($hook->getPhase() !== $phase) {
                continue;
            }

            $start = Stopwatch::start();

            try {
                $result = $hook->execute();
            } catch (\Throwable $e) {
                $durationMs = Stopwatch::elapsedMs($start);
                $result = DeployHookResult::fail(
                    $hook->getName(),
                    $durationMs,
                    'Hook threw an unexpected exception: ' . $e->getMessage(),
                );
            }

            $results[] = $result;
        }

        return $results;
    }
}
