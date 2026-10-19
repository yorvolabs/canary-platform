<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Bridge\Shopware\Tests\Unit\Audit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Auth\AuthFailureReason;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Bridge\Shopware\Audit\MonologAuditLogger;

final class MonologAuditLoggerTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private MonologAuditLogger $auditLogger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->auditLogger = new MonologAuditLogger($this->logger);
    }

    public function testLogsAtInfoLevel(): void
    {
        $this->logger->expects(self::once())->method('info');

        $this->auditLogger->log($this->makeRecord());
    }

    public function testLogMessageContainsMethodEndpointAndStatus(): void
    {
        $this->logger->expects(self::once())
            ->method('info')
            ->with(self::stringContains('[canary] GET /canary/health/live → HTTP 200'));

        $this->auditLogger->log($this->makeRecord(method: 'GET', endpoint: '/canary/health/live', statusCode: 200));
    }

    public function testLogContextContainsAllAuditFields(): void
    {
        $record = $this->makeRecord(scope: TokenScope::MONITORING, statusCode: 401, failureReason: AuthFailureReason::TOKEN_MISSING);

        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                self::anything(),
                self::callback(function (array $ctx) {
                    return isset($ctx['ip'], $ctx['scope'], $ctx['status_code'], $ctx['failure_reason'])
                        && $ctx['status_code'] === 401
                        && $ctx['failure_reason'] === 'token_missing';
                }),
            );

        $this->auditLogger->log($record);
    }

    public function testLogContextScopeIsNullWhenNoScope(): void
    {
        $record = $this->makeRecord(scope: null);

        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                self::anything(),
                self::callback(fn(array $ctx) => $ctx['scope'] === null),
            );

        $this->auditLogger->log($record);
    }

    public function testLogContextIncludesTimestampInAtomFormat(): void
    {
        $this->logger->expects(self::once())
            ->method('info')
            ->with(
                self::anything(),
                self::callback(function (array $ctx) {
                    return isset($ctx['timestamp'])
                        && (bool) \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $ctx['timestamp']);
                }),
            );

        $this->auditLogger->log($this->makeRecord());
    }

    private function makeRecord(
        string $method = 'GET',
        string $endpoint = '/canary/health/live',
        int $statusCode = 200,
        ?TokenScope $scope = TokenScope::MONITORING,
        AuthFailureReason|null $failureReason = null,
    ): AuditRecord {
        return new AuditRecord(
            timestamp: new \DateTimeImmutable(),
            ip: '127.0.0.1',
            scope: $scope,
            endpoint: $endpoint,
            method: $method,
            statusCode: $statusCode,
            durationMs: 5,
            failureReason: $failureReason,
        );
    }
}
