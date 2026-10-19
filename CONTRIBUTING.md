# Contributing

## Prerequisites

- PHP 8.2+
- Docker + Docker Compose
- `make` (GNU Make)
- `jq` (for the split workflow)

## Local setup

```bash
git clone https://github.com/yorvolabs/canary-platform.git
cd canary-platform
cp .env.example .env        # fill in tokens, or leave defaults (unit tests use phpunit.xml values)
make up                      # start php + mysql + redis containers
```

## Running tests

```bash
# Core library (yorvolabs/canary)
make test-canary             # unit + functional + integration

# Shopware bridge — unit and functional (no Shopware needed)
make test-bridge

# Shopware bridge — integration tests (requires Shopware container)
make up-shopware             # start the shopware profile
make test-bridge-integration
```

## Running the full CI suite locally

```bash
make ci
```

This mirrors what GitHub Actions runs: PHP-CS-Fixer check, PHPStan (level 8), and all tests for both packages.

## Code style

```bash
make cs-check   # dry-run — show violations
make cs-fix     # auto-fix
```

The project follows PER-CS2.0 with strict types and risky rules enabled (see `.php-cs-fixer.php`).

## Static analysis

```bash
make phpstan-canary
make phpstan-bridge
```

Both packages run at PHPStan level 8. New code must pass without baseline exceptions.

## Pull requests

1. Fork the repo and create a feature branch from `main`
2. Write tests for any new behaviour
3. Ensure `make ci` passes
4. Open a PR against `main` with a clear description of what changed and why

## Monorepo structure

```
src/
├── YorvoLabs/Canary/           # yorvolabs/canary — framework-agnostic core
└── YorvoLabs/Bridge/Shopware/  # yorvolabs/canary-bridge-shopware — Shopware 6.6+ plugin
docs/                        # shared documentation
```

Each sub-package is an independent Composer package. On every push to `main`, GitHub Actions splits both packages into their own repositories via the `split.yml` workflow.

## Reporting security vulnerabilities

See [SECURITY.md](SECURITY.md).
