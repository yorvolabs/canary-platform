<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Controller\Diagnostics;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Bridge\Shopware\Controller\Diagnostics\LogController;
use YorvoLabs\Canary\Diagnostics\SensitiveValueMasker;
use YorvoLabs\Canary\Response\ResponseFactory;

final class LogControllerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . '/canary_log_test_' . uniqid('', true);
        mkdir($this->logDir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->logDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->logDir);
    }

    private function makeController(): LogController
    {
        return new LogController(new ResponseFactory(), $this->logDir, new SensitiveValueMasker());
    }

    private function writeLogFile(string $name, string $content): void
    {
        file_put_contents($this->logDir . '/' . $name, $content);
    }

    #[Test]
    public function it_returns_404_when_no_log_file_exists(): void
    {
        $response = ($this->makeController())(Request::create('/canary/diag/logs', 'GET'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function it_returns_200_with_expected_shape_for_plain_text_log(): void
    {
        $this->writeLogFile('prod.log', "[2026-04-25 10:00:00] app.ERROR: Something went wrong\n");

        $response = ($this->makeController())(Request::create('/canary/diag/logs', 'GET'));

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode((string) $response->getContent(), true)['data'];
        self::assertArrayHasKey('file', $body);
        self::assertArrayHasKey('format', $body);
        self::assertArrayHasKey('lines_returned', $body);
        self::assertArrayHasKey('entries', $body);
        self::assertSame('text', $body['format']);
    }

    #[Test]
    public function it_parses_json_log_format(): void
    {
        $entry = json_encode([
            'datetime'   => '2026-04-25T10:00:00+00:00',
            'level_name' => 'ERROR',
            'level'      => 400,
            'channel'    => 'app',
            'message'    => 'Connection failed',
            'context'    => [],
        ]);
        $this->writeLogFile('prod.log', $entry . "\n");

        $response = ($this->makeController())(Request::create('/canary/diag/logs', 'GET'));
        $body     = json_decode((string) $response->getContent(), true)['data'];

        self::assertSame('json', $body['format']);
        self::assertIsArray($body['entries'][0]);
        self::assertSame('Connection failed', $body['entries'][0]['message']);
    }

    #[Test]
    public function it_strips_email_addresses_from_log_entries(): void
    {
        $this->writeLogFile('prod.log', "[2026-04-25 10:00:00] app.ERROR: User user@example.com logged out\n");

        $response = ($this->makeController())(Request::create('/canary/diag/logs', 'GET'));
        $body     = json_decode((string) $response->getContent(), true)['data'];

        self::assertStringNotContainsString('user@example.com', (string) json_encode($body['entries']));
        self::assertStringContainsString('[EMAIL]', (string) json_encode($body['entries']));
    }

    #[Test]
    public function it_strips_ipv4_addresses_from_log_entries(): void
    {
        $this->writeLogFile('prod.log', "[2026-04-25 10:00:00] app.ERROR: Request from 192.168.1.100 failed\n");

        $response = ($this->makeController())(Request::create('/canary/diag/logs', 'GET'));
        $body     = json_decode((string) $response->getContent(), true)['data'];

        self::assertStringNotContainsString('192.168.1.100', (string) json_encode($body['entries']));
        self::assertStringContainsString('[IP]', (string) json_encode($body['entries']));
    }

    #[Test]
    public function it_clamps_lines_to_maximum_of_500(): void
    {
        // Write 600 log lines
        $content = '';
        for ($i = 1; $i <= 600; $i++) {
            $content .= "[2026-04-25 10:00:00] app.INFO: Line {$i}\n";
        }
        $this->writeLogFile('prod.log', $content);

        $response = ($this->makeController())(
            Request::create('/canary/diag/logs?lines=9999', 'GET'),
        );
        $body = json_decode((string) $response->getContent(), true)['data'];

        self::assertLessThanOrEqual(500, $body['lines_returned']);
    }

    #[Test]
    public function it_filters_entries_by_level(): void
    {
        $content  = "[2026-04-25 10:00:00] app.INFO: info message\n";
        $content .= "[2026-04-25 10:00:01] app.ERROR: error message\n";
        $this->writeLogFile('prod.log', $content);

        $response = ($this->makeController())(
            Request::create('/canary/diag/logs?level=error', 'GET'),
        );
        $body     = json_decode((string) $response->getContent(), true)['data'];
        $entries  = $body['entries'];

        foreach ($entries as $entry) {
            self::assertStringContainsString('error', strtolower((string) $entry));
        }
    }
}
