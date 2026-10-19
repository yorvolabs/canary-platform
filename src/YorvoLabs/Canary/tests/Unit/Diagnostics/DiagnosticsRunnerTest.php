<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Contract\DiagnosticsInterface;
use YorvoLabs\Canary\Diagnostics\DiagnosticsResult;
use YorvoLabs\Canary\Diagnostics\DiagnosticsRunner;

final class DiagnosticsRunnerTest extends TestCase
{
    #[Test]
    public function it_returns_empty_list_when_no_checks_registered(): void
    {
        $runner = new DiagnosticsRunner();

        self::assertSame([], $runner->run());
    }

    #[Test]
    public function it_returns_results_in_registration_order(): void
    {
        $runner = new DiagnosticsRunner([
            $this->makeCheck('first', DiagnosticsResult::pass('first')),
            $this->makeCheck('second', DiagnosticsResult::fail('second', 'broken')),
            $this->makeCheck('third', DiagnosticsResult::pass('third', 'all good')),
        ]);

        $results = $runner->run();

        self::assertCount(3, $results);
        self::assertSame('first', $results[0]->name);
        self::assertSame('second', $results[1]->name);
        self::assertSame('third', $results[2]->name);
    }

    #[Test]
    public function it_records_pass_and_fail_correctly(): void
    {
        $runner = new DiagnosticsRunner([
            $this->makeCheck('ok', DiagnosticsResult::pass('ok', 'all good')),
            $this->makeCheck('broken', DiagnosticsResult::fail('broken', 'something wrong')),
        ]);

        $results = $runner->run();

        self::assertTrue($results[0]->pass);
        self::assertFalse($results[1]->pass);
        self::assertSame('all good', $results[0]->message);
        self::assertSame('something wrong', $results[1]->message);
    }

    #[Test]
    public function it_catches_exceptions_and_records_as_failure(): void
    {
        $throwing = $this->createStub(DiagnosticsInterface::class);
        $throwing->method('getName')->willReturn('exploding');
        $throwing->method('execute')->willThrowException(new \RuntimeException('boom'));

        $runner = new DiagnosticsRunner([$throwing]);
        $results = $runner->run();

        self::assertCount(1, $results);
        self::assertFalse($results[0]->pass);
        self::assertStringContainsString('boom', (string) $results[0]->message);
    }

    #[Test]
    public function it_continues_running_remaining_checks_after_exception(): void
    {
        $throwing = $this->createStub(DiagnosticsInterface::class);
        $throwing->method('getName')->willReturn('broken');
        $throwing->method('execute')->willThrowException(new \RuntimeException('fail'));

        $runner = new DiagnosticsRunner([
            $throwing,
            $this->makeCheck('subsequent', DiagnosticsResult::pass('subsequent')),
        ]);

        $results = $runner->run();

        self::assertCount(2, $results);
        self::assertSame('subsequent', $results[1]->name);
        self::assertTrue($results[1]->pass);
    }

    private function makeCheck(string $name, DiagnosticsResult $result): DiagnosticsInterface
    {
        $check = $this->createStub(DiagnosticsInterface::class);
        $check->method('getName')->willReturn($name);
        $check->method('execute')->willReturn($result);
        return $check;
    }
}
