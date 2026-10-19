<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Audit;

use Psr\Log\LoggerInterface;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;

/**
 * Writes audit records to the canary_audit Monolog channel.
 *
 * Every Canary request — authenticated or not — produces one record.
 * The channel is configured with 30-day log rotation in monolog.xml.
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
