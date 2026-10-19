.PHONY: help \
        build build-xdebug build-shopware build-admin \
        up up-es up-shopware down restart \
        shell shell-shopware shell-mysql logs \
        install install-canary install-bridge-shopware update update-canary update-bridge-shopware outdated \
        test test-canary test-bridge-shopware test-integration test-integration-ratelimit test-integration-prod \
        test-unit-canary test-unit-bridge-shopware \
        test-functional-canary test-functional-bridge-shopware \
        test-filter-canary test-filter-bridge-shopware \
        test-coverage-canary test-coverage-bridge-shopware \
        stan stan-canary stan-bridge-shopware \
        cs-fix cs-fix-canary cs-fix-bridge-shopware \
        cs-check cs-check-canary cs-check-bridge-shopware \
        lint lint-canary lint-bridge-shopware \
        ci ci-canary ci-bridge-shopware \
        shopware-console shopware-plugin-refresh shopware-plugin-activate \
        shopware-test generate-token

.DEFAULT_GOAL := help

comma := ,

# ── Colours ───────────────────────────────────────────────────────────────────
BOLD  := $(shell tput bold 2>/dev/null || true)
RESET := $(shell tput sgr0 2>/dev/null || true)
CYAN  := $(shell tput setaf 6 2>/dev/null || true)
GREEN := $(shell tput setaf 2 2>/dev/null || true)
YELLOW := $(shell tput setaf 3 2>/dev/null || true)

# ── Docker compose helpers ────────────────────────────────────────────────────
COMPOSE    := docker compose
PHP        := $(COMPOSE) exec php
SW         := $(COMPOSE) --profile shopware exec shopware

# Package paths inside the container (mirrors the monorepo src/ structure).
canary-path         := src/YorvoLabs/Canary
bridge-shopware-path := src/YorvoLabs/Bridge/Shopware

# Run a command in a specific package directory inside the php container.
# Usage: $(call in,canary,vendor/bin/phpunit)  or  $(call in,bridge-shopware,...)
define in
$(PHP) sh -c "cd /app/$($(1)-path) && $(2)"
endef

# ─────────────────────────────────────────────────────────────────────────────
help: ## Show this help
	@echo ""
	@echo "  $(BOLD)canary-platform$(RESET) — unified tooling for yorvolabs/canary + yorvolabs/canary-bridge-shopware"
	@echo ""
	@echo "  $(CYAN)$(BOLD)Docker$(RESET)"
	@grep -E '^(build|build-xdebug|build-shopware|build-admin|up|up-es|up-shopware|down|restart|shell|shell-shopware|shell-mysql|logs):.*?## .*$$' \
		$(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-32s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)$(BOLD)Dependencies$(RESET)"
	@grep -E '^(install|install-canary|install-bridge-shopware|update|update-canary|update-bridge-shopware|outdated):.*?## .*$$' \
		$(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-32s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)$(BOLD)Testing$(RESET)"
	@grep -E '^(test|test-canary|test-bridge-shopware|test-integration|test-integration-ratelimit|test-integration-prod|test-unit-canary|test-unit-bridge-shopware|test-functional-canary|test-functional-bridge-shopware|test-filter-canary|test-filter-bridge-shopware|test-coverage-canary|test-coverage-bridge-shopware):.*?## .*$$' \
		$(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-32s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)$(BOLD)Code quality$(RESET)"
	@grep -E '^(stan|stan-canary|stan-bridge-shopware|cs-fix|cs-fix-canary|cs-fix-bridge-shopware|cs-check|cs-check-canary|cs-check-bridge-shopware|lint|lint-canary|lint-bridge-shopware|ci|ci-canary|ci-bridge-shopware):.*?## .*$$' \
		$(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-32s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(CYAN)$(BOLD)Shopware$(RESET)"
	@grep -E '^(shopware-console|shopware-plugin-refresh|shopware-plugin-activate|shopware-test|generate-token):.*?## .*$$' \
		$(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  $(GREEN)%-32s$(RESET) %s\n", $$1, $$2}'
	@echo ""
	@echo "  $(YELLOW)Examples:$(RESET)"
	@echo "    make up && make install && make test"
	@echo "    make test-filter-canary F=TokenAuthenticator"
	@echo "    make test-filter-bridge-shopware F=DatabaseCheckTest"
	@echo "    make shopware-console CMD='cache:clear'"
	@echo "    SHOPWARE_VERSION=~6.6.0 make build-shopware up-shopware"
	@echo ""

# ─────────────────────────────────────────────────────────────────────────────
# Docker
# ─────────────────────────────────────────────────────────────────────────────

build: ## Build php image (and mysql/redis which are pulled)
	$(COMPOSE) build php

build-xdebug: ## Build php image with Xdebug enabled
	$(COMPOSE) build --build-arg XDEBUG=true php

build-shopware: ## Build Shopware image (honours SHOPWARE_VERSION env var)
	$(COMPOSE) --profile shopware build shopware

build-admin: ## Build the Shopware Administration JS bundle inside the Docker container
	$(COMPOSE) --profile shopware exec shopware bin/build-administration.sh

up: ## Start php + mysql + redis (core dev, no Shopware)
	$(COMPOSE) up -d
	@echo "$(GREEN)Ready.$(RESET) Run: make shell"

up-es: ## Start php + mysql + redis + elasticsearch
	$(COMPOSE) --profile es up -d

up-shopware: ## Start all services including Shopware (first start installs SW, ~5 min)
	$(COMPOSE) --profile shopware up -d
	@echo "$(YELLOW)Shopware first-run install can take ~5 minutes. Follow with: make logs$(RESET)"

down: ## Stop and remove all containers
	$(COMPOSE) --profile shopware --profile es down

restart: down up ## Restart core services

shell: ## Open a bash shell in the PHP container
	$(COMPOSE) exec php bash

shell-shopware: ## Open a bash shell in the Shopware container
	$(SW) bash

shell-mysql: ## Open a MySQL client session
	$(COMPOSE) exec mysql mysql -u canary -pcanary canary_test

logs: ## Tail logs for all running services
	$(COMPOSE) logs -f

# ─────────────────────────────────────────────────────────────────────────────
# Dependencies
# ─────────────────────────────────────────────────────────────────────────────

install: install-canary install-bridge-shopware ## Install composer dependencies for both packages

install-canary: ## Install canary core dependencies
	$(call in,canary,composer install --prefer-dist --no-progress)

install-bridge-shopware: ## Install canary-bridge-shopware dependencies
	$(call in,bridge-shopware,composer install --prefer-dist --no-progress)

update: update-canary update-bridge-shopware ## Update dependencies for both packages

update-canary: ## Update canary core dependencies
	$(call in,canary,composer update)

update-bridge-shopware: ## Update canary-bridge-shopware dependencies
	$(call in,bridge-shopware,composer update)

outdated: ## List outdated dependencies for both packages
	@echo "$(BOLD)── canary ────────────────────────────────$(RESET)"
	$(call in,canary,composer outdated --direct)
	@echo "$(BOLD)── bridge-shopware ───────────────────────$(RESET)"
	$(call in,bridge-shopware,composer outdated --direct)

# ─────────────────────────────────────────────────────────────────────────────
# Testing
# ─────────────────────────────────────────────────────────────────────────────

test: test-canary test-bridge-shopware ## Run all tests for both packages (unit + functional)

test-canary: ## Run all canary core tests
	$(call in,canary,vendor/bin/phpunit)

test-bridge-shopware: ## Run unit + functional tests for bridge-shopware (no Shopware needed)
	$(call in,bridge-shopware,vendor/bin/phpunit --testsuite Unit$(comma)Functional)

test-integration: ## Run bridge-shopware integration tests against a running Shopware instance
	@echo "$(YELLOW)Ensure Shopware is running: make up-shopware$(RESET)"
	$(SW) sh -c "cd /var/www/html && \
		APP_ENV=test CANARY_ROUTE_PREFIX=canary php bin/console cache:clear -q && \
		APP_ENV=test CANARY_ROUTE_PREFIX=canary \
		/app/src/YorvoLabs/Bridge/Shopware/vendor/bin/phpunit \
		-c /app/src/YorvoLabs/Bridge/Shopware/phpunit.integration.xml"

test-integration-ratelimit: ## Run rate-limit exhaustion integration test (isolated, CANARY_RATE_LIMIT_HEALTH=1)
	@echo "$(YELLOW)Ensure Shopware is running: make up-shopware$(RESET)"
	$(SW) sh -c "cd /var/www/html && \
		APP_ENV=test CANARY_ROUTE_PREFIX=canary php bin/console cache:clear -q && \
		APP_ENV=test CANARY_ROUTE_PREFIX=canary CANARY_RATE_LIMIT_HEALTH=1 CANARY_ALLOWED_CIDRS='' \
		/app/src/YorvoLabs/Bridge/Shopware/vendor/bin/phpunit \
		-c /app/src/YorvoLabs/Bridge/Shopware/phpunit.integration.xml \
		--group rate-limit"

test-integration-prod: ## Run production-mode integration test (isolated, APP_ENV=prod)
	@echo "$(YELLOW)Ensure Shopware is running: make up-shopware$(RESET)"
	$(SW) sh -c "cd /var/www/html && \
		APP_ENV=prod php bin/console cache:clear -q && \
		APP_ENV=prod CANARY_ALLOWED_CIDRS='' \
		/app/src/YorvoLabs/Bridge/Shopware/vendor/bin/phpunit \
		-c /app/src/YorvoLabs/Bridge/Shopware/phpunit.integration.xml \
		--group prod"

test-unit-canary: ## Run only unit tests for canary core
	$(call in,canary,vendor/bin/phpunit --testsuite Unit)

test-unit-bridge-shopware: ## Run only unit tests for bridge-shopware
	$(call in,bridge-shopware,vendor/bin/phpunit --testsuite Unit)

test-functional-canary: ## Run functional tests for canary core
	$(call in,canary,vendor/bin/phpunit --testsuite Functional)

test-functional-bridge-shopware: ## Run functional tests for bridge-shopware
	$(call in,bridge-shopware,vendor/bin/phpunit --testsuite Functional)

# Usage: make test-filter-canary F=TokenAuthenticator
test-filter-canary: ## Run a filtered test in canary core. Usage: make test-filter-canary F=ClassName
	$(call in,canary,vendor/bin/phpunit --filter=$(F))

test-filter-bridge-shopware: ## Run a filtered test in bridge-shopware. Usage: make test-filter-bridge-shopware F=ClassName
	$(call in,bridge-shopware,vendor/bin/phpunit --filter=$(F))

test-coverage-canary: ## Generate HTML coverage report for canary core → src/YorvoLabs/Canary/.coverage/
	$(call in,canary,XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html .coverage)
	@echo "$(GREEN)Report: src/YorvoLabs/Canary/.coverage/index.html$(RESET)"

test-coverage-bridge-shopware: ## Generate HTML coverage report for bridge-shopware → src/YorvoLabs/Bridge/Shopware/.coverage/
	$(call in,bridge-shopware,XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-html .coverage)
	@echo "$(GREEN)Report: src/YorvoLabs/Bridge/Shopware/.coverage/index.html$(RESET)"

# ─────────────────────────────────────────────────────────────────────────────
# Code quality
# ─────────────────────────────────────────────────────────────────────────────

stan: stan-canary stan-bridge-shopware ## PHPStan static analysis for both packages

stan-canary: ## PHPStan for canary core
	$(call in,canary,vendor/bin/phpstan analyse --no-progress)

stan-bridge-shopware: ## PHPStan for bridge-shopware
	$(call in,bridge-shopware,vendor/bin/phpstan analyse --no-progress)

cs-fix: cs-fix-canary cs-fix-bridge-shopware ## Auto-fix coding style for both packages

cs-fix-canary: ## Auto-fix coding style for canary core
	$(call in,canary,vendor/bin/php-cs-fixer fix)

cs-fix-bridge-shopware: ## Auto-fix coding style for bridge-shopware
	$(call in,bridge-shopware,vendor/bin/php-cs-fixer fix)

cs-check: cs-check-canary cs-check-bridge-shopware ## Check coding style for both packages (no changes)

cs-check-canary: ## Check coding style for canary core
	$(call in,canary,vendor/bin/php-cs-fixer check --diff)

cs-check-bridge-shopware: ## Check coding style for bridge-shopware
	$(call in,bridge-shopware,vendor/bin/php-cs-fixer check --diff)

lint: lint-canary lint-bridge-shopware ## PHP syntax check for both packages

lint-canary: ## PHP syntax check for canary core
	$(PHP) sh -c "find /app/$(canary-path)/src /app/$(canary-path)/tests -name '*.php' \
		| xargs -I{} php -l {} | grep -v 'No syntax errors' || echo 'All files OK'"

lint-bridge-shopware: ## PHP syntax check for bridge-shopware
	$(PHP) sh -c "find /app/$(bridge-shopware-path)/src /app/$(bridge-shopware-path)/tests -name '*.php' \
		| xargs -I{} php -l {} | grep -v 'No syntax errors' || echo 'All files OK'"

# Full CI check — same gates as GitHub Actions
ci: ci-canary ci-bridge-shopware ## Run full CI suite for both packages (cs-check + stan + test)

ci-canary: ## Full CI for canary core
	@echo "$(BOLD)── CI: canary ──────────────────────────────────$(RESET)"
	$(call in,canary,vendor/bin/php-cs-fixer check --diff)
	$(call in,canary,vendor/bin/phpstan analyse --no-progress)
	$(call in,canary,vendor/bin/phpunit)

ci-bridge-shopware: ## Full CI for bridge-shopware (unit + functional only)
	@echo "$(BOLD)── CI: bridge-shopware ─────────────────────────$(RESET)"
	$(call in,bridge-shopware,vendor/bin/php-cs-fixer check --diff)
	$(call in,bridge-shopware,vendor/bin/phpstan analyse --no-progress)
	$(call in,bridge-shopware,vendor/bin/phpunit --testsuite Unit$(comma)Functional)

# ─────────────────────────────────────────────────────────────────────────────
# Shopware
# ─────────────────────────────────────────────────────────────────────────────

# Usage: make shopware-console CMD='cache:clear'
shopware-console: ## Run a Shopware console command. Usage: make shopware-console CMD='cache:clear'
	$(SW) sh -c "cd /var/www/html && bin/console $(CMD)"

shopware-plugin-refresh: ## Refresh plugin list in Shopware
	$(SW) sh -c "cd /var/www/html && bin/console plugin:refresh"

shopware-plugin-activate: ## Install and activate YorvoLabsCanary plugin
	$(SW) sh -c "cd /var/www/html && \
		bin/console plugin:install --activate YorvoLabsCanary && \
		bin/console cache:clear"

shopware-test: ## Run bridge-shopware integration tests inside Shopware (alias for test-integration)
	$(MAKE) test-integration

# Usage: make generate-token SCOPE=monitoring
# Requires Shopware to be running (the command is registered as a Shopware console command).
# Without Shopware: run `php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"` directly.
generate-token: ## Generate a Canary token via Shopware console. Usage: make generate-token SCOPE=monitoring
	$(SW) sh -c "cd /var/www/html && bin/console canary:token:generate $(SCOPE)"
