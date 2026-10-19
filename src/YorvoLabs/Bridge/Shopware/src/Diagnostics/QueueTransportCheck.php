<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Diagnostics;

use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

final class QueueTransportCheck implements DiagnosticsInterface
{
    public function __construct(private readonly string $messengerTransportDsn = '') {}

    public function getName(): string
    {
        return 'queue_transport';
    }

    public function execute(): DiagnosticsResult
    {
        $dsn = $this->messengerTransportDsn;

        if ($dsn === '') {
            return DiagnosticsResult::pass($this->getName(), 'MESSENGER_TRANSPORT_DSN is not set');
        }

        if (str_starts_with($dsn, 'sync://')) {
            return DiagnosticsResult::fail(
                $this->getName(),
                'Messenger transport is sync:// — messages are processed synchronously, which blocks requests',
            );
        }

        return DiagnosticsResult::pass($this->getName(), 'Async transport configured');
    }
}
