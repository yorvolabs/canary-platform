<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Repository;

use Doctrine\DBAL\Connection;

/**
 * Single source of truth for messenger_messages count queries.
 *
 * Both methods throw on database error — callers decide whether an exception
 * means "treat as zero" (metrics/deploy checks) or "transport unreachable" (health check).
 */
final class MessengerQueueRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function fetchPendingCount(): int
    {
        $result = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages
             WHERE queue_name != 'failed'
               AND available_at <= NOW()
               AND delivered_at IS NULL",
        );

        return is_numeric($result) ? (int) $result : 0;
    }

    public function fetchFailedCount(): int
    {
        $result = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'",
        );

        return is_numeric($result) ? (int) $result : 0;
    }
}
