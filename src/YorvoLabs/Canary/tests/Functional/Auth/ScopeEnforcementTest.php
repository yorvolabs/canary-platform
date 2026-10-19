<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Functional\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use YorvoLabs\Canary\RateLimit\InMemoryRateLimiter;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Functional tests: scope enforcement contract.
 *
 * Asserts that each Canary path group accepts only the correct token scope
 * and rejects all others with 403, not 401 (token present but wrong scope).
 */
final class ScopeEnforcementTest extends TestCase
{
    private const MONITORING_TOKEN  = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN       = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DIAGNOSTICS_TOKEN = 'diagnostics-token-ccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const DEPLOY_TOKEN      = 'deploy-token-ddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const ADMIN_TOKEN       = 'admin-token-eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    private function buildKernel(): HttpKernel
    {
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::MONITORING->value  => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value       => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
            TokenScope::DIAGNOSTICS->value => ScopedToken::fromEnv(TokenScope::DIAGNOSTICS, self::DIAGNOSTICS_TOKEN),
            TokenScope::DEPLOY->value      => ScopedToken::fromEnv(TokenScope::DEPLOY, self::DEPLOY_TOKEN),
            TokenScope::ADMIN->value       => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
        ];

        $auditLogger = new class implements AuditLoggerInterface {
            public function log(AuditRecord $record): void {}
        };

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray(['APP_ENV' => 'dev']),
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            new ResponseFactory(),
            $auditLogger,
        );

        $dispatcher->addSubscriber($subscriber);
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            fn(RequestEvent $e) => $e->setResponse(new Response('OK', 200)),
            priority: -100,
        );

        return new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());
    }

    private function request(HttpKernel $kernel, string $path, string $token): Response
    {
        $req = Request::create('http://example.com' . $path);
        $req->headers->set('Authorization', 'Bearer ' . $token);
        return $kernel->handle($req, HttpKernelInterface::MAIN_REQUEST, false);
    }

    // ------------------------------------------------------------------
    // Each scope accepts its own token
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('correctScopeMatrix')]
    public function correct_scope_token_is_accepted(string $path, string $token): void
    {
        self::assertSame(200, $this->request($this->buildKernel(), $path, $token)->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function correctScopeMatrix(): array
    {
        return [
            'health with monitoring token'      => ['/canary/health/live',      self::MONITORING_TOKEN],
            'cache with cache token'            => ['/canary/cache/opcache',    self::CACHE_TOKEN],
            'diagnostics with diag token'       => ['/canary/diag/env',         self::DIAGNOSTICS_TOKEN],
            'deploy with deploy token'          => ['/canary/deploy/pre',       self::DEPLOY_TOKEN],
        ];
    }

    // ------------------------------------------------------------------
    // Admin token grants access everywhere
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('allPaths')]
    public function admin_token_is_accepted_on_every_path(string $path): void
    {
        self::assertSame(200, $this->request($this->buildKernel(), $path, self::ADMIN_TOKEN)->getStatusCode());
    }

    /** @return array<int, array{string}> */
    public static function allPaths(): array
    {
        return [
            ['/canary/health/live'],
            ['/canary/cache/opcache'],
            ['/canary/diag/env'],
            ['/canary/deploy/pre'],
        ];
    }

    // ------------------------------------------------------------------
    // Wrong scope returns 403 (not 401 — token is present but insufficient)
    // ------------------------------------------------------------------

    #[Test]
    #[DataProvider('wrongScopeMatrix')]
    public function wrong_scope_token_returns_403(string $path, string $token): void
    {
        self::assertSame(403, $this->request($this->buildKernel(), $path, $token)->getStatusCode());
    }

    /** @return array<string, array{string, string}> */
    public static function wrongScopeMatrix(): array
    {
        return [
            'cache token on health path'       => ['/canary/health/live',   self::CACHE_TOKEN],
            'monitoring token on cache path'   => ['/canary/cache/opcache', self::MONITORING_TOKEN],
            'deploy token on diag path'        => ['/canary/diag/env',      self::DEPLOY_TOKEN],
            'diag token on deploy path'        => ['/canary/deploy/pre',    self::DIAGNOSTICS_TOKEN],
        ];
    }

    // ------------------------------------------------------------------
    // Response contract: body shape and headers
    // ------------------------------------------------------------------

    #[Test]
    public function auth_failure_response_is_valid_json_with_ok_false(): void
    {
        $kernel   = $this->buildKernel();
        $response = $this->request($kernel, '/canary/health/live', self::CACHE_TOKEN); // wrong scope

        $body = json_decode((string) $response->getContent(), true);

        self::assertIsArray($body);
        self::assertFalse($body['ok']);
        self::assertArrayHasKey('error', $body);
        self::assertArrayHasKey('code', $body['error']);
        self::assertArrayHasKey('message', $body['error']);
        self::assertArrayHasKey('meta', $body);
        self::assertArrayHasKey('request_id', $body['meta']);
    }

    #[Test]
    public function auth_failure_response_carries_all_security_headers(): void
    {
        $kernel   = $this->buildKernel();
        $response = $this->request($kernel, '/canary/health/live', str_repeat('x', 64)); // invalid token

        self::assertSame('application/json', $response->headers->get('Content-Type'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('canary-', (string) $response->headers->get('X-Canary-RequestId'));
    }

    #[Test]
    public function missing_token_returns_401_with_correct_content_type(): void
    {
        $kernel = $this->buildKernel();
        $req    = Request::create('http://example.com/canary/health/live');
        $resp   = $kernel->handle($req, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(401, $resp->getStatusCode());
        self::assertStringContainsString('application/json', (string) $resp->headers->get('Content-Type'));

        $body = json_decode((string) $resp->getContent(), true);
        self::assertFalse($body['ok']);
    }
}
