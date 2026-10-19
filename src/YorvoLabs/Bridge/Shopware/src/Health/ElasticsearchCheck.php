<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Health;

use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Util\Stopwatch;

/**
 * Probes Elasticsearch / OpenSearch reachability.
 *
 * This check is always registered and always appears in readiness results.
 * When SHOPWARE_ES_HOSTS is empty it skips TCP probing and returns PASS with
 * a not_configured=true detail flag — it does not remove itself from results.
 *
 * The probe connects a TCP socket to the first host in the comma-separated
 * list — cheap, no HTTP overhead, catches the most common failure modes
 * (server down, wrong port, network policy). Cluster-level health status
 * (yellow / red shards) is NOT checked here; that belongs in a monitoring
 * dashboard rather than a binary readiness probe.
 *
 * IPv6: bracketed notation [::1]:9200 is supported. Bare IPv6 without brackets
 * is ambiguous and not supported — use the bracketed form in SHOPWARE_ES_HOSTS.
 */
final class ElasticsearchCheck implements HealthCheckInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 3.0;

    public function __construct(
        private readonly string $hosts,
    ) {}

    public function getName(): string
    {
        return 'elasticsearch';
    }

    public function execute(): HealthCheckResult
    {
        if ($this->hosts === '') {
            return HealthCheckResult::pass($this->getName(), 0, ['not_configured' => true]);
        }

        $start = Stopwatch::start();

        try {
            [$host, $port] = $this->parseFirstHost($this->hosts);

            $socket = @fsockopen($host, $port, $errno, $errstr, self::CONNECT_TIMEOUT_SECONDS);
            $durationMs = Stopwatch::elapsedMs($start);

            if ($socket === false) {
                return HealthCheckResult::fail(
                    $this->getName(),
                    $durationMs,
                    "Cannot connect to Elasticsearch at {$host}:{$port} — {$errstr} ({$errno})",
                    ['host' => $host, 'port' => $port],
                );
            }

            fclose($socket);

            return HealthCheckResult::pass(
                $this->getName(),
                $durationMs,
                ['host' => $host, 'port' => $port],
            );
        } catch (\Throwable $e) {
            $durationMs = Stopwatch::elapsedMs($start);

            return HealthCheckResult::fail(
                $this->getName(),
                $durationMs,
                'Elasticsearch check failed: ' . $e->getMessage(),
            );
        }
    }

    /**
     * Extracts host + port from the first entry of a comma-separated hosts string.
     * Accepts: "host:port", "http://host:port", "https://host:port", "[::1]:9200".
     *
     * @return array{string, int}
     */
    private function parseFirstHost(string $hosts): array
    {
        $first = trim(explode(',', $hosts)[0]);

        // Strip scheme if present.
        $first = preg_replace('#^https?://#', '', $first) ?? $first;

        // Strip path if present.
        $first = explode('/', $first)[0];

        // Bracketed IPv6: [::1] or [::1]:9200
        if (str_starts_with($first, '[')) {
            $close = strpos($first, ']');
            if ($close === false) {
                return [ltrim($first, '['), 9200];
            }
            $host = substr($first, 1, $close - 1);
            $port = isset($first[$close + 1]) ? (int) substr($first, $close + 2) : 9200;
            return [$host, $port];
        }

        // Plain host:port
        if (str_contains($first, ':')) {
            [$host, $portStr] = explode(':', $first, 2);
            return [$host, (int) $portStr];
        }

        return [$first, 9200];
    }
}
