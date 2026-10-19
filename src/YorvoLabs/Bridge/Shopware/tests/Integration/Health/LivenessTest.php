<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Health;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for GET {prefix}/health/live.
 *
 * Requires a running Shopware kernel and at least CANARY_TOKEN_MONITORING set.
 * Run from the Shopware root: vendor/bin/phpunit vendor/yorvolabs/canary-bridge-shopware/tests
 */
final class LivenessTest extends TestCase
{
    use KernelTestBehaviour;

    public function testLiveReturns200WithValidMonitoringToken(): void
    {
        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertSame('alive', $body['data']['status']);
        self::assertNotNull($response->headers->get('X-Canary-RequestId'));
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testLiveReturns401WithoutToken(): void
    {
        $request = Request::create('/canary/health/live', 'GET');

        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(401, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['ok']);
        self::assertSame('UNAUTHORIZED', $body['error']['code']);
    }

    public function testLiveReturns403WithWrongScope(): void
    {
        $token = (string) getenv('CANARY_TOKEN_CACHE');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_CACHE not configured');
        }

        // CACHE token cannot authenticate against the MONITORING scope.
        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testLiveReturns200WithAdminToken(): void
    {
        $token = (string) getenv('CANARY_TOKEN_ADMIN');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_ADMIN not configured');
        }

        $request = Request::create('/canary/health/live', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode());
    }
}
