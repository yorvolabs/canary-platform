<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * GET {prefix}/diag/queue
 *
 * Reports the depth and oldest-message age for each queue, the failed message
 * count and timestamp of the oldest failure, and a best-effort worker detection
 * result.
 *
 * Requires DIAGNOSTICS scope token.
 */
#[Route(
    '/diag/queue',
    name: 'canary.diag.queue',
    methods: ['GET'],
    defaults: ['_routeScope' => ['api'], 'auth_required' => false]
)]
final class QueueController
{
    public function __construct(
        private readonly ResponseFactory $responseFactory,
        private readonly Connection $connection,
    ) {}

    public function __invoke(Request $request): Response
    {
        $requestId = $request->attributes->getString('_canary_request_id') ?: null;

        return $this->responseFactory->ok([
            'queues'  => $this->buildQueueInfo(),
            'failed'  => $this->buildFailedInfo(),
            'workers' => $this->detectWorkers(),
        ], $requestId);
    }

    /** @return list<array<string, mixed>> */
    private function buildQueueInfo(): array
    {
        try {
            $names = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT queue_name FROM messenger_messages WHERE queue_name != 'failed' ORDER BY queue_name",
            );
        } catch (\Throwable) {
            return [];
        }

        $queues = [];
        foreach ($names as $name) {
            $queues[] = $this->fetchQueueStats((string) $name);
        }

        return $queues;
    }

    /** @return array<string, mixed> */
    private function fetchQueueStats(string $queueName): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                'SELECT COUNT(*) AS depth, MIN(available_at) AS oldest
                 FROM messenger_messages
                 WHERE queue_name = ?
                   AND available_at <= NOW()
                   AND delivered_at IS NULL',
                [$queueName],
            );

            $depth      = (int) ($row['depth'] ?? 0);
            $oldestAge  = null;

            if ($depth > 0 && isset($row['oldest'])) {
                $oldestAt  = new \DateTimeImmutable((string) $row['oldest']);
                $oldestAge = max(0, (int) (time() - $oldestAt->getTimestamp()));
            }
        } catch (\Throwable) {
            $depth     = 0;
            $oldestAge = null;
        }

        return [
            'name'               => $queueName,
            'depth'              => $depth,
            'oldest_age_seconds' => $oldestAge,
        ];
    }

    /** @return array<string, mixed> */
    private function buildFailedInfo(): array
    {
        try {
            $row = $this->connection->fetchAssociative(
                "SELECT COUNT(*) AS cnt, MIN(created_at) AS oldest
                 FROM messenger_messages
                 WHERE queue_name = 'failed'",
            );

            $count    = (int) ($row['cnt'] ?? 0);
            $oldestAt = null;

            if ($count > 0 && isset($row['oldest'])) {
                $dt       = new \DateTimeImmutable((string) $row['oldest']);
                $oldestAt = $dt->format(\DateTimeInterface::ATOM);
            }
        } catch (\Throwable) {
            $count    = 0;
            $oldestAt = null;
        }

        return [
            'count'            => $count,
            'oldest_failed_at' => $oldestAt,
        ];
    }

    /** @return array<string, mixed> */
    private function detectWorkers(): array
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            return [
                'detected' => false,
                'note'     => 'Worker detection not available on this platform',
            ];
        }

        $detected = false;
        $cmdFiles = glob('/proc/*/cmdline');

        if (is_array($cmdFiles)) {
            foreach ($cmdFiles as $cmdFile) {
                $cmd = @file_get_contents($cmdFile);
                if ($cmd !== false && str_contains($cmd, 'messenger:consume')) {
                    $detected = true;
                    break;
                }
            }
        }

        return [
            'detected' => $detected,
            'note'     => $detected
                ? 'At least one messenger:consume worker process detected'
                : 'No messenger:consume worker processes detected',
        ];
    }
}
