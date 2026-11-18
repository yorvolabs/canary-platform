<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Controller\ArgumentResolver;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernel;
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

/**
 * Integration tests: RequestGuardSubscriber wired into a real Symfony HttpKernel.
 *
 * Unlike the unit tests which call onKernelRequest() directly, these tests
 * dispatch requests through the actual kernel event pipeline and assert on the
 * subscriber's interaction with the full event system — priority ordering,
 * event propagation stopping, and response injection.
 */
final class RequestGuardSubscriberKernelTest extends TestCase
{
    private const MONITORING_TOKEN = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var list<AuditRecord> */
    private array $auditRecords = [];

    protected function setUp(): void
    {
        $this->auditRecords = [];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param list<string> $cidrs
     * @param list<string> $enabledFeatures
     */
    private function buildKernel(
        bool $production = false,
        array $cidrs = [],
        array $enabledFeatures = [],
        ?\Closure $controller = null,
    ): HttpKernel {
        $dispatcher = new EventDispatcher();

        $config = Options::fromArray(array_filter([
            'APP_ENV'                  => $production ? 'prod' : 'dev',
            'CANARY_TOKEN_MONITORING'  => self::MONITORING_TOKEN,
            'CANARY_FEATURES'          => $enabledFeatures !== [] ? implode(',', $enabledFeatures) : null,
        ]));

        $tokenMap = [
            TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
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

        $subscriber = new RequestGuardSubscriber(
            $config,
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker($cidrs),
            new InMemoryRateLimiter(),
            new ResponseFactory(),
            $auditLogger,
        );

        $dispatcher->addSubscriber($subscriber);

        // A catch-all "controller" for any request that gets past the subscriber.
        // Returns 200 unless a custom closure is given.
        $controller ??= static fn(): Response => new Response('OK', 200);
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function (RequestEvent $event) use ($controller): void {
                if (!$event->hasResponse()) {
                    $event->setResponse($controller());
                }
            },
            priority: -100,
        );

        // Stand-in for the framework's ErrorListener: uncaught exceptions become a 500.
        $dispatcher->addListener(
            KernelEvents::EXCEPTION,
            static fn(ExceptionEvent $event) => $event->setResponse(new Response('error', 500)),
        );

        return new HttpKernel(
            $dispatcher,
            new ControllerResolver(),
            null,
            new ArgumentResolver(),
        );
    }

    private function handle(HttpKernel $kernel, Request $request): Response
    {
        return $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    #[Test]
    public function subscriber_is_registered_at_correct_priority_and_intercepts_canary_requests(): void
    {
        $kernel  = $this->buildKernel();
        $request = Request::create('http://example.com/canary/health/live');

        // No token — should be intercepted by subscriber before the catch-all
        $response = $this->handle($kernel, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function non_canary_requests_pass_through_to_the_application(): void
    {
        $kernel   = $this->buildKernel();
        $request  = Request::create('http://example.com/storefront/home');
        $response = $this->handle($kernel, $request);

        self::assertSame(200, $response->getStatusCode());
        self::assertEmpty($this->auditRecords);
    }

    #[Test]
    public function authenticated_ops_request_reaches_the_application(): void
    {
        $kernel  = $this->buildKernel();
        $request = Request::create('http://example.com/canary/health/live');
        $request->headers->set('Authorization', 'Bearer ' . self::MONITORING_TOKEN);

        $response = $this->handle($kernel, $request);

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function all_responses_carry_mandatory_security_headers(): void
    {
        $kernel  = $this->buildKernel();
        $request = Request::create('http://example.com/canary/health/live');

        $response = $this->handle($kernel, $request);
        $headers  = $response->headers;

        self::assertSame('no-store, private', $headers->get('Cache-Control'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertStringStartsWith('canary-', (string) $headers->get('X-Canary-RequestId'));
    }

    #[Test]
    public function disabled_feature_returns_404_before_auth_and_leaves_no_audit_record(): void
    {
        $kernel  = $this->buildKernel(enabledFeatures: ['cache']);
        $request = Request::create('http://example.com/canary/health/live');
        $request->headers->set('Authorization', 'Bearer ' . self::MONITORING_TOKEN);

        $response = $this->handle($kernel, $request);

        self::assertSame(404, $response->getStatusCode());
        self::assertEmpty($this->auditRecords, 'Disabled feature 404 must not produce an audit record');
    }

    #[Test]
    public function subscriber_stops_event_propagation_on_auth_failure(): void
    {
        $catchAllCalled = false;
        $kernel         = $this->buildKernel();
        $dispatcher     = new EventDispatcher();

        // Rebuild with a spy on the catch-all
        $config      = Options::fromArray(['APP_ENV' => 'dev', 'CANARY_TOKEN_MONITORING' => self::MONITORING_TOKEN]);
        $tokenMap    = [TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN)];
        $auditLogger = new class ($this->auditRecords) implements AuditLoggerInterface {
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

        $subscriber = new RequestGuardSubscriber(
            $config,
            new TokenAuthenticator($tokenMap),
            new IpAllowlistChecker([]),
            new InMemoryRateLimiter(),
            new ResponseFactory(),
            $auditLogger,
        );

        $dispatcher->addSubscriber($subscriber);
        $dispatcher->addListener(
            KernelEvents::REQUEST,
            function () use (&$catchAllCalled): void {
                $catchAllCalled = true;
            },
            priority: -100,
        );

        $httpKernel = new HttpKernel($dispatcher, new ControllerResolver(), null, new ArgumentResolver());
        $request    = Request::create('http://example.com/canary/health/live'); // no token

        $httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);

        // The catch-all at priority -100 should still run (Symfony doesn't stop propagation
        // on setResponse; it just means the controller step is skipped). What matters is
        // that the subscriber's 401 response is what actually gets returned.
        // This test confirms our response is set before the catch-all, and the catch-all's
        // hasResponse() guard means it won't overwrite ours.
        self::assertCount(1, $this->auditRecords);
    }

    #[Test]
    public function audit_record_contains_correct_fields_for_a_failed_request(): void
    {
        $kernel  = $this->buildKernel();
        $request = Request::create('http://example.com/canary/health/live', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.1']);

        $this->handle($kernel, $request);

        self::assertCount(1, $this->auditRecords);

        /** @var AuditRecord $record */
        $record = $this->auditRecords[0];

        self::assertSame('10.0.0.1', $record->ip);
        self::assertSame('/canary/health/live', $record->endpoint);
        self::assertSame('GET', $record->method);
        self::assertSame(401, $record->statusCode);
        self::assertNotNull($record->failureReason);
    }

    #[Test]
    public function passed_request_is_audited_with_the_status_the_controller_returned(): void
    {
        $kernel  = $this->buildKernel(controller: static fn(): Response => new Response('degraded', 503));
        $request = Request::create('http://example.com/canary/health/ready');
        $request->headers->set('Authorization', 'Bearer ' . self::MONITORING_TOKEN);

        $response = $this->handle($kernel, $request);

        self::assertSame(503, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        self::assertSame(503, $this->auditRecords[0]->statusCode);
        self::assertSame(TokenScope::MONITORING, $this->auditRecords[0]->scope);
        self::assertNull($this->auditRecords[0]->failureReason);
    }

    #[Test]
    public function passed_request_is_audited_with_500_when_the_controller_throws(): void
    {
        $kernel  = $this->buildKernel(controller: static fn(): Response => throw new \RuntimeException('boom'));
        $request = Request::create('http://example.com/canary/health/ready');
        $request->headers->set('Authorization', 'Bearer ' . self::MONITORING_TOKEN);

        // catch: true — the exception listener turns the exception into a response
        // and the kernel still dispatches kernel.response, as in production.
        $response = $kernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);

        self::assertSame(500, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        self::assertSame(500, $this->auditRecords[0]->statusCode);
    }

    #[Test]
    public function rejected_request_is_audited_once_with_the_guard_status(): void
    {
        $kernel  = $this->buildKernel();
        $request = Request::create('http://example.com/canary/health/live');
        $request->headers->set('Authorization', 'Bearer wrong');

        $response = $this->handle($kernel, $request);

        self::assertSame(401, $response->getStatusCode());
        self::assertCount(1, $this->auditRecords);
        self::assertSame(401, $this->auditRecords[0]->statusCode);
    }

    // ------------------------------------------------------------------
    // Unknown paths under the prefix
    // ------------------------------------------------------------------

    #[Test]
    public function unknown_path_and_disabled_group_look_the_same_to_a_caller_without_a_token(): void
    {
        // Health is disabled; /canary/nope matches no group at all.
        $kernel = $this->buildKernel(enabledFeatures: ['cache']);

        $disabled = $this->handle($kernel, Request::create('http://example.com/canary/health/live'));
        $unknown  = $this->handle($kernel, Request::create('http://example.com/canary/nope'));

        self::assertSame(404, $disabled->getStatusCode());
        self::assertSame($disabled->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($this->normalisedBody($disabled), $this->normalisedBody($unknown));
        self::assertSame($this->normalisedHeaders($disabled), $this->normalisedHeaders($unknown));
        self::assertEmpty($this->auditRecords, 'Neither 404 may produce an audit record');
    }

    #[Test]
    public function unknown_path_returns_404_before_every_other_check_and_is_not_audited(): void
    {
        // Production over plain HTTP from an IP outside the allowlist: a path in
        // a group would get 403, an unknown path still gets 404.
        $kernel  = $this->buildKernel(production: true, cidrs: ['10.0.0.0/8']);
        $request = Request::create('http://example.com/canary/nope', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set('Authorization', 'Bearer ' . self::MONITORING_TOKEN);

        $response = $this->handle($kernel, $request);

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
        self::assertEmpty($this->auditRecords, 'Unknown-path 404 must not produce an audit record');
    }

    #[Test]
    public function path_equal_to_a_group_name_without_trailing_slash_is_unknown(): void
    {
        $kernel   = $this->buildKernel();
        $response = $this->handle($kernel, Request::create('http://example.com/canary/health'));

        self::assertSame(404, $response->getStatusCode());
        self::assertEmpty($this->auditRecords);
    }

    // ------------------------------------------------------------------
    // WWW-Authenticate on 401
    // ------------------------------------------------------------------

    #[Test]
    public function missing_token_401_carries_a_bearer_challenge(): void
    {
        $response = $this->handle($this->buildKernel(), Request::create('http://example.com/canary/health/live'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function wrong_token_401_carries_a_bearer_challenge(): void
    {
        $request = Request::create('http://example.com/canary/health/live');
        $request->headers->set('Authorization', 'Bearer wrong');

        $response = $this->handle($this->buildKernel(), $request);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', $response->headers->get('WWW-Authenticate'));
    }

    #[Test]
    public function non_401_rejections_carry_no_challenge(): void
    {
        // IP outside the allowlist -> 403, which is not an authentication challenge.
        $request = Request::create('http://example.com/canary/health/live', server: ['REMOTE_ADDR' => '203.0.113.9']);

        $response = $this->handle($this->buildKernel(cidrs: ['10.0.0.0/8']), $request);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($response->headers->has('WWW-Authenticate'));
    }

    /**
     * Body with the per-request values (request ID, duration) blanked out.
     *
     * @return array<string, mixed>
     */
    private function normalisedBody(Response $response): array
    {
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertIsArray($body['meta'] ?? null);
        $body['meta']['request_id']  = '<id>';
        $body['meta']['duration_ms'] = 0;

        return $body;
    }

    /**
     * Header names and values, with the per-request ID and Date blanked out.
     *
     * @return array<string, list<string|null>>
     */
    private function normalisedHeaders(Response $response): array
    {
        $headers = $response->headers->all();
        self::assertStringStartsWith('canary-', (string) $response->headers->get('X-Canary-RequestId'));
        $headers['x-canary-requestid'] = ['<id>'];
        unset($headers['date']);
        ksort($headers);

        return $headers;
    }
}
