<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Deploy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\MaintenanceController;
use YorvoLabs\Canary\Response\ResponseFactory;

final class MaintenanceControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    #[Test]
    public function status_returns_disabled_by_default(): void
    {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnMap([
            ['core.basicInformation.maintenanceMode', null, false],
            ['YorvoLabsCanary.config.maintenanceReason', null, null],
        ]);

        $controller = new MaintenanceController($this->responseFactory, $systemConfig);
        $response   = $controller->status(Request::create('/canary/deploy/maintenance', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertFalse($body['data']['enabled']);
        self::assertNull($body['data']['reason']);
    }

    #[Test]
    public function enable_sets_maintenance_mode_and_returns_state(): void
    {
        $config = ['core.basicInformation.maintenanceMode' => false, 'YorvoLabsCanary.config.maintenanceReason' => null];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(function (string $k) use (&$config): mixed {
            return $config[$k] ?? null;
        });
        $systemConfig->expects(self::exactly(2))->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$config): void {
                $config[$key] = $value;
            });

        $controller = new MaintenanceController($this->responseFactory, $systemConfig);

        $request = Request::create('/canary/deploy/maintenance', 'PUT', content: (string) json_encode(['reason' => 'Release v3']));
        $request->headers->set('Content-Type', 'application/json');

        $response = $controller->enable($request);
        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['data']['enabled']);
        self::assertSame('Release v3', $body['data']['reason']);
    }

    #[Test]
    public function enable_accepts_empty_reason(): void
    {
        $config = ['core.basicInformation.maintenanceMode' => false, 'YorvoLabsCanary.config.maintenanceReason' => null];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(function (string $k) use (&$config): mixed {
            return $config[$k] ?? null;
        });
        $systemConfig->method('set')->willReturnCallback(function (string $key, mixed $value) use (&$config): void {
            $config[$key] = $value;
        });

        $controller = new MaintenanceController($this->responseFactory, $systemConfig);
        $response   = $controller->enable(Request::create('/canary/deploy/maintenance', 'PUT'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['data']['enabled']);
        self::assertNull($body['data']['reason']);
    }

    #[Test]
    public function disable_clears_maintenance_mode(): void
    {
        $config = [
            'core.basicInformation.maintenanceMode' => true,
            'YorvoLabsCanary.config.maintenanceReason' => 'old reason',
        ];

        $systemConfig = $this->createMock(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(function (string $k) use (&$config): mixed {
            return $config[$k] ?? null;
        });
        $systemConfig->expects(self::exactly(2))->method('set')
            ->willReturnCallback(function (string $key, mixed $value) use (&$config): void {
                $config[$key] = $value;
            });

        $controller = new MaintenanceController($this->responseFactory, $systemConfig);
        $response   = $controller->disable(Request::create('/canary/deploy/maintenance', 'DELETE'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['data']['enabled']);
        self::assertNull($body['data']['reason']);
    }
}
