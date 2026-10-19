<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Functional\Auth;

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
 * Functional tests: rate limiting contract.
 *
 * Asserts on the full HTTP contract of rate-limited responses:
 * status code, Retry-After header, response body shape.
 */
final class RateLimitingTest extends TestCase
{
    private const MONITORING_TOKEN = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN      = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private function buildKernel(int $healthLimit, int $mutationLimit): HttpKernel
    {
        $dispatcher = new EventDispatcher();

        $tokenMap = [
            TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value      => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
        ];

        $auditLogger = new class implements AuditLoggerInterface {
            public function log(AuditRecord $record): void {}
        };

        $subscriber = new RequestGuardSubscriber(
            Options::fromArray([
                'APP_ENV'                    => 'dev',
                'CANARY_RATE_LIMIT_HEALTH'   => $healthLimit,
                'CANARY_RATE_LIMIT_MUTATION' => $mutationLimit,
            ]),
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

    private function sendRequest(HttpKernel $kernel, string $path, string $token, string $ip = '127.0.0.1'): Response
    {
        $request = Request::create('http://example.com' . $path, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    #[Test]
    public function requests_within_the_limit_are_allowed(): void
    {
        $kernel = $this->buildKernel(healthLimit: 3, mutationLimit: 3);

        for ($i = 0; $i < 3; $i++) {
            $response = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
            self::assertSame(200, $response->getStatusCode(), "Request {$i} should be allowed");
        }
    }

    #[Test]
    public function request_exceeding_the_limit_returns_429(): void
    {
        $kernel = $this->buildKernel(healthLimit: 2, mutationLimit: 2);

        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);

        $response = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
        self::assertSame(429, $response->getStatusCode());
    }

    #[Test]
    public function rate_limited_response_includes_retry_after_header(): void
    {
        $kernel = $this->buildKernel(healthLimit: 1, mutationLimit: 1);

        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
        $response = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);

        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertGreaterThanOrEqual(1, (int) $response->headers->get('Retry-After'));
    }

    #[Test]
    public function rate_limited_response_body_is_correct_json(): void
    {
        $kernel = $this->buildKernel(healthLimit: 1, mutationLimit: 1);

        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
        $response = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);

        $body = json_decode((string) $response->getContent(), true);

        self::assertIsArray($body);
        self::assertFalse($body['ok']);
        self::assertSame('RATE_LIMITED', $body['error']['code']);
    }

    #[Test]
    public function health_and_mutation_limits_are_tracked_independently(): void
    {
        // Health limit 1, mutation limit 10
        $kernel = $this->buildKernel(healthLimit: 1, mutationLimit: 10);

        // Exhaust health limit
        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);
        $healthResponse = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN);

        // Cache (mutation group) should still be fine
        $cacheResponse = $this->sendRequest($kernel, '/canary/cache/opcache', self::CACHE_TOKEN);

        self::assertSame(429, $healthResponse->getStatusCode());
        self::assertNotSame(429, $cacheResponse->getStatusCode());
    }

    #[Test]
    public function rate_limits_are_tracked_per_ip(): void
    {
        $kernel = $this->buildKernel(healthLimit: 1, mutationLimit: 1);

        // Exhaust limit for ip-a
        $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN, ip: '10.0.0.1');
        $responseA = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN, ip: '10.0.0.1');

        // ip-b has its own bucket and should still be allowed
        $responseB = $this->sendRequest($kernel, '/canary/health/live', self::MONITORING_TOKEN, ip: '10.0.0.2');

        self::assertSame(429, $responseA->getStatusCode());
        self::assertSame(200, $responseB->getStatusCode());
    }
}
