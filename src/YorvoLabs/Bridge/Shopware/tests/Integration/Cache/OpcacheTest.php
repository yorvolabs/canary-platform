<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Cache;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for the OPcache endpoints.
 *
 * GET  {prefix}/cache/opcache              — status snapshot
 * POST {prefix}/cache/opcache/reset        — reset (requires ?confirm=true)
 */
final class OpcacheTest extends TestCase
{
    use KernelTestBehaviour;

    private function makeCacheRequest(string $method, string $path, string $query = ''): \Symfony\Component\HttpFoundation\Response
    {
        $token = (string) getenv('CANARY_TOKEN_CACHE');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_CACHE not configured');
        }

        $uri = $path . ($query !== '' ? '?' . $query : '');
        $request = Request::create($uri, $method);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    public function testStatusReturnsOpcacheInfo(): void
    {
        $response = $this->makeCacheRequest('GET', '/canary/cache/opcache');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('enabled', $body['data']);
    }

    public function testResetRequiresConfirmParam(): void
    {
        $response = $this->makeCacheRequest('POST', '/canary/cache/opcache/reset');

        self::assertSame(400, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertSame('BAD_REQUEST', $body['error']['code']);
    }

    public function testResetSucceedsWithConfirmParam(): void
    {
        if (!function_exists('opcache_reset')) {
            $this->markTestSkipped('OPcache extension not loaded');
        }

        $response = $this->makeCacheRequest('POST', '/canary/cache/opcache/reset', 'confirm=true');

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('reset', $body['data']);
    }
}
