<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthRunResult;
use YorvoLabs\Canary\Health\HealthStatus;
use YorvoLabs\Canary\Response\HealthResponse;

final class HealthResponseTest extends TestCase
{
    #[Test]
    public function it_includes_status_from_run_result(): void
    {
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::PASS, []),
            'canary-test-id',
            10,
        );

        self::assertSame('pass', $response->toArray()['status']);
    }

    #[Test]
    public function it_reflects_warn_status(): void
    {
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::WARN, []),
            'canary-test-id',
            5,
        );

        self::assertSame('warn', $response->toArray()['status']);
    }

    #[Test]
    public function it_reflects_fail_status(): void
    {
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::FAIL, []),
            'canary-test-id',
            5,
        );

        self::assertSame('fail', $response->toArray()['status']);
    }

    #[Test]
    public function it_maps_checks_to_array(): void
    {
        $check = HealthCheckResult::pass('database', 3);
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::PASS, [$check]),
            'canary-test-id',
            10,
        );

        $data = $response->toArray();
        self::assertCount(1, $data['checks']);
        self::assertSame('database', $data['checks'][0]['name']);
        self::assertSame('pass', $data['checks'][0]['status']);
        self::assertSame(3, $data['checks'][0]['duration_ms']);
    }

    #[Test]
    public function it_includes_meta_with_request_id_and_duration(): void
    {
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::PASS, []),
            'canary-abc123',
            42,
        );

        $data = $response->toArray();
        self::assertSame('canary-abc123', $data['meta']['request_id']);
        self::assertSame(42, $data['meta']['duration_ms']);
    }

    #[Test]
    public function it_returns_empty_checks_array_when_no_checks_registered(): void
    {
        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::PASS, []),
            'canary-test-id',
            1,
        );

        self::assertSame([], $response->toArray()['checks']);
    }

    #[Test]
    public function it_preserves_check_order(): void
    {
        $checks = [
            HealthCheckResult::pass('database', 2),
            HealthCheckResult::warn('cache', 5, 'slow response'),
            HealthCheckResult::fail('queue', 1, 'connection refused'),
        ];

        $response = HealthResponse::fromRunResult(
            new HealthRunResult(HealthStatus::FAIL, $checks),
            'canary-test-id',
            8,
        );

        $names = array_column($response->toArray()['checks'], 'name');
        self::assertSame(['database', 'cache', 'queue'], $names);
    }
}
