<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\PostDeployController;
use YorvoLabs\Canary\Contract\HealthCheckInterface;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Health\HealthCheckResult;
use YorvoLabs\Canary\Health\HealthCheckRunner;
use YorvoLabs\Canary\Response\ResponseFactory;

final class PostDeployControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    private function passingHealthCheck(): HealthCheckInterface
    {
        $check = $this->createStub(HealthCheckInterface::class);
        $check->method('getName')->willReturn('db');
        $check->method('execute')->willReturn(HealthCheckResult::pass('db', 1));
        return $check;
    }

    #[Test]
    public function it_returns_all_three_steps(): void
    {
        $pool   = $this->createStub(CacheItemPoolInterface::class);
        $runner = new HealthCheckRunner([$this->passingHealthCheck()]);

        $controller = new PostDeployController($this->responseFactory, $runner, $pool, null, new DeployHookRunner());
        $response   = $controller(Request::create('/canary/deploy/post', 'POST'));

        self::assertSame(200, $response->getStatusCode());
        $body  = json_decode((string) $response->getContent(), true);
        $names = array_column($body['data']['steps'], 'name');

        self::assertContains('opcache_reset', $names);
        self::assertContains('cache_clear', $names);
        self::assertContains('readiness_check', $names);
    }

    #[Test]
    public function it_reports_overall_pass_when_all_steps_succeed(): void
    {
        $pool   = $this->createStub(CacheItemPoolInterface::class);
        $runner = new HealthCheckRunner([$this->passingHealthCheck()]);

        $controller = new PostDeployController($this->responseFactory, $runner, $pool, null, new DeployHookRunner());
        $body       = json_decode((string) $controller(Request::create('/canary/deploy/post', 'POST'))->getContent(), true);

        self::assertTrue($body['data']['pass']);
    }

    #[Test]
    public function it_reports_overall_fail_when_cache_clear_throws(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('clear')->willThrowException(new \RuntimeException('Redis down'));

        $runner = new HealthCheckRunner([$this->passingHealthCheck()]);

        $controller = new PostDeployController($this->responseFactory, $runner, $pool, null, new DeployHookRunner());
        $body       = json_decode((string) $controller(Request::create('/canary/deploy/post', 'POST'))->getContent(), true);

        self::assertFalse($body['data']['pass']);

        $cacheStep = $this->findStep($body['data']['steps'], 'cache_clear');
        self::assertFalse($cacheStep['pass']);
    }

    #[Test]
    public function it_clears_http_pool_when_wired(): void
    {
        $objectPool = $this->createMock(CacheItemPoolInterface::class);
        $objectPool->expects(self::once())->method('clear')->willReturn(true);

        $httpPool = $this->createMock(CacheItemPoolInterface::class);
        $httpPool->expects(self::once())->method('clear')->willReturn(true);

        $runner     = new HealthCheckRunner([$this->passingHealthCheck()]);
        $controller = new PostDeployController($this->responseFactory, $runner, $objectPool, $httpPool, new DeployHookRunner());
        $body       = json_decode((string) $controller(Request::create('/canary/deploy/post', 'POST'))->getContent(), true);

        $step = $this->findStep($body['data']['steps'], 'cache_clear');
        self::assertContains('object', $step['detail']['pools_cleared']);
        self::assertContains('http', $step['detail']['pools_cleared']);
    }

    #[Test]
    public function each_step_includes_duration_ms(): void
    {
        $pool   = $this->createStub(CacheItemPoolInterface::class);
        $runner = new HealthCheckRunner([$this->passingHealthCheck()]);

        $body = json_decode((string) (new PostDeployController($this->responseFactory, $runner, $pool, null, new DeployHookRunner()))(Request::create('/canary/deploy/post', 'POST'))->getContent(), true);

        foreach ($body['data']['steps'] as $step) {
            self::assertArrayHasKey('duration_ms', $step);
            self::assertIsInt($step['duration_ms']);
        }
    }

    /** @param list<array<string, mixed>> $steps */
    private function findStep(array $steps, string $name): mixed
    {
        foreach ($steps as $step) {
            if ($step['name'] === $name) {
                return $step;
            }
        }
        self::fail("Step '{$name}' not found in response");
    }
}
