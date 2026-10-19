<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Bridge\Shopware\Diagnostics\MailerTransportCheck;

final class MailerTransportCheckTest extends TestCase
{
    #[Test]
    public function it_has_stable_name(): void
    {
        self::assertSame('mailer_transport', (new MailerTransportCheck())->getName());
    }

    #[Test]
    public function it_fails_when_dsn_is_null_null(): void
    {
        $result = (new MailerTransportCheck('null://null'))->execute();

        self::assertFalse($result->pass);
    }

    #[Test]
    public function it_does_not_throw_when_dsn_is_empty(): void
    {
        // Empty string → falls through to getenv(); check it completes without exception
        $result = (new MailerTransportCheck(''))->execute();
        self::assertNotEmpty($result->name);
    }

    #[Test]
    public function it_passes_when_smtp_is_configured(): void
    {
        $result = (new MailerTransportCheck('smtp://user:pass@mail.example.com:587'))->execute();

        self::assertTrue($result->pass);
    }

    #[Test]
    public function it_passes_for_sendmail_transport(): void
    {
        $result = (new MailerTransportCheck('sendmail://default'))->execute();

        self::assertTrue($result->pass);
    }
}
