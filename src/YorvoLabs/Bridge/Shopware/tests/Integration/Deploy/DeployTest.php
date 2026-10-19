<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Deploy;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for the Phase 2 deploy endpoints.
 *
 * GET    /canary/deploy/pre            — pre-deploy readiness check
 * GET    /canary/deploy/maintenance    — maintenance state
 * PUT    /canary/deploy/maintenance    — enable maintenance
 * DELETE /canary/deploy/maintenance    — disable maintenance
 * POST   /canary/deploy/post           — post-deploy step sequence
 *
 * Requires a running Shopware instance (KernelTestBehaviour) and
 * CANARY_TOKEN_DEPLOY configured in phpunit.integration.xml.
 *
 * tearDown() restores maintenance mode to disabled after each test
 * so the Shopware storefront is not left in maintenance state.
 */
final class DeployTest extends TestCase
{
    use KernelTestBehaviour;

    private string $deployToken;
    private string $monitoringToken;

    protected function setUp(): void
    {
        $this->deployToken     = (string) getenv('CANARY_TOKEN_DEPLOY');
        $this->monitoringToken = (string) getenv('CANARY_TOKEN_MONITORING');

        if ($this->deployToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_DEPLOY not configured');
        }
    }

    protected function tearDown(): void
    {
        // Always restore maintenance mode to off so other tests are not affected.
        if ($this->deployToken !== '') {
            $this->handle('DELETE', '/canary/deploy/maintenance', $this->deployToken);
        }
    }

    // ------------------------------------------------------------------
    // Pre-deploy check
    // ------------------------------------------------------------------

    public function testPreDeployReturnsPassWhenEverythingIsOk(): void
    {
        $response = $this->handle('GET', '/canary/deploy/pre', $this->deployToken);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['ok']);
        self::assertArrayHasKey('pass', $body['data']);
        self::assertArrayHasKey('checks', $body['data']);
        self::assertNotEmpty($body['data']['checks']);
    }

    public function testPreDeployRequiresDeployScope(): void
    {
        if ($this->monitoringToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $response = $this->handle('GET', '/canary/deploy/pre', $this->monitoringToken);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testPreDeployChecksIncludeExpectedNames(): void
    {
        $body   = json_decode((string) $this->handle('GET', '/canary/deploy/pre', $this->deployToken)->getContent(), true);
        $names  = array_column($body['data']['checks'], 'name');

        self::assertContains('maintenance_not_active', $names);
        self::assertContains('queue_depth', $names);
        self::assertContains('failed_messages', $names);
    }

    // ------------------------------------------------------------------
    // Maintenance mode
    // ------------------------------------------------------------------

    public function testMaintenanceStatusIsDisabledByDefault(): void
    {
        // Ensure it's off before checking.
        $this->handle('DELETE', '/canary/deploy/maintenance', $this->deployToken);

        $response = $this->handle('GET', '/canary/deploy/maintenance', $this->deployToken);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['data']['enabled']);
    }

    public function testEnableMaintenanceModeReturnsEnabledState(): void
    {
        $body    = (string) json_encode(['reason' => 'Test deploy']);
        $request = Request::create('http://example.com/canary/deploy/maintenance', 'PUT', content: $body);
        $request->headers->set('Authorization', 'Bearer ' . $this->deployToken);
        $request->headers->set('Content-Type', 'application/json');

        $response = $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertTrue($body['data']['enabled']);
        self::assertSame('Test deploy', $body['data']['reason']);
    }

    public function testDisableMaintenanceModeReturnsDisabledState(): void
    {
        // Enable first.
        $this->handle('PUT', '/canary/deploy/maintenance', $this->deployToken);

        $response = $this->handle('DELETE', '/canary/deploy/maintenance', $this->deployToken);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true);
        self::assertFalse($body['data']['enabled']);
        self::assertNull($body['data']['reason']);
    }

    public function testPreDeployFailsWhenMaintenanceIsAlreadyActive(): void
    {
        // Enable maintenance mode first.
        $this->handle('PUT', '/canary/deploy/maintenance', $this->deployToken);

        $body   = json_decode((string) $this->handle('GET', '/canary/deploy/pre', $this->deployToken)->getContent(), true);
        $checks = $body['data']['checks'];

        $maintenanceCheck = null;
        foreach ($checks as $check) {
            if ($check['name'] === 'maintenance_not_active') {
                $maintenanceCheck = $check;
                break;
            }
        }

        self::assertNotNull($maintenanceCheck);
        self::assertFalse($maintenanceCheck['pass']);
    }

    // ------------------------------------------------------------------
    // Post-deploy
    // ------------------------------------------------------------------

    public function testPostDeployReturnsAllSteps(): void
    {
        $response = $this->handle('POST', '/canary/deploy/post', $this->deployToken);

        self::assertSame(200, $response->getStatusCode());
        $body  = json_decode((string) $response->getContent(), true);
        $names = array_column($body['data']['steps'], 'name');

        self::assertContains('opcache_reset', $names);
        self::assertContains('cache_clear', $names);
        self::assertContains('readiness_check', $names);
    }

    public function testPostDeployStepsHaveDurationMs(): void
    {
        $body = json_decode((string) $this->handle('POST', '/canary/deploy/post', $this->deployToken)->getContent(), true);

        foreach ($body['data']['steps'] as $step) {
            self::assertArrayHasKey('duration_ms', $step, "Step '{$step['name']}' missing duration_ms");
            self::assertIsInt($step['duration_ms']);
            self::assertGreaterThanOrEqual(0, $step['duration_ms']);
        }
    }

    public function testPostDeployRequiresDeployScope(): void
    {
        if ($this->monitoringToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $response = $this->handle('POST', '/canary/deploy/post', $this->monitoringToken);

        self::assertSame(403, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Helper
    // ------------------------------------------------------------------

    private function handle(string $method, string $path, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create("http://example.com{$path}", $method);
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }
}
