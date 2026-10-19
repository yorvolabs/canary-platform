<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Util;

final class Stopwatch
{
    public static function start(): int
    {
        return hrtime(true);
    }

    public static function elapsedMs(int $startNs): int
    {
        return (int) round((hrtime(true) - $startNs) / 1_000_000);
    }
}
