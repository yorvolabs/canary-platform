<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Cache;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Cache\AdapterFlushController;
use YorvoLabs\Canary\Response\ResponseFactory;

final class AdapterFlushControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    private function makeRequest(string $confirm, ?TokenScope $scope): Request
    {
        $request = Request::create('/canary/cache/adapters?confirm=' . $confirm, 'POST');
        $request->attributes->set('_canary_scope', $scope);

        return $request;
    }

    #[Test]
    public function flush_succeeds_with_admin_scope_and_confirm(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::once())->method('clear');

        $controller = new AdapterFlushController($this->responseFactory, $pool);
        $response   = $controller($this->makeRequest('true', TokenScope::ADMIN));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['data']['flushed']);
    }

    #[Test]
    public function flush_returns_403_with_non_admin_scope(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::never())->method('clear');

        $controller = new AdapterFlushController($this->responseFactory, $pool);
        $response   = $controller($this->makeRequest('true', TokenScope::CACHE));

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['ok']);
    }

    #[Test]
    public function flush_returns_403_with_no_scope(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::never())->method('clear');

        $controller = new AdapterFlushController($this->responseFactory, $pool);
        $response   = $controller($this->makeRequest('true', null));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function flush_returns_400_when_confirm_is_missing(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::never())->method('clear');

        $controller = new AdapterFlushController($this->responseFactory, $pool);
        $response   = $controller($this->makeRequest('', TokenScope::ADMIN));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['ok']);
    }

    #[Test]
    public function flush_returns_400_when_confirm_is_not_true(): void
    {
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::never())->method('clear');

        $controller = new AdapterFlushController($this->responseFactory, $pool);
        $response   = $controller($this->makeRequest('yes', TokenScope::ADMIN));

        self::assertSame(400, $response->getStatusCode());
    }
}
