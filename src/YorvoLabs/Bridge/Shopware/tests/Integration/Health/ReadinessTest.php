<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Integration\Health;

use PHPUnit\Framework\TestCase;
use Shopware\Core\Framework\Test\TestCaseBase\KernelTestBehaviour;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Integration tests for GET {prefix}/health/ready.
 *
 * Verifies RFC application/health+json shape and that all registered
 * health checks (database, cache_adapter, filesystem, elasticsearch)
 * appear in the response.
 */
final class ReadinessTest extends TestCase
{
    use KernelTestBehaviour;

    private function makeReadyRequest(): \Symfony\Component\HttpFoundation\Response
    {
        $token = (string) getenv('CANARY_TOKEN_MONITORING');
        if ($token === '') {
            $this->markTestSkipped('CANARY_TOKEN_MONITORING not configured');
        }

        $request = Request::create('/canary/health/ready', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $this->getKernel()->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    public function testReadyResponseHasHealthJsonContentType(): void
    {
        $response = $this->makeReadyRequest();

        self::assertStringContainsString('application/health+json', (string) $response->headers->get('Content-Type'));
    }

    public function testReadyResponseShapeIsRfcCompliant(): void
    {
        $response = $this->makeReadyRequest();
        $body = json_decode((string) $response->getContent(), true);

        self::assertArrayHasKey('status', $body);
        self::assertContains($body['status'], ['pass', 'warn', 'fail']);
        self::assertIsArray($body['checks']);
        self::assertArrayHasKey('meta', $body);
        self::assertArrayHasKey('request_id', $body['meta']);
        self::assertArrayHasKey('duration_ms', $body['meta']);
    }

    public function testReadyResponseIncludesAllPhase1Checks(): void
    {
        $response = $this->makeReadyRequest();
        $body = json_decode((string) $response->getContent(), true);

        $checkNames = array_column($body['checks'], 'name');

        self::assertContains('database', $checkNames);
        self::assertContains('cache_adapter', $checkNames);
        self::assertContains('filesystem', $checkNames);
        self::assertContains('elasticsearch', $checkNames);
    }

    public function testReadyReturns200WhenAllCheckPass(): void
    {
        $response = $this->makeReadyRequest();
        $body = json_decode((string) $response->getContent(), true);

        if ($body['status'] === 'fail') {
            $this->markTestSkipped('At least one dependency is unavailable in this environment');
        }

        self::assertSame(200, $response->getStatusCode());
    }

}
