<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Diagnostics;

use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;

final class MailerTransportCheck implements DiagnosticsInterface
{
    public function __construct(private readonly string $mailerDsn = '') {}

    public function getName(): string
    {
        return 'mailer_transport';
    }

    public function execute(): DiagnosticsResult
    {
        $dsn = $this->mailerDsn;

        if ($dsn === '' || $dsn === 'null://null') {
            return DiagnosticsResult::fail(
                $this->getName(),
                'MAILER_DSN is not set or is null — transactional emails will not be delivered',
            );
        }

        return DiagnosticsResult::pass($this->getName(), 'Mailer transport configured');
    }
}
