<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Health;

use Psr\Cache\CacheItemPoolInterface;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Verifies the cache adapter (Redis / Memcached / APCu) with a write–read–delete
 * round-trip using a per-execution probe key.
 *
 * The probe key includes a random suffix to prevent concurrent health checks
 * (multiple PHP workers calling this simultaneously) from interfering with each
 * other's read-back validation. The 10-second TTL ensures cleanup even if a
 * worker crashes mid-probe.
 *
 * A FAIL here means Shopware's object cache is down — sessions, entity cache,
 * and plugin config will fall back to slow database reads or fail entirely.
 */
final class CacheAdapterCheck implements HealthCheckInterface
{
    private const PROBE_KEY_PREFIX = 'canary_health_probe_';
    private const PROBE_VALUE = 'ok';
    private const PROBE_TTL = 10;

    public function __construct(
        private readonly CacheItemPoolInterface $cachePool,
    ) {}

    public function getName(): string
    {
        return 'cache_adapter';
    }

    public function execute(): HealthCheckResult
    {
        $start = Stopwatch::start();
        $probeKey = self::PROBE_KEY_PREFIX . bin2hex(random_bytes(4));

        try {
            $item = $this->cachePool->getItem($probeKey);
            $item->set(self::PROBE_VALUE);
            $item->expiresAfter(self::PROBE_TTL);
            $this->cachePool->save($item);

            $readback = $this->cachePool->getItem($probeKey);

            if (!$readback->isHit()) {
                throw new \RuntimeException('Cache read-back: key not found after write.');
            }

            if ($readback->get() !== self::PROBE_VALUE) {
                throw new \RuntimeException('Cache read-back: unexpected value stored.');
            }

            $this->cachePool->deleteItem($probeKey);

            $durationMs = Stopwatch::elapsedMs($start);

            return HealthCheckResult::pass($this->getName(), $durationMs);
        } catch (\Throwable $e) {
            $durationMs = Stopwatch::elapsedMs($start);

            return HealthCheckResult::fail(
                $this->getName(),
                $durationMs,
                'Cache adapter unreachable: ' . $e->getMessage(),
            );
        }
    }
}
