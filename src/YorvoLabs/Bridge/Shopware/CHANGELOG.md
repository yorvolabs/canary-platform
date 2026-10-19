# Changelog — yorvolabs/canary-bridge-shopware

All notable changes to this package are documented here.
This project adheres to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Admin setting for the diagnostics token (`tokenDiagnostics`, maps to `CANARY_TOKEN_DIAGNOSTICS`) — previously the only token that could only be set via environment variable.

### Fixed

- `CANARY_RATE_LIMIT_HEALTH`, `CANARY_RATE_LIMIT_MUTATION`, `CANARY_DEPLOY_QUEUE_THRESHOLD` and `CANARY_DEPLOY_FAILED_THRESHOLD` were ignored: plugin install saved the `config.xml` default values to the system config, which takes precedence over environment variables. The admin fields now start empty, so the environment variable (or the built-in default) applies until a value is set in the admin.

## [1.0.0] — 2026-05-08

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
- `MonologAuditLogger` — writes audit records to a dedicated `canary_audit` Monolog channel (30-day rotation)
- `FileBasedRateLimiter` — file-based sliding-window rate limiter using `flock()` for atomicity
- `MessengerQueueRepository` — DBAL abstraction over `messenger_messages` table
- `RegisterApiPrefixPass` — compiler pass registering the configured route prefix before kernel boot
- `RouteScopeWhitelist` — prevents `SalesChannelMappingException` for Canary routes
- CLI command `canary:token:generate` — generates cryptographically random 64-byte tokens
- Shopware `config.xml` — admin Settings form for tokens, IP ranges, deploy thresholds, rate limits
- Unit, functional, and integration test suites (PHPUnit 11, PHPStan level 8)
