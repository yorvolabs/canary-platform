<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Deploy;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Deploy\PreDeployController;
use YorvoLabs\Canary\Bridge\Shopware\Repository\MessengerQueueRepository;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Deploy\DeployHookRunner;
use YorvoLabs\Canary\Response\ResponseFactory;

final class PreDeployControllerTest extends TestCase
{
    private ResponseFactory $responseFactory;

    protected function setUp(): void
    {
        $this->responseFactory = new ResponseFactory();
    }

    private function makeController(
        bool $maintenanceEnabled = false,
        int $queueDepth = 0,
        int $failedCount = 0,
    ): PreDeployController {
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturn($maintenanceEnabled);

        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls($queueDepth, $failedCount);
        $queueRepository = new MessengerQueueRepository($connection);

        $options = Options::fromArray([]);

        return new PreDeployController($this->responseFactory, $systemConfig, $queueRepository, $options, new DeployHookRunner());
    }

    #[Test]
    public function it_returns_pass_when_all_conditions_are_met(): void
    {
        $response = ($this->makeController())(Request::create('/canary/deploy/pre', 'GET'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertTrue($body['data']['pass']);
        self::assertCount(3, $body['data']['checks']);
    }

    #[Test]
    public function it_fails_when_maintenance_is_already_active(): void
    {
        $response = ($this->makeController(maintenanceEnabled: true))(Request::create('/canary/deploy/pre', 'GET'));

        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['data']['pass']);

        $check = $this->findCheck($body['data']['checks'], 'maintenance_not_active');
        self::assertFalse($check['pass']);
        self::assertTrue($check['detail']['maintenance_enabled']);
    }

    #[Test]
    public function it_fails_when_queue_depth_exceeds_threshold(): void
    {
        // Default threshold is 100; send 101 pending messages.
        $response = ($this->makeController(queueDepth: 101))(Request::create('/canary/deploy/pre', 'GET'));

        $body  = json_decode((string) $response->getContent(), true);
        $check = $this->findCheck($body['data']['checks'], 'queue_depth');
        self::assertFalse($check['pass']);
        self::assertSame(101, $check['detail']['depth']);
        self::assertSame(100, $check['detail']['threshold']);
    }

    #[Test]
    public function it_fails_when_failed_messages_exceed_threshold(): void
    {
        // Default threshold is 0; any failed message fails the check.
        $response = ($this->makeController(failedCount: 1))(Request::create('/canary/deploy/pre', 'GET'));

        $body  = json_decode((string) $response->getContent(), true);
        $check = $this->findCheck($body['data']['checks'], 'failed_messages');
        self::assertFalse($check['pass']);
        self::assertSame(1, $check['detail']['count']);
    }

    #[Test]
    public function it_reports_all_checks_in_response(): void
    {
        $body = json_decode((string) ($this->makeController())(Request::create('/canary/deploy/pre', 'GET'))->getContent(), true);

        $names = array_column($body['data']['checks'], 'name');
        self::assertContains('maintenance_not_active', $names);
        self::assertContains('queue_depth', $names);
        self::assertContains('failed_messages', $names);
    }

    /** @param list<array<string, mixed>> $checks */
    private function findCheck(array $checks, string $name): mixed
    {
        foreach ($checks as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }
        self::fail("Check '{$name}' not found in response");
    }
}
