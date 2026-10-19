<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Deploy\MaintenanceState;

final class MaintenanceStateTest extends TestCase
{
    #[Test]
    public function it_reports_enabled_state(): void
    {
        $state = new MaintenanceState(enabled: true, reason: 'Deploying v2.0');

        self::assertTrue($state->enabled);
        self::assertSame('Deploying v2.0', $state->reason);
    }

    #[Test]
    public function it_reports_disabled_state(): void
    {
        $state = new MaintenanceState(enabled: false);

        self::assertFalse($state->enabled);
        self::assertNull($state->reason);
    }

    #[Test]
    public function it_serializes_to_array(): void
    {
        $state = new MaintenanceState(enabled: true, reason: 'Planned maintenance');

        self::assertSame([
            'enabled' => true,
            'reason'  => 'Planned maintenance',
        ], $state->toArray());
    }

    #[Test]
    public function it_serializes_null_reason_to_array(): void
    {
        $state = new MaintenanceState(enabled: false);

        self::assertSame([
            'enabled' => false,
            'reason'  => null,
        ], $state->toArray());
    }
}
