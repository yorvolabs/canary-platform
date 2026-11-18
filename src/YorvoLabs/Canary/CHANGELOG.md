# Changelog — yorvolabs/canary

All notable changes to this package are documented here.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- The "token too short" and "no token configured" error messages no longer tell you to run `bin/console canary:token:generate`, a command that only the Shopware bridge provides. They now suggest `php -r 'echo bin2hex(random_bytes(32));'` and point to the token requirements in `docs/authentication.md`. The hint is `ScopedToken::GENERATE_HINT`.

### Documentation

- README and getting-started: the core package contains the request guard, runners and response factory, but no controllers or routes.
- The token-generation instructions mark `canary:token:generate` as the Shopware plugin's command.
- `TokenScope` docblock: scopes are matched exactly, not ordered by privilege.

## [1.2.0] — 2026-11-18

### Changed

- **Failed authentication attempts now count toward the rate limit.** `RequestGuardSubscriber` checks the rate limit before the token, so the order is: route group (404) → HTTPS (403) → IP allowlist (403) → rate limit (429) → token (401 missing · 503 not configured · 401 wrong · 403 insufficient scope). Requests rejected by the allowlist do not touch rate-limit state. A `429` audit record has `scope: null`.
- **Passed requests are audited with the status actually sent.** The audit record for a request that passes the guard is now written on `kernel.response` (new listener `RequestGuardSubscriber::onKernelResponse`, priority -1024) with the response's status code, instead of a fixed `200` at guard time. Its `duration_ms` now covers the controller as well. If you register the subscriber as a `kernel.event_subscriber`, nothing changes in your wiring.
- `TokenAuthenticator` compares the presented token against every configured token without returning on the first match, and decides the result after the loop.
- `ScopedToken::matches()` compares SHA-256 digests with `hash_equals()`, so the comparison no longer depends on the configured token's length.
- A path under the route prefix that matches no endpoint group now answers `404`.

### Fixed

- `401` responses now send `WWW-Authenticate: Bearer`.

### Documentation

- Authentication flow rewritten to match the code (order and status codes), including the `ADMIN` exception and exactly which requests are audited.
- Token requirements: the 64-character minimum is a length check, and the comparison's timing behaviour is by design, not measured.
- API reference: the error envelope example matches the actual body (`meta.request_id`, `meta.duration_ms`).
- API reference: a wrong token returns `401`, not `403`.
- `SECURITY.md`: 1.2.x is the supported version.
- The 1.0.0 entry below carries the date of the `v1.0.0` tag.

## [1.1.0] — 2026-10-19

No functional changes; released alongside `yorvolabs/canary-bridge-shopware` 1.1.0.

### Changed

- Documentation, issue and support links now point to the `yorvolabs/canary-platform` monorepo
- Development dependencies updated (`composer audit` clean)

## [1.0.0] — 2026-10-19

### Added

- `HealthCheckInterface` + `HealthCheckRunner` — extensible health check aggregation
- `DeployHookInterface` + `DeployHookRunner` — pre/post deploy hook execution
- `DiagnosticsInterface` + `DiagnosticsRunner` — config validation checks
- `ConfigProviderInterface` — pluggable configuration overlay (env var fallback)
- `AuditLoggerInterface` — pluggable audit log destination
- `RateLimiterInterface` with `FileBasedRateLimiter` and `InMemoryRateLimiter` implementations
- `RequestGuardSubscriber` — Symfony kernel event subscriber enforcing token auth, IP allowlist, HTTPS, and rate limiting at priority 256
- `TokenAuthenticator` with constant-time `hash_equals` comparison
- `IpAllowlistChecker` with IPv4 and IPv6 CIDR support
- `ResponseFactory` producing standard JSON envelopes with `X-Canary-RequestId`, `X-Content-Type-Options`, and `Cache-Control` headers
- `PrometheusFormatter` — Prometheus exposition format v0.0.4
- `SensitiveValueMasker` — redacts tokens, passwords, keys, DSNs in strings
- `Options` value object built from environment variables with optional provider overlay
- `TokenScope` enum with `MONITORING`, `CACHE`, `DIAGNOSTICS`, `DEPLOY`, `ADMIN` scopes
- `DeployPhase` enum with `PRE` and `POST` values
- `HealthStatus` enum with `pass`, `warn`, `fail` values
- Unit, functional, and integration test suites (PHPUnit 11, PHPStan level 8)
