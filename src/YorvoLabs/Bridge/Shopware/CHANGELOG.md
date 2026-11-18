# Changelog — yorvolabs/canary-bridge-shopware

All notable changes to this package are documented here.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] — 2026-11-18

### Changed

- Requires `yorvolabs/canary` ^1.3: the rate limiter removes idle buckets, caps their number, limits IPv6 clients per /64, and on storage errors serves `/health/*` while the other groups answer `503`. Disk space for `var/canary/rate-limit/` is worth monitoring (see the core changelog).

## [1.2.0] — 2026-11-18

### Changed

- Requires `yorvolabs/canary` ^1.2: failed authentication attempts now count toward the rate limit, passed requests are audited with the status code actually sent, a path under the prefix that matches no group now answers `404`, and `401` responses now send `WWW-Authenticate` (see the core changelog).
- The admin audit view (`/api/_action/canary/audit`) reads `canary_audit.log` and, if that file does not exist, the newest `canary_audit-*.log` (the names Monolog's `rotating_file` handler uses).

### Fixed

- Documentation and docblocks said the audit log rotates with 30-day retention. The plugin's default handler is a plain stream to `var/log/canary_audit.log` without rotation; rotation is configuration you add (example in `docs/configuration.md`).
- The 1.0.0 entry below carries the date of the `v1.0.0` tag.

## [1.1.0] — 2026-10-19

### Added

- Admin setting for the diagnostics token (`tokenDiagnostics`, maps to `CANARY_TOKEN_DIAGNOSTICS`) — previously the only token that could only be set via environment variable.

### Fixed

- `CANARY_RATE_LIMIT_HEALTH`, `CANARY_RATE_LIMIT_MUTATION`, `CANARY_DEPLOY_QUEUE_THRESHOLD` and `CANARY_DEPLOY_FAILED_THRESHOLD` were ignored: plugin install saved the `config.xml` default values to the system config, which takes precedence over environment variables. The admin fields now start empty, so the environment variable (or the built-in default) applies until a value is set in the admin.

## [1.0.0] — 2026-10-19

### Added

- Shopware 6.6+ plugin class with `configureRoutes()`, `enrichPrivileges()` (`canary:viewer`), and `uninstall()` cleanup
- **Health controllers:** `LivenessController`, `ReadinessController`, `MetricsController`
  - Built-in health checks: `DatabaseCheck`, `CacheAdapterCheck`, `FilesystemCheck`, `ElasticsearchCheck`, `MessageQueueCheck`
  - Business metrics (time-windowed): orders, revenue, customers, active carts, newsletter sign-ups
  - Prometheus exposition format via `Accept: text/plain` content negotiation
- **Cache controllers:** `OpcacheController`, `ShopwareCacheController`, `AdapterFlushController`
- **Deploy controllers:** `PreDeployController`, `PostDeployController`, `MaintenanceController`
- **Diagnostics controllers:** `EnvController`, `ConfigController`, `LogController`, `QueueController`, `PluginsController`
  - PII masking (email addresses, IPv4 addresses) in log output
  - Chunked reverse log reader — reads only the requested tail, not the entire file
- **Admin UI** — full Vue.js dashboard (Overview, Queue, Plugins, Logs, Tools tabs) with EN/DE translations
- `ShopwareConfigProvider` — reads tokens and settings from Shopware `SystemConfig` with env var fallback
- `MonologAuditLogger` — writes audit records to a dedicated `canary_audit` Monolog channel (`var/log/canary_audit.log`; no rotation by default)
- `FileBasedRateLimiter` — file-based sliding-window rate limiter using `flock()` for atomicity
- `MessengerQueueRepository` — DBAL abstraction over `messenger_messages` table
- `RegisterApiPrefixPass` — compiler pass registering the configured route prefix before kernel boot
- `RouteScopeWhitelist` — prevents `SalesChannelMappingException` for Canary routes
- CLI command `canary:token:generate` — generates cryptographically random 64-byte tokens
- Shopware `config.xml` — admin Settings form for tokens, IP ranges, deploy thresholds, rate limits
- Unit, functional, and integration test suites (PHPUnit 11, PHPStan level 8)
