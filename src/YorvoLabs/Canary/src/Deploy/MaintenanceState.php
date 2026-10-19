<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Deploy;

final class MaintenanceState
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string|null $reason = null,
    ) {}

    /** @return array{enabled: bool, reason: string|null} */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'reason'  => $this->reason,
        ];
    }
}
