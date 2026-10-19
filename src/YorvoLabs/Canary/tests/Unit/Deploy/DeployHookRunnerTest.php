<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Contract\DeployHookInterface;
use YorvoLabs\Canary\Deploy\DeployHookResult;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Deploy\DeployPhase;

final class DeployHookRunnerTest extends TestCase
{
    private function makeHook(string $name, DeployPhase $phase, bool $pass): DeployHookInterface
    {
        $hook = $this->createStub(DeployHookInterface::class);
        $hook->method('getName')->willReturn($name);
        $hook->method('getPhase')->willReturn($phase);
        $hook->method('execute')->willReturn(
            $pass
                ? DeployHookResult::pass($name, 0)
                : DeployHookResult::fail($name, 0, 'hook failed'),
        );
        return $hook;
    }

    #[Test]
    public function it_returns_empty_result_when_no_hooks_are_registered(): void
    {
        $runner = new DeployHookRunner();

        self::assertSame([], $runner->run(DeployPhase::PRE));
        self::assertSame([], $runner->run(DeployPhase::POST));
    }

    #[Test]
    public function it_runs_only_hooks_for_the_requested_phase(): void
    {
        $runner = new DeployHookRunner([
            $this->makeHook('pre_hook', DeployPhase::PRE, true),
            $this->makeHook('post_hook', DeployPhase::POST, true),
        ]);

        $preResults  = $runner->run(DeployPhase::PRE);
        $postResults = $runner->run(DeployPhase::POST);

        self::assertCount(1, $preResults);
        self::assertSame('pre_hook', $preResults[0]->name);

        self::assertCount(1, $postResults);
        self::assertSame('post_hook', $postResults[0]->name);
    }

    #[Test]
    public function it_records_pass_and_fail_results(): void
    {
        $runner = new DeployHookRunner([
            $this->makeHook('hook_pass', DeployPhase::PRE, true),
            $this->makeHook('hook_fail', DeployPhase::PRE, false),
        ]);

        $results = $runner->run(DeployPhase::PRE);

        self::assertCount(2, $results);
        self::assertTrue($results[0]->pass);
        self::assertFalse($results[1]->pass);
        self::assertSame('hook failed', $results[1]->message);
    }

    #[Test]
    public function it_catches_unexpected_exceptions_and_records_fail(): void
    {
        $hook = $this->createStub(DeployHookInterface::class);
        $hook->method('getName')->willReturn('broken_hook');
        $hook->method('getPhase')->willReturn(DeployPhase::PRE);
        $hook->method('execute')->willThrowException(new \RuntimeException('boom'));

        $runner  = new DeployHookRunner([$hook]);
        $results = $runner->run(DeployPhase::PRE);

        self::assertCount(1, $results);
        self::assertFalse($results[0]->pass);
        self::assertStringContainsString('boom', (string) $results[0]->message);
    }

    #[Test]
    public function it_includes_non_negative_duration_ms_in_results(): void
    {
        $runner  = new DeployHookRunner([$this->makeHook('hook', DeployPhase::PRE, true)]);
        $results = $runner->run(DeployPhase::PRE);

        self::assertGreaterThanOrEqual(0, $results[0]->durationMs);
    }
}
