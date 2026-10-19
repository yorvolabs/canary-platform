<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Full 7-case auth matrix for GET {prefix}/health/live against a live Shopware kernel.
 *
 * This mirrors the test matrix defined in the core RequestGuardSubscriberTest but
 * verifies the wiring end-to-end: subscriber → token authenticator → IP checker
 * → rate limiter → controller.
 *
 * Cases:
 *   1. No token                     → 401
 *   2. Wrong token (gibberish)       → 401
 *   3. Right token, wrong scope      → 403
 *   4. Right token, right scope      → 200
 *   5. Right token, rate limited     → 429  (requires driving rate limit to exhaustion)
 *   6. Non-HTTPS in production mode  → 403  (only if APP_ENV=prod)
 *   7. IP not in allowlist           → 403  (only if CANARY_ALLOWED_CIDRS is set)
 */
final class AllScopesIntegrationTest extends TestCase
{
    use KernelTestBehaviour;

    private function handle(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    // Case 1: No token
    public function testNoTokenReturns401(): void
    {
        $response = $this->handle(Request::create('/canary/health/live', 'GET'));

        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('UNAUTHORIZED', $body['error']['code']);
    }

    // Case 2: Wrong token
    public function testWrongTokenReturns401(): void
    {
        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer totally-wrong-token-value-here');

        $response = $this->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    // Case 3: Right token, wrong scope (CACHE token against MONITORING endpoint)
    public function testRightTokenWrongScopeReturns403(): void
    {
        $cacheToken = (string) getenv('CANARY_TOKEN_CACHE');
        if ($cacheToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_CACHE not configured');
        }

        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $cacheToken);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    // Case 4: Right token, right scope
    public function testRightTokenRightScopeReturns200(): void
    {
        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // Case 4b: ADMIN token grants access to any scope
    public function testAdminTokenGrantsAccessToMonitoringEndpoint(): void
    {
        $token = (string) getenv('CANARY_TOKEN_ADMIN');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_ADMIN not configured');
        }

        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
    }

    // Case 5: Rate limited — exhaust the limit then assert 429
    // Runs only via phpunit.integration-ratelimit.xml (CANARY_RATE_LIMIT_HEALTH=1).
    #[Group('rate-limit')]
    public function testExceededRateLimitReturns429(): void
    {
        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        if ((int) getenv('CANARY_RATE_LIMIT_HEALTH') > 5) {
            $this->markTestSkipped('Set CANARY_RATE_LIMIT_HEALTH=1 to run rate-limit exhaustion test');
        }

        // Use a dedicated source IP so this test never shares a rate-limit bucket
        // with other tests, even when suites are run back-to-back.
        $makeRequest = function () use ($token) {
            $request = Request::create('/canary/health/live', 'GET');
            $request->server->set('REMOTE_ADDR', '192.0.2.1'); // TEST-NET-1 (RFC 5737)
            $request->headers->set('Authorization', 'Bearer ' . $token);
            return $this->handle($request);
        };

        $first = $makeRequest();
        self::assertSame(200, $first->getStatusCode());

        $second = $makeRequest();
        self::assertSame(429, $second->getStatusCode());
        self::assertNotEmpty($second->headers->get('Retry-After'));
    }

    // Case 6: Non-HTTPS in production
    // Runs only via phpunit.integration-prod.xml (APP_ENV=prod).
    #[Group('prod')]
    public function testNonHttpsInProductionReturns403(): void
    {
        if (getenv('APP_ENV') !== 'prod') {
            $this->markTestSkipped('Only enforced in APP_ENV=prod');
        }

        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        // HTTP (non-secure) request
        $request = Request::create('http://example.com/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }

    // Case 7: IP not in allowlist
    public function testIpNotInAllowlistReturns403(): void
    {
        $cidrs = (string) getenv('CANARY_ALLOWED_CIDRS');
        if ($cidrs === '') {
            $this->markTestSkipped('CANARY_ALLOWED_CIDRS not configured — allowlist is disabled');
        }

        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        // Forge a client IP that is guaranteed not in the allowlist.
        $request = Request::create('/canary/health/live', 'GET');
        $request->server->set('REMOTE_ADDR', '198.51.100.1'); // TEST-NET-3 (RFC 5737)
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->handle($request);

        self::assertSame(403, $response->getStatusCode());
    }
}
