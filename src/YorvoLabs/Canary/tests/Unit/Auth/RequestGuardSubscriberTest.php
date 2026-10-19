<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use YorvoLabs\Canary\Audit\AuditRecord;
use YorvoLabs\Canary\Auth\IpAllowlistChecker;
use YorvoLabs\Canary\Auth\RequestGuardSubscriber;
use YorvoLabs\Canary\Auth\ScopedToken;
use YorvoLabs\Canary\Auth\TokenAuthenticator;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Configuration\Options;
use YorvoLabs\Canary\Contract\AuditLoggerInterface;
use YorvoLabs\Canary\RateLimit\InMemoryRateLimiter;
use YorvoLabs\Canary\Response\ResponseFactory;

final class RequestGuardSubscriberTest extends TestCase
{
    private const MONITORING_TOKEN = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN      = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @var list<AuditRecord> */
    private array $auditRecords = [];

    protected function setUp(): void
    {
        $this->auditRecords = [];
    }

    /**
     * @param list<string> $cidrs
     * @param list<string> $enabledFeatures
     */
    private function makeSubscriber(
        bool $production = true,
        array $cidrs = [],
        int $rateLimit = 60,
        array $enabledFeatures = [],
    ): RequestGuardSubscriber {
        $tokenMap = [
            TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value      => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
        ];

        $rawConfig = [
            'APP_ENV'                    => $production ? 'prod' : 'dev',
            'CANARY_RATE_LIMIT_HEALTH'   => $rateLimit,
            'CANARY_RATE_LIMIT_MUTATION' => $rateLimit,
        ];
        if ($enabledFeatures !== []) {
            $rawConfig['CANARY_FEATURES'] = implode(',', $enabledFeatures);
        }
        $config = Options::fromArray($rawConfig);
        $ipChecker   = new IpAllowlistChecker($cidrs);
        $rateLimiter = new InMemoryRateLimiter();

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

        return new RequestGuardSubscriber(
            $config,
            new TokenAuthenticator($tokenMap),
            $ipChecker,
            $rateLimiter,
            new ResponseFactory(),
            $auditLogger,
        );
    }

    private function makeEvent(
        string $path,
        string $method = 'GET',
        string $ip = '127.0.0.1',
        bool $https = true,
        ?string $token = null,
    ): RequestEvent {
        $uri     = ($https ? 'https' : 'http') . '://example.com' . $path;
        $request = Request::create($uri, $method, [], [], [], ['REMOTE_ADDR' => $ip]);
        if ($token !== null) {
            $request->headers->set('Authorization', "Bearer {$token}");
        }
        $kernel = $this->createStub(HttpKernelInterface::class);
        return new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);
    }

    #[Test]
    public function it_ignores_non_canary_paths(): void
    {
        $event = $this->makeEvent('/storefront/home');
        $this->makeSubscriber()->onKernelRequest($event);
        self::assertFalse($event->hasResponse());
        self::assertEmpty($this->auditRecords);
    }

    #[Test]
    public function it_rejects_non_https_in_production(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN);
        $this->makeSubscriber(production: true)->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
        self::assertCount(1, $this->auditRecords);
    }

    #[Test]
    public function it_allows_non_https_in_development(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN);
        $this->makeSubscriber(production: false)->onKernelRequest($event);
        // Valid token in dev over HTTP — subscriber must not set a response at all
        self::assertFalse($event->hasResponse(), 'Non-HTTPS must not be rejected in dev environment');
    }

    #[Test]
    public function it_rejects_ips_not_in_the_allowlist(): void
    {
        $event = $this->makeEvent('/canary/health/live', ip: '192.168.1.1', https: false, token: self::MONITORING_TOKEN);
        $this->makeSubscriber(production: false, cidrs: ['10.0.0.0/8'])->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(403, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function it_returns_401_when_no_token_is_provided(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false);
        $this->makeSubscriber(production: false)->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(401, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function it_returns_401_when_token_is_invalid(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false, token: str_repeat('z', 64));
        $this->makeSubscriber(production: false)->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(401, $event->getResponse()->getStatusCode());
    }

    #[Test]
    public function it_passes_a_valid_request_through(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN);
        $this->makeSubscriber(production: false)->onKernelRequest($event);
        self::assertFalse($event->hasResponse());
        self::assertSame(TokenScope::MONITORING, $event->getRequest()->attributes->get('_canary_scope'));
    }

    #[Test]
    public function it_returns_429_when_rate_limit_is_exceeded(): void
    {
        $sub = $this->makeSubscriber(production: false, rateLimit: 2);
        for ($i = 0; $i < 2; $i++) {
            $sub->onKernelRequest($this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN));
        }
        $event = $this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN);
        $sub->onKernelRequest($event);
        self::assertTrue($event->hasResponse());
        self::assertSame(429, $event->getResponse()->getStatusCode());
        self::assertNotEmpty($event->getResponse()->headers->get('Retry-After'));
    }

    #[Test]
    public function it_adds_mandatory_security_headers_to_rejection_responses(): void
    {
        $event = $this->makeEvent('/canary/health/live', https: false);
        $this->makeSubscriber(production: false)->onKernelRequest($event);
        $response = $event->getResponse();
        self::assertNotNull($response);
        $headers = $response->headers;
        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertNotEmpty($headers->get('X-Canary-RequestId'));
    }

    #[Test]
    public function it_writes_an_audit_record_for_every_canary_request(): void
    {
        $sub = $this->makeSubscriber(production: false);
        $sub->onKernelRequest($this->makeEvent('/canary/health/live', https: false));
        $sub->onKernelRequest($this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN));
        self::assertCount(2, $this->auditRecords);
    }

    #[Test]
    public function it_subscribes_to_kernel_request_at_priority_256(): void
    {
        $events = RequestGuardSubscriber::getSubscribedEvents();
        self::assertArrayHasKey(KernelEvents::REQUEST, $events);
        self::assertSame('onKernelRequest', $events[KernelEvents::REQUEST][0]);
        self::assertSame(256, $events[KernelEvents::REQUEST][1]);
    }

    #[Test]
    public function it_returns_404_for_a_disabled_feature_group(): void
    {
        // Only health is enabled — cache group should 404
        $sub = $this->makeSubscriber(production: false, enabledFeatures: ['health']);
        $event = $this->makeEvent('/canary/cache/opcache', https: false, token: self::CACHE_TOKEN);
        $sub->onKernelRequest($event);

        self::assertTrue($event->hasResponse());
        self::assertSame(404, $event->getResponse()->getStatusCode());
        // Disabled feature 404s must not produce an audit record
        self::assertEmpty($this->auditRecords);
    }

    #[Test]
    public function it_passes_through_when_feature_group_is_enabled(): void
    {
        $sub = $this->makeSubscriber(production: false, enabledFeatures: ['health']);
        $event = $this->makeEvent('/canary/health/live', https: false, token: self::MONITORING_TOKEN);
        $sub->onKernelRequest($event);

        self::assertFalse($event->hasResponse());
    }

    #[Test]
    public function it_enables_all_features_when_none_are_explicitly_configured(): void
    {
        $sub = $this->makeSubscriber(production: false, enabledFeatures: []);
        $event = $this->makeEvent('/canary/cache/opcache', https: false, token: self::CACHE_TOKEN);
        $sub->onKernelRequest($event);

        // Empty feature list means all features enabled — valid token must pass through
        self::assertFalse($event->hasResponse(), 'All features should be enabled when CANARY_FEATURES is empty');
    }
}
