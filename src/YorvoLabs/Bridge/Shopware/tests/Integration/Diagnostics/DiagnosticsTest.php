<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Diagnostics;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for GET {prefix}/diag/* endpoints.
 *
 * Requires a live Shopware 6 kernel (run via make test-integration).
 * Token values are provided by phpunit.integration.xml env vars.
 */
final class DiagnosticsTest extends TestCase
{
    use KernelTestBehaviour;

    private function diagToken(): string
    {
        $token = (string) getenv('CANARY_TOKEN_DIAGNOSTICS');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_DIAGNOSTICS not configured');
        }
        return $token;
    }

    private function makeRequest(string $path, string $token, string $method = 'GET'): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($path, $method);
        $request->headers->set('Authorization', 'Bearer ' . $token);
        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
    }

    public function testEnvReturnsPhpSection(): void
    {
        $response = $this->makeRequest('/canary/diag/env', $this->diagToken());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('php', $body['data']);
        self::assertArrayHasKey('version', $body['data']['php']);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $body['data']['php']['version']);
    }

    public function testEnvMasksSensitiveKeys(): void
    {
        $response = $this->makeRequest('/canary/diag/env', $this->diagToken());
        $body     = json_decode((string) $response->getContent(), true);
        $env      = $body['data']['env'];

        foreach ($env as $key => $value) {
            if (str_contains(strtolower((string) $key), 'token')
                || str_contains(strtolower((string) $key), 'password')
                || str_contains(strtolower((string) $key), 'secret')) {
                self::assertSame('[REDACTED]', $value, "Key '{$key}' should be redacted");
            }
        }
    }

    public function testEnvReturnsShopwareSection(): void
    {
        $response = $this->makeRequest('/canary/diag/env', $this->diagToken());
        $body     = json_decode((string) $response->getContent(), true);

        self::assertArrayHasKey('shopware', $body['data']);
        self::assertArrayHasKey('version', $body['data']['shopware']);
    }

    public function testConfigReturnsSnapshotAndValidation(): void
    {
        $response = $this->makeRequest('/canary/diag/config', $this->diagToken());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('namespace', $body['data']);
        self::assertArrayHasKey('snapshot', $body['data']);
        self::assertArrayHasKey('validation', $body['data']);
        self::assertIsArray($body['data']['validation']);
    }

    public function testConfigDefaultNamespaceIsYorvoLabsCanary(): void
    {
        $response = $this->makeRequest('/canary/diag/config', $this->diagToken());
        $body     = json_decode((string) $response->getContent(), true);

        self::assertSame('YorvoLabsCanary', $body['data']['namespace']);
    }

    public function testLogsReturnExpectedShape(): void
    {
        $response = $this->makeRequest('/canary/diag/logs?lines=5', $this->diagToken());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('file', $body['data']);
        self::assertArrayHasKey('format', $body['data']);
        self::assertArrayHasKey('lines_returned', $body['data']);
        self::assertArrayHasKey('entries', $body['data']);
        self::assertLessThanOrEqual(5, $body['data']['lines_returned']);
    }

    public function testQueueReturnsDepthAndFailedSections(): void
    {
        $response = $this->makeRequest('/canary/diag/queue', $this->diagToken());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('queues', $body['data']);
        self::assertArrayHasKey('failed', $body['data']);
        self::assertArrayHasKey('workers', $body['data']);
        self::assertArrayHasKey('count', $body['data']['failed']);
        self::assertArrayHasKey('oldest_failed_at', $body['data']['failed']);
    }

    public function testPluginsReturnsList(): void
    {
        $response = $this->makeRequest('/canary/diag/plugins', $this->diagToken());

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true);
        self::assertArrayHasKey('plugins', $body['data']);
        self::assertIsArray($body['data']['plugins']);
    }

    public function testDiagnosticsRequiresDiagnosticsScope(): void
    {
        $monitoringToken = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($monitoringToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $response = $this->makeRequest('/canary/diag/env', $monitoringToken);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testAdminTokenAcceptedOnAllDiagnosticsEndpoints(): void
    {
        $adminToken = (string) getenv('CANARY_TOKEN_ADMIN');
        if ($adminToken === '') {
            $this->markTestSkipped('CANARY_TOKEN_ADMIN not configured');
        }

        foreach (['/canary/diag/env', '/canary/diag/config', '/canary/diag/queue', '/canary/diag/plugins'] as $path) {
            $response = $this->makeRequest($path, $adminToken);
            self::assertSame(200, $response->getStatusCode(), "Admin token should be accepted on {$path}");
        }
    }
}
