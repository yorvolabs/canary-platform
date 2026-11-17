<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Auth\AuthFailureReason;
use YorvoLabs\Canary\Auth\IpAllowlistChecker;
use YorvoLabs\Canary\Auth\RequestGuardSubscriber;
use YorvoLabs\Canary\Auth\ScopedToken;
use YorvoLabs\Canary\Auth\TokenAuthenticator;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use YorvoLabs\Canary\RateLimit\InMemoryRateLimiter;
use YorvoLabs\Canary\Response\ResponseFactory;

/**
 * Layer order of RequestGuardSubscriber, through a real HttpKernel.
 *
 * Order: feature flag (404) → HTTPS (403) → IP allowlist (403) → rate limit (429)
 *        → token (401 missing / 503 not configured / 401 wrong / 403 wrong scope).
 *
 * Each "*_wins_over_*" test builds a request for which two adjacent layers both
 * fail, and asserts that the earlier layer decides the response and the audit record.
 */
final class RequestGuardLayerOrderTest extends TestCase
{
    private const MONITORING_TOKEN = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN      = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const WRONG_TOKEN      = 'wrong-token-zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';

    private const ALLOWED_IP = '10.0.0.1';
    private const BLOCKED_IP = '192.168.1.1';

    /** @var list<AuditRecord> */
    private array $auditRecords = [];

    private InMemoryRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        $this->auditRecords = [];
        $this->rateLimiter  = new InMemoryRateLimiter();
    }

    /**
     * @param list<string> $enabledFeatures
     */
    private function buildKernel(
        bool $production = false,
        int $rateLimit = 60,
        array $enabledFeatures = [],
    ): HttpKernel {
        $config = Options::fromArray(array_filter([
            'APP_ENV'                    => $production ? 'prod' : 'dev',
            'CANARY_RATE_LIMIT_HEALTH'   => $rateLimit,
            'CANARY_RATE_LIMIT_MUTATION' => $rateLimit,
            'CANARY_FEATURES'            => $enabledFeatures !== [] ? implode(',', $enabledFeatures) : null,
        ]));

        // No DEPLOY and no ADMIN token: /deploy/* is "not configured" (503).
        $tokenMap = [
            TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value      => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
        ];

        $auditRecords = &$this->auditRecords;
        $auditLogger  = new class ($auditRecords) implements AuditLoggerInterface {
            /** @var list<AuditRecord> */
            private array $records; // @phpstan-ignore property.onlyWritten
            /** @param list<AuditRecord> $records */
            public function __construct(array &$records)
            {
                $this->records = &$records;
            }
            public function log(AuditRecord $record): void
            {
                $this->records[] = $record;
            }
        };

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new RequestGuardSubscriber(
            $config,
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker(['10.0.0.0/8']),
            $this->rateLimiter,
            new ResponseFactory(),
            $auditLogger,
        ));
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $event): void {
                if (!$event->hasResponse()) {
                    $event->setResponse(new Response('OK', 200));
                }
            },
            priority: -100,
        );

        return new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());
    }

    private function send(
        HttpKernel $kernel,
        string $path = '/canary/health/live',
        string $ip = self::ALLOWED_IP,
        bool $https = true,
        ?string $token = self::MONITORING_TOKEN,
    ): Response {
        $uri     = ($https ? 'https' : 'http') . '://example.com' . $path;
        $request = Request::create($uri, 'GET', [], [], [], ['REMOTE_ADDR' => $ip]);
        if ($token !== null) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    /** Fill the rate-limit bucket for a group and IP so the next check is denied. */
    private function exhaustRateLimit(string $group, string $ip, int $limit): void
    {
        for ($i = 0; $i < $limit; $i++) {
            $this->rateLimiter->check("{$group}:{$ip}", $limit);
        }
        self::assertFalse($this->rateLimiter->check("{$group}:{$ip}", $limit)->allowed, 'Precondition: bucket exhausted');
    }

    private function lastAudit(): AuditRecord
    {
        self::assertNotEmpty($this->auditRecords);

        return $this->auditRecords[array_key_last($this->auditRecords)];
    }

    // ------------------------------------------------------------------
    // Adjacent pairs: the earlier layer wins
    // ------------------------------------------------------------------

    #[Test]
    public function feature_flag_404_wins_over_https_403(): void
    {
        // Disabled feature AND plain HTTP in production.
        $kernel   = $this->buildKernel(production: true, enabledFeatures: ['cache']);
        $response = $this->send($kernel, https: false);

        self::assertSame(404, $response->getStatusCode());
        self::assertEmpty($this->auditRecords, 'Disabled-feature 404s are not audited');
    }

    #[Test]
    public function https_403_wins_over_ip_allowlist_403(): void
    {
        // Plain HTTP in production AND an IP outside the allowlist.
        $kernel   = $this->buildKernel(production: true);
        $response = $this->send($kernel, ip: self::BLOCKED_IP, https: false);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        self::assertSame(AuthFailureReason::HTTPS_REQUIRED, $this->lastAudit()->failureReason);
    }

    #[Test]
    public function ip_allowlist_403_wins_over_rate_limit_429(): void
    {
        // Blocked IP AND an exhausted rate-limit bucket for that IP.
        $kernel = $this->buildKernel(rateLimit: 1);
        $this->exhaustRateLimit('health', self::BLOCKED_IP, 1);

        $response = $this->send($kernel, ip: self::BLOCKED_IP);

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        self::assertSame(AuthFailureReason::IP_NOT_ALLOWED, $this->lastAudit()->failureReason);
    }

    #[Test]
    public function blocked_ips_do_not_consume_the_rate_limit(): void
    {
        $kernel = $this->buildKernel(rateLimit: 1);

        $this->send($kernel, ip: self::BLOCKED_IP);
        $this->send($kernel, ip: self::BLOCKED_IP);

        self::assertTrue(
            $this->rateLimiter->check('health:' . self::BLOCKED_IP, 1)->allowed,
            'Requests rejected by the allowlist must not touch the rate-limit bucket',
        );
    }

    /**
     * @return iterable<string, array{string, ?string}>
     */
    public static function tokenFailures(): iterable
    {
        yield 'missing token (401)'        => ['/canary/health/live', null];
        yield 'wrong token (401)'          => ['/canary/health/live', self::WRONG_TOKEN];
        yield 'wrong scope (403)'          => ['/canary/health/live', self::CACHE_TOKEN];
        yield 'token not configured (503)' => ['/canary/deploy/pre', self::MONITORING_TOKEN];
    }

    #[Test]
    #[DataProvider('tokenFailures')]
    public function rate_limit_429_wins_over_token_failure(string $path, ?string $token): void
    {
        // Exhausted bucket AND a request that would fail the token layer.
        $kernel = $this->buildKernel(rateLimit: 1);
        $group  = str_starts_with($path, '/canary/health/') ? 'health' : 'mutation';
        $this->exhaustRateLimit($group, self::ALLOWED_IP, 1);

        $response = $this->send($kernel, path: $path, token: $token);

        self::assertSame(429, $response->getStatusCode());
        self::assertNotNull($response->headers->get('Retry-After'));
        self::assertCount(1, $this->auditRecords);
        self::assertSame(AuthFailureReason::RATE_LIMITED, $this->lastAudit()->failureReason);
        self::assertNull($this->lastAudit()->scope, 'Scope is unknown before authentication');
    }

    /**
     * Sanity check for the data provider: without the exhausted bucket, each
     * case fails the token layer with its own status.
     *
     * @return iterable<string, array{string, ?string, int}>
     */
    public static function tokenFailureStatuses(): iterable
    {
        yield 'missing token'        => ['/canary/health/live', null, 401];
        yield 'wrong token'          => ['/canary/health/live', self::WRONG_TOKEN, 401];
        yield 'wrong scope'          => ['/canary/health/live', self::CACHE_TOKEN, 403];
        yield 'token not configured' => ['/canary/deploy/pre', self::MONITORING_TOKEN, 503];
    }

    #[Test]
    #[DataProvider('tokenFailureStatuses')]
    public function token_layer_returns_its_own_status_when_the_rate_limit_allows(string $path, ?string $token, int $status): void
    {
        $response = $this->send($this->buildKernel(), path: $path, token: $token);

        self::assertSame($status, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Failed authentication counts toward the rate limit
    // ------------------------------------------------------------------

    #[Test]
    public function wrong_token_attempts_consume_the_rate_limit_and_end_in_429(): void
    {
        $kernel = $this->buildKernel(rateLimit: 3);

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->send($kernel, token: self::WRONG_TOKEN);
            self::assertSame(401, $response->getStatusCode(), "Attempt {$i} is within the limit and fails authentication");
        }

        $response = $this->send($kernel, token: self::WRONG_TOKEN);
        self::assertSame(429, $response->getStatusCode(), 'Attempt 4 exceeds the limit');
        self::assertSame(AuthFailureReason::RATE_LIMITED, $this->lastAudit()->failureReason);

        // The bucket is per group and IP, so a correct token from the same IP is limited too.
        $response = $this->send($kernel);
        self::assertSame(429, $response->getStatusCode());
    }
}
