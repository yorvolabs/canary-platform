<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Functional\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Auth\IpAllowlistChecker;
use YorvoLabs\Canary\Auth\RequestGuardSubscriber;
use YorvoLabs\Canary\Auth\ScopedToken;
use YorvoLabs\Canary\Auth\TokenAuthenticator;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\AdapterFlushController;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use YorvoLabs\Canary\RateLimit\InMemoryRateLimiter;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Functional tests: scope enforcement for bridge-specific route groups
 * and the AdapterFlushController secondary ADMIN guard.
 *
 * These tests build a minimal Symfony HttpKernel (no Shopware dependency) so
 * they can run in a standard dev environment with just yorvolabs/canary installed.
 */
final class ScopeEnforcementTest extends TestCase
{
    private const MONITORING_TOKEN   = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN        = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DEPLOY_TOKEN       = 'deploy-token-ddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const ADMIN_TOKEN        = 'admin-token-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const DIAGNOSTICS_TOKEN  = 'diagnostics-token-fffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function buildKernel(): HttpKernel
    {
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::MONITORING->value   => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value        => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
            TokenScope::DEPLOY->value       => ScopedToken::fromEnv(TokenScope::DEPLOY, self::DEPLOY_TOKEN),
            TokenScope::ADMIN->value        => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
            TokenScope::DIAGNOSTICS->value  => ScopedToken::fromEnv(TokenScope::DIAGNOSTICS, self::DIAGNOSTICS_TOKEN),
        ];

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray(['APP_ENV' => 'dev']),
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            $this->responseFactory,
            new class implements AuditLoggerInterface {
                public function log(AuditRecord $record): void {}
            },
        );

        $dispatcher->addSubscriber($subscriber);
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            fn(RequestEvent $e) => $e->setResponse(new Response('OK', 200)),
            priority: -100,
        );

        return new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());
    }

    private function handle(HttpKernel $kernel, string $path, string $token, string $method = 'GET'): Response
    {
        $request = Request::create('http://example.com' . $path, $method);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    // ------------------------------------------------------------------
    // Scope matrix: correct token accepted, wrong token returns 403
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('correctScopeMatrix')]
    public function correct_scope_is_accepted(string $path, string $token): void
    {
        self::assertSame(200, $this->handle($this->buildKernel(), $path, $token)->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function correctScopeMatrix(): array
    {
        return [
            '/canary/health/live with MONITORING'    => ['/canary/health/live',      self::MONITORING_TOKEN],
            '/canary/health/ready with MONITORING'   => ['/canary/health/ready',     self::MONITORING_TOKEN],
            '/canary/health/metrics with MONITORING' => ['/canary/health/metrics',   self::MONITORING_TOKEN],
            '/canary/cache/opcache with CACHE'       => ['/canary/cache/opcache',    self::CACHE_TOKEN],
            '/canary/cache/shopware with CACHE'      => ['/canary/cache/shopware',   self::CACHE_TOKEN],
            '/canary/cache/adapters with ADMIN'      => ['/canary/cache/adapters',   self::ADMIN_TOKEN],
            '/canary/deploy/pre with DEPLOY'              => ['/canary/deploy/pre',       self::DEPLOY_TOKEN],
            '/canary/deploy/maintenance with DEPLOY'      => ['/canary/deploy/maintenance', self::DEPLOY_TOKEN],
            '/canary/diag/env with DIAGNOSTICS'           => ['/canary/diag/env',     self::DIAGNOSTICS_TOKEN],
            '/canary/diag/logs with DIAGNOSTICS'          => ['/canary/diag/logs',    self::DIAGNOSTICS_TOKEN],
            '/canary/diag/queue with DIAGNOSTICS'         => ['/canary/diag/queue',   self::DIAGNOSTICS_TOKEN],
            '/canary/diag/plugins with DIAGNOSTICS'       => ['/canary/diag/plugins', self::DIAGNOSTICS_TOKEN],
        ];
    }

    #[Test]
    #[DataProvider('wrongScopeMatrix')]
    public function wrong_scope_returns_403(string $path, string $token): void
    {
        self::assertSame(403, $this->handle($this->buildKernel(), $path, $token)->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function wrongScopeMatrix(): array
    {
        return [
            'CACHE token on /canary/health/live'     => ['/canary/health/live',    self::CACHE_TOKEN],
            'MONITORING token on /canary/cache/...'  => ['/canary/cache/opcache',  self::MONITORING_TOKEN],
            'CACHE token on /canary/deploy/pre'          => ['/canary/deploy/pre',     self::CACHE_TOKEN],
            'MONITORING token on /canary/diag/env'       => ['/canary/diag/env',      self::MONITORING_TOKEN],
            'DEPLOY token on /canary/diag/logs'          => ['/canary/diag/logs',     self::DEPLOY_TOKEN],
        ];
    }

    #[Test]
    #[DataProvider('allPaths')]
    public function admin_token_accepted_on_all_paths(string $path): void
    {
        self::assertSame(200, $this->handle($this->buildKernel(), $path, self::ADMIN_TOKEN)->getStatusCode());
    }

    /** @return array<int, array{string}> */
    public static function allPaths(): array
    {
        return [
            ['/canary/health/live'],
            ['/canary/health/ready'],
            ['/canary/health/metrics'],
            ['/canary/cache/opcache'],
            ['/canary/cache/shopware'],
            ['/canary/cache/adapters'],
            ['/canary/deploy/pre'],
            ['/canary/deploy/maintenance'],
            ['/canary/deploy/post'],
            ['/canary/diag/env'],
            ['/canary/diag/config'],
            ['/canary/diag/logs'],
            ['/canary/diag/queue'],
            ['/canary/diag/plugins'],
        ];
    }

    // ------------------------------------------------------------------
    // AdapterFlushController: secondary ADMIN scope guard
    //
    // RequestGuardSubscriber guards {prefix}/cache/* with CACHE scope.
    // AdapterFlushController further requires ADMIN scope internally
    // because flushing all adapters is a nuclear operation.
    // This test builds a kernel that dispatches the real controller
    // so the secondary guard actually fires.
    // ------------------------------------------------------------------

    #[Test]
    public function adapter_flush_requires_admin_scope_even_with_valid_cache_token(): void
    {
        // Kernel that routes /canary/cache/adapters POST to the real controller.
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::CACHE->value  => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
            TokenScope::ADMIN->value  => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
        ];

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray(['APP_ENV' => 'dev']),
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            $this->responseFactory,
            new class implements AuditLoggerInterface {
                public function log(AuditRecord $record): void {}
            },
        );

        $dispatcher->addSubscriber($subscriber);

        $cachePool = $this->createStub(CacheItemPoolInterface::class);
        $controller = new AdapterFlushController($this->responseFactory, $cachePool);

        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $event) use ($controller): void {
                if (!$event->hasResponse() && $event->getRequest()->getPathInfo() === '/canary/cache/adapters') {
                    $response = ($controller)($event->getRequest());
                    $event->setResponse($response);
                }
            },
            priority: -100,
        );

        $kernel = new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());

        // CACHE token passes RequestGuardSubscriber (CACHE scope) but fails inner ADMIN check.
        $request = Request::create('http://example.com/canary/cache/adapters?confirm=true', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . self::CACHE_TOKEN);

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function adapter_flush_succeeds_with_admin_token_and_confirm_param(): void
    {
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::ADMIN->value => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
        ];

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray(['APP_ENV' => 'dev']),
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            $this->responseFactory,
            new class implements AuditLoggerInterface {
                public function log(AuditRecord $record): void {}
            },
        );

        $dispatcher->addSubscriber($subscriber);

        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $cachePool->expects(self::once())->method('clear');
        $controller = new AdapterFlushController($this->responseFactory, $cachePool);

        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $event) use ($controller): void {
                if (!$event->hasResponse() && $event->getRequest()->getPathInfo() === '/canary/cache/adapters') {
                    $event->setResponse(($controller)($event->getRequest()));
                }
            },
            priority: -100,
        );

        $kernel = new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());

        $request = Request::create('http://example.com/canary/cache/adapters?confirm=true', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . self::ADMIN_TOKEN);

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['data']['flushed']);
    }

    #[Test]
    public function adapter_flush_requires_confirm_param_even_with_admin_token(): void
    {
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::ADMIN->value => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
        ];

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray(['APP_ENV' => 'dev']),
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            $this->responseFactory,
            new class implements AuditLoggerInterface {
                public function log(AuditRecord $record): void {}
            },
        );

        $dispatcher->addSubscriber($subscriber);

        $cachePool = $this->createMock(CacheItemPoolInterface::class);
        $cachePool->expects(self::never())->method('clear');
        $controller = new AdapterFlushController($this->responseFactory, $cachePool);

        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $event) use ($controller): void {
                if (!$event->hasResponse() && $event->getRequest()->getPathInfo() === '/canary/cache/adapters') {
                    $event->setResponse(($controller)($event->getRequest()));
                }
            },
            priority: -100,
        );

        $kernel = new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());

        // No ?confirm=true
        $request = Request::create('http://example.com/canary/cache/adapters', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . self::ADMIN_TOKEN);

        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(400, $response->getStatusCode());
    }
}
