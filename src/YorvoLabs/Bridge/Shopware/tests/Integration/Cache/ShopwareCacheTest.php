<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for the Shopware cache-clearing endpoints.
 *
 * POST /canary/cache/shopware         — full clear (all pools)
 * POST /canary/cache/shopware/{pool}  — selective pool clear
 *
 * Requires CANARY_TOKEN_CACHE configured in phpunit.integration.xml.
 */
final class ShopwareCacheTest extends TestCase
{
    use KernelTestBehaviour;

    private function handle(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $token = (string) getenv('CANARY_TOKEN_CACHE');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_CACHE not configured');
        }

        $request = Request::create('http://example.com' . $path, 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    public function testFullClearReturns200(): void
    {
        $response = $this->handle('/canary/cache/shopware');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('cleared', $body['data']);
        self::assertArrayHasKey('object', $body['data']['cleared']);
    }

    public function testSelectiveClearOfObjectPoolReturns200(): void
    {
        $response = $this->handle('/canary/cache/shopware/object');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['data']['cleared']);
        self::assertSame('object', $body['data']['pool']);
    }

    public function testSelectiveClearOfUnknownPoolReturns404(): void
    {
        $token = (string) getenv('CANARY_TOKEN_CACHE');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_CACHE not configured');
        }

        // 'config' and 'template' are not wired in Phase 2 — expect 404.
        $request = Request::create('http://example.com/canary/cache/shopware/config', 'POST');
        $request->headers->set('Authorization', 'Bearer ' . $token);
        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        // Shopware's router will 404 because 'config' doesn't match the route requirement.
        self::assertSame(404, $response->getStatusCode());
    }
}
