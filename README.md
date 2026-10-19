# YorvoLabs Canary — Monorepo

[![CI](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml/badge.svg)](https://github.com/yorvolabs/canary-platform/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Secure, auditable HTTP endpoints for health monitoring, cache management, and deployment automation.

## Packages

| Package | Description | Packagist |
|---|---|---|
| [`yorvolabs/canary`](src/YorvoLabs/Canary/) | Framework-agnostic core library (Symfony 7.4+) | [![Packagist](https://img.shields.io/packagist/v/yorvolabs/canary)](https://packagist.org/packages/yorvolabs/canary) |
| [`yorvolabs/canary-bridge-shopware`](src/YorvoLabs/Bridge/Shopware/) | Shopware 6.6+ plugin | [![Packagist](https://img.shields.io/packagist/v/yorvolabs/canary-bridge-shopware)](https://packagist.org/packages/yorvolabs/canary-bridge-shopware) |

## Documentation

Full documentation lives in [`docs/`](docs/index.md):

- [Getting Started](docs/getting-started.md)
- [API Reference](docs/api-reference.md)
- [Authentication](docs/authentication.md)
- [Configuration](docs/configuration.md)
- [Admin UI](docs/admin-ui.md)
- [Extending: Health Checks](docs/extending/health-checks.md)
- [Extending: Deploy Hooks](docs/extending/deploy-hooks.md)
- [Extending: New Bridge](docs/extending/new-bridge.md)
- [Deployment Checklist](docs/deployment/checklist.md)
- [CI/CD Integration](docs/deployment/ci-cd.md)

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full development setup.

```bash
cp .env.example .env    # fill in tokens (or leave defaults for unit tests)
make up                  # start Docker services
make test-canary         # run core library tests
make test-bridge         # run bridge unit + functional tests
```

## License

MIT — see [LICENSE](LICENSE).
