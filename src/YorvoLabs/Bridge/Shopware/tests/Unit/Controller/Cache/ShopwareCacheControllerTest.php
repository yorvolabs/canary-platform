<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Cache;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\ShopwareCacheController;
use YorvoLabs\Canary\Response\ResponseFactory;

final class ShopwareCacheControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    #[Test]
    public function clear_all_clears_object_pool(): void
    {
        $objectPool = $this->createMock(CacheItemPoolInterface::class);
        $objectPool->expects(self::once())->method('clear')->willReturn(true);

        $controller = new ShopwareCacheController($this->responseFactory, $objectPool, null);
        $response   = $controller->clearAll(Request::create('/canary/cache/shopware', 'POST'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('object', $body['data']['cleared']);
    }

    #[Test]
    public function clear_all_also_clears_http_pool_when_wired(): void
    {
        $objectPool = $this->createMock(CacheItemPoolInterface::class);
        $objectPool->expects(self::once())->method('clear')->willReturn(true);

        $httpPool = $this->createMock(CacheItemPoolInterface::class);
        $httpPool->expects(self::once())->method('clear')->willReturn(true);

        $controller = new ShopwareCacheController($this->responseFactory, $objectPool, $httpPool);
        $body       = json_decode((string) $controller->clearAll(Request::create('/canary/cache/shopware', 'POST'))->getContent(), true);

        self::assertArrayHasKey('object', $body['data']['cleared']);
        self::assertArrayHasKey('http', $body['data']['cleared']);
    }

    #[Test]
    public function clear_pool_clears_named_pool(): void
    {
        $objectPool = $this->createMock(CacheItemPoolInterface::class);
        $objectPool->expects(self::once())->method('clear')->willReturn(true);

        $controller = new ShopwareCacheController($this->responseFactory, $objectPool, null);
        $response   = $controller->clearPool(Request::create('/canary/cache/shopware/object', 'POST'), 'object');

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['data']['cleared']);
        self::assertSame('object', $body['data']['pool']);
    }

    #[Test]
    public function clear_pool_returns_404_for_unknown_pool_name(): void
    {
        $objectPool = $this->createStub(CacheItemPoolInterface::class);
        $controller = new ShopwareCacheController($this->responseFactory, $objectPool, null);

        $response = $controller->clearPool(Request::create('/canary/cache/shopware/unknown', 'POST'), 'unknown');

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function clear_pool_returns_404_for_http_pool_when_not_wired(): void
    {
        $objectPool = $this->createStub(CacheItemPoolInterface::class);
        $controller = new ShopwareCacheController($this->responseFactory, $objectPool, null);

        $response = $controller->clearPool(Request::create('/canary/cache/shopware/http', 'POST'), 'http');

        self::assertSame(404, $response->getStatusCode());
    }
}
