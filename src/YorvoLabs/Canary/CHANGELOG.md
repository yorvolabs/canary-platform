# Changelog — yorvolabs/canary

All notable changes to this package are documented here.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-10-19

No functional changes; released alongside `yorvolabs/canary-bridge-shopware` 1.1.0.

### Changed

- Documentation, issue and support links now point to the `yorvolabs/canary-platform` monorepo
- Development dependencies updated (`composer audit` clean)

## [1.0.0] — 2026-05-08

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
