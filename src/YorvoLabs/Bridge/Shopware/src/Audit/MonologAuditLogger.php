<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Audit;

use Psr\Log\LoggerInterface;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;

/**
 * Writes audit records to the canary_audit Monolog channel.
 *
 * Every main Canary request that reaches the HTTPS check or later produces one
 * record (see RequestGuardSubscriber for what is not audited).
 *
 * By default the plugin writes the channel to var/log/canary_audit.log with a
 * plain stream handler: no rotation, no retention limit. Rotation (for example
 * 30 daily files) is configuration you add in monolog.yaml; monolog.xml has an
 * example.
 *
 * The logger is injected via the monolog.logger tag in monolog.xml, which
 * causes Symfony to replace the generic $logger argument with the
 * canary_audit channel instance (monolog.logger.canary_audit).
 */
final class MonologAuditLogger implements AuditLoggerInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function log(AuditRecord $record): void
    {
        $this->logger->info(
            sprintf('[canary] %s %s → HTTP %d', $record->method, $record->endpoint, $record->statusCode),
            $record->toArray(),
        );
    }
}
