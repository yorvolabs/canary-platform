<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Contract;

use YorvoLabs\Canary\Audit\AuditRecord;

/**
 * Receives a structured audit record for every Canary request.
 *
 * The Shopware bridge ships MonologAuditLogger (writes to the canary.audit
 * Monolog channel). Swap it out in DI for any other destination.
 */
interface AuditLoggerInterface
{
    public function log(AuditRecord $record): void;
}
