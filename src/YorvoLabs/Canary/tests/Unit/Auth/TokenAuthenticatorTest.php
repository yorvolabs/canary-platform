<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use YorvoLabs\Canary\Auth\AuthFailureReason;
use YorvoLabs\Canary\Auth\ScopedToken;
use YorvoLabs\Canary\Auth\TokenAuthenticator;
use YorvoLabs\Canary\Auth\TokenScope;
use YorvoLabs\Canary\Exception\TokenNotConfiguredException;

final class TokenAuthenticatorTest extends TestCase
{
    private const MONITORING_TOKEN = 'monitoring-token-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const CACHE_TOKEN      = 'cache-token-bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const ADMIN_TOKEN      = 'admin-token-ccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @param array<string, ScopedToken> $overrides */
    private function makeAuthenticator(array $overrides = []): TokenAuthenticator
    {
        $defaults = [
            TokenScope::MONITORING->value => ScopedToken::fromEnv(TokenScope::MONITORING, self::MONITORING_TOKEN),
            TokenScope::CACHE->value      => ScopedToken::fromEnv(TokenScope::CACHE, self::CACHE_TOKEN),
            TokenScope::ADMIN->value      => ScopedToken::fromEnv(TokenScope::ADMIN, self::ADMIN_TOKEN),
        ];
        return new TokenAuthenticator(array_merge($defaults, $overrides));
    }

    private function requestWith(?string $token): Request
    {
        $request = Request::create('/canary/health/live');
        if ($token !== null) {
            $request->headers->set('Authorization', "Bearer {$token}");
        }
        return $request;
    }

    #[Test]
    public function it_fails_when_no_authorization_header_is_present(): void
    {
        $result = $this->makeAuthenticator()->authenticate($this->requestWith(null), TokenScope::MONITORING);
        self::assertTrue($result->failed());
        self::assertSame(AuthFailureReason::TOKEN_MISSING, $result->reason);
        self::assertSame(401, $result->httpStatus());
    }

    #[Test]
    public function it_fails_with_token_invalid_when_token_matches_nothing(): void
    {
        $result = $this->makeAuthenticator()->authenticate($this->requestWith(str_repeat('x', 64)), TokenScope::MONITORING);
        self::assertTrue($result->failed());
        self::assertSame(AuthFailureReason::TOKEN_INVALID, $result->reason);
        self::assertSame(401, $result->httpStatus());
    }

    #[Test]
    public function it_fails_with_scope_insufficient_when_token_is_for_a_different_scope(): void
    {
        $result = $this->makeAuthenticator()->authenticate($this->requestWith(self::CACHE_TOKEN), TokenScope::MONITORING);
        self::assertTrue($result->failed());
        self::assertSame(AuthFailureReason::SCOPE_INSUFFICIENT, $result->reason);
        self::assertSame(403, $result->httpStatus());
    }

    #[Test]
    public function it_passes_when_correct_scoped_token_is_provided(): void
    {
        $result = $this->makeAuthenticator()->authenticate($this->requestWith(self::MONITORING_TOKEN), TokenScope::MONITORING);
        self::assertTrue($result->passed);
        self::assertSame(TokenScope::MONITORING, $result->scope);
        self::assertNull($result->reason);
    }

    #[Test]
    public function it_passes_with_admin_token_for_any_scope(): void
    {
        $auth = $this->makeAuthenticator();
        foreach (TokenScope::cases() as $scope) {
            if ($scope === TokenScope::ADMIN) {
                continue;
            }
            $result = $auth->authenticate($this->requestWith(self::ADMIN_TOKEN), $scope);
            self::assertTrue($result->passed, "Admin token should pass for scope {$scope->value}");
            self::assertSame(TokenScope::ADMIN, $result->scope);
        }
    }

    #[Test]
    public function it_throws_when_neither_admin_nor_scope_token_is_configured(): void
    {
        $auth = new TokenAuthenticator([]);
        $this->expectException(TokenNotConfiguredException::class);
        $auth->authenticate($this->requestWith(str_repeat('a', 64)), TokenScope::MONITORING);
    }

    #[Test]
    public function it_fails_with_token_missing_when_bearer_value_is_empty(): void
    {
        $request = Request::create('/canary/health/live');
        $request->headers->set('Authorization', 'Bearer ');
        $result = $this->makeAuthenticator()->authenticate($request, TokenScope::MONITORING);
        self::assertTrue($result->failed());
        self::assertSame(AuthFailureReason::TOKEN_MISSING, $result->reason);
    }

    #[Test]
    public function it_fails_with_token_missing_when_basic_auth_is_used(): void
    {
        $request = Request::create('/canary/health/live');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');
        $result = $this->makeAuthenticator()->authenticate($request, TokenScope::MONITORING);
        self::assertTrue($result->failed());
        self::assertSame(AuthFailureReason::TOKEN_MISSING, $result->reason);
    }
}
