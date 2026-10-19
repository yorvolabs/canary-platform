<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Health;

use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Configuration\DeployThreshold;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Checks message queue reachability and depth against configured thresholds.
 *
 * Returns FAIL when the messenger_messages table is unreachable (transport down).
 * Returns WARN when queue depth or failed-message count exceeds the configured
 * threshold — the shop is degraded but still serving requests.
 * Returns PASS when both counts are within their thresholds.
 *
 * Thresholds are read from Options:
 *   CANARY_DEPLOY_QUEUE_THRESHOLD  (default 100) — pending message limit
 *   CANARY_DEPLOY_FAILED_THRESHOLD (default 0)   — dead-letter limit
 */
final class MessageQueueCheck implements HealthCheckInterface
{
    public function __construct(
        private readonly MessengerQueueRepository $queueRepository,
        private readonly Options $options,
    ) {}

    public function getName(): string
    {
        return 'message_queue';
    }

    public function execute(): HealthCheckResult
    {
        $start = Stopwatch::start();

        try {
            $depth  = $this->queueRepository->fetchPendingCount();
            $failed = $this->queueRepository->fetchFailedCount();
        } catch (\Throwable $e) {
            return HealthCheckResult::fail(
                $this->getName(),
                Stopwatch::elapsedMs($start),
                'Queue transport unreachable: ' . $e->getMessage(),
            );
        }

        $durationMs      = Stopwatch::elapsedMs($start);
        $queueThreshold  = $this->options->getDeployThreshold(DeployThreshold::QUEUE);
        $failedThreshold = $this->options->getDeployThreshold(DeployThreshold::FAILED);
        $detail          = ['depth' => $depth, 'failed' => $failed, 'queue_threshold' => $queueThreshold, 'failed_threshold' => $failedThreshold];

        if ($failed > $failedThreshold) {
            return HealthCheckResult::warn(
                $this->getName(),
                $durationMs,
                "Dead-letter count ({$failed}) exceeds threshold ({$failedThreshold})",
                $detail,
            );
        }

        if ($depth > $queueThreshold) {
            return HealthCheckResult::warn(
                $this->getName(),
                $durationMs,
                "Queue depth ({$depth}) exceeds threshold ({$queueThreshold})",
                $detail,
            );
        }

        return HealthCheckResult::pass($this->getName(), $durationMs, $detail);
    }
}
