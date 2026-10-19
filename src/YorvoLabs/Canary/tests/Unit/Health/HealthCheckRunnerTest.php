<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Health;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Health\HealthStatus;

final class HealthCheckRunnerTest extends TestCase
{
    private function makeCheck(string $name, HealthStatus $status, ?string $error = null): HealthCheckInterface
    {
        return new class ($name, $status, $error) implements HealthCheckInterface {
            public function __construct(
                private readonly string $name,
                private readonly HealthStatus $status,
                private readonly ?string $error,
            ) {}
            public function getName(): string
            {
                return $this->name;
            }
            public function execute(): HealthCheckResult
            {
                return match ($this->status) {
                    HealthStatus::PASS => HealthCheckResult::pass($this->name, 1),
                    HealthStatus::WARN => HealthCheckResult::warn($this->name, 1, $this->error ?? 'warn'),
                    HealthStatus::FAIL => HealthCheckResult::fail($this->name, 1, $this->error ?? 'fail'),
                };
            }
        };
    }

    private function makeThrowingCheck(string $name): HealthCheckInterface
    {
        return new class ($name) implements HealthCheckInterface {
            public function __construct(private readonly string $name) {}
            public function getName(): string
            {
                return $this->name;
            }
            public function execute(): HealthCheckResult
            {
                throw new \RuntimeException('Unexpected failure');
            }
        };
    }

    #[Test]
    public function it_returns_pass_when_all_checks_pass(): void
    {
        $runner = new HealthCheckRunner([$this->makeCheck('db', HealthStatus::PASS), $this->makeCheck('cache', HealthStatus::PASS)]);
        $result = $runner->run();
        self::assertSame(HealthStatus::PASS, $result->status);
        self::assertCount(2, $result->results);
    }

    #[Test]
    public function it_returns_fail_when_any_check_fails(): void
    {
        $runner = new HealthCheckRunner([$this->makeCheck('db', HealthStatus::PASS), $this->makeCheck('cache', HealthStatus::FAIL, 'Redis unreachable')]);
        self::assertSame(HealthStatus::FAIL, $runner->run()->status);
        self::assertSame(503, $runner->run()->status->httpStatus());
    }

    #[Test]
    public function it_returns_warn_when_only_warn_checks_degrade(): void
    {
        $runner = new HealthCheckRunner([$this->makeCheck('db', HealthStatus::PASS), $this->makeCheck('es', HealthStatus::WARN, 'Slow')]);
        self::assertSame(HealthStatus::WARN, $runner->run()->status);
        self::assertSame(200, $runner->run()->status->httpStatus());
    }

    #[Test]
    public function it_returns_fail_when_fail_and_warn_are_mixed(): void
    {
        $runner = new HealthCheckRunner([$this->makeCheck('db', HealthStatus::FAIL), $this->makeCheck('es', HealthStatus::WARN)]);
        self::assertSame(HealthStatus::FAIL, $runner->run()->status);
    }

    #[Test]
    public function it_catches_thrown_exceptions_and_records_as_fail(): void
    {
        $runner = new HealthCheckRunner([$this->makeCheck('db', HealthStatus::PASS), $this->makeThrowingCheck('broken')]);
        $result = $runner->run();
        self::assertSame(HealthStatus::FAIL, $result->status);
        $broken = array_values(array_filter($result->results, fn($r) => $r->name === 'broken'))[0];
        self::assertSame(HealthStatus::FAIL, $broken->status);
        self::assertNotNull($broken->error);
        self::assertStringContainsString('unexpected exception', $broken->error);
    }

    #[Test]
    public function it_returns_pass_with_no_checks_registered(): void
    {
        $result = (new HealthCheckRunner())->run();
        self::assertSame(HealthStatus::PASS, $result->status);
        self::assertSame([], $result->results);
    }

    #[Test]
    public function it_preserves_result_order(): void
    {
        $runner = new HealthCheckRunner([
            $this->makeCheck('db', HealthStatus::PASS),
            $this->makeCheck('cache', HealthStatus::WARN, 'slow'),
            $this->makeCheck('fs', HealthStatus::PASS),
        ]);
        $names = array_map(fn($r) => $r->name, $runner->run()->results);
        self::assertSame(['db', 'cache', 'fs'], $names);
    }
}
