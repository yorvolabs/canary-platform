#!/bin/bash
# Shopware first-run installer + runtime starter.
# On first run: installs Shopware, adds our packages via path repos, activates plugin.
# On subsequent runs: updates .env with current runtime values and starts services.
set -euo pipefail

SW_DIR=/var/www/html
MARKER="${SW_DIR}/.canary-installed"

log() { echo "[entrypoint] $*"; }

wait_for_db() {
    log "Waiting for MySQL at ${DATABASE_URL}..."
    local host
    host=$(echo "${DATABASE_URL}" | sed -E 's|.*@([^:/]+).*|\1|')
    local port
    port=$(echo "${DATABASE_URL}" | sed -E 's|.*:([0-9]+)/.*|\1|')
    local user
    user=$(echo "${DATABASE_URL}" | sed -E 's|mysql://([^:]+):.*|\1|')
    local pass
    pass=$(echo "${DATABASE_URL}" | sed -E 's|mysql://[^:]+:([^@]+)@.*|\1|')

    for i in $(seq 1 30); do
        if mysqladmin ping -h "${host}" -P "${port}" -u "${user}" -p"${pass}" --skip-ssl --silent 2>/dev/null; then
            log "MySQL ready."
            return 0
        fi
        log "Waiting for MySQL ($i/30)..."
        sleep 2
    done
    echo "[entrypoint] ERROR: MySQL not available after 60s." >&2
    exit 1
}

write_env() {
    cat > "${SW_DIR}/.env" <<ENV
APP_ENV=${APP_ENV:-dev}
APP_DEBUG=${APP_DEBUG:-1}
APP_SECRET=${APP_SECRET:-$(openssl rand -hex 32)}
DATABASE_URL=${DATABASE_URL}
APP_URL=${APP_URL:-http://localhost}
SHOPWARE_ES_HOSTS=${SHOPWARE_ES_HOSTS:-}
CANARY_TOKEN_MONITORING=${CANARY_TOKEN_MONITORING:-}
CANARY_TOKEN_CACHE=${CANARY_TOKEN_CACHE:-}
CANARY_TOKEN_ADMIN=${CANARY_TOKEN_ADMIN:-}
CANARY_FEATURES=${CANARY_FEATURES:-}
CANARY_ROUTE_PREFIX=${CANARY_ROUTE_PREFIX:-canary}
ENV

    # .env.test pins values that must stay consistent across integration test runs,
    # regardless of what the developer has in .env. Symfony loads this file on top of
    # .env when APP_ENV=test, so the test suite never inherits a custom CANARY_ROUTE_PREFIX.
    cat > "${SW_DIR}/.env.test" <<ENVTEST
CANARY_ROUTE_PREFIX=canary
ENVTEST
}

install_shopware() {
    log "Installing Shopware ${SHOPWARE_VERSION} into ${SW_DIR}..."

    composer create-project "shopware/production:${SHOPWARE_VERSION}" "${SW_DIR}" \
        --no-interaction --prefer-dist

    cd "${SW_DIR}"

    # Add path repositories so composer resolves our packages from mounted volumes.
    # Uses symlink=true so edits to the source are immediately reflected.
    log "Configuring path repositories for local packages..."
    composer config repositories.canary-local \
        '{"type":"path","url":"/app/src/YorvoLabs/Canary","options":{"symlink":true}}'
    composer config repositories.bridge-local \
        '{"type":"path","url":"/app/src/YorvoLabs/Bridge/Shopware","options":{"symlink":true}}'

    composer require yorvolabs/canary:* yorvolabs/canary-bridge-shopware:* --no-interaction

    write_env

    # Create shopware.yaml before system:install so the kernel compiles with
    # use_app_secret=true from the start and never generates RSA key files.
    mkdir -p "${SW_DIR}/config/packages"
    cat > "${SW_DIR}/config/packages/shopware.yaml" <<YAML
shopware:
    api:
        jwt_key:
            use_app_secret: true
YAML

    # Register the canary_audit Monolog channel handler so MonologAuditLogger
    # writes to var/log/canary_audit.log instead of falling through to dev.log.
    cat > "${SW_DIR}/config/packages/monolog.yaml" <<MONOLOG
monolog:
    handlers:
        canary_audit:
            type: rotating_file
            path: "%kernel.logs_dir%/canary_audit.log"
            level: info
            max_files: 30
            channels: [canary_audit]
MONOLOG

    log "Running Shopware database installer..."
    bin/console system:install \
        --create-database \
        --shop-locale=en-GB \
        --shop-currency=EUR \
        --no-interaction

    # Fix permissions on any JWT key files system:install may have generated,
    # so php-fpm (www-data) can read them if use_app_secret is not honoured.
    chmod 644 "${SW_DIR}/config/jwt/"*.pem 2>/dev/null || true

    bin/console plugin:refresh
    bin/console plugin:install --activate YorvoLabsCanary

    log "Creating default admin user (admin / shopware)..."
    bin/console user:create admin \
        --admin \
        --firstName="Admin" \
        --lastName="Admin" \
        --email="admin@example.com" \
        --password="shopware" \
        --no-interaction || true

    # Canary rate-limit directory must be writable by php-fpm (www-data).
    mkdir -p "${SW_DIR}/var/canary/rate-limit"
    chmod 777 "${SW_DIR}/var/canary"
    chmod 777 "${SW_DIR}/var/canary/rate-limit"

    touch "${MARKER}"
    log "Shopware installation complete."
}

update_env() {
    cd "${SW_DIR}"
    log "Updating .env with current runtime values..."
    write_env
    # Clear compiled DI container so decorator/subscriber registrations are always fresh.
    # Stale containers silently miss services registered after the original compilation.
    log "Clearing Shopware cache..."
    rm -rf "${SW_DIR}/var/cache/dev_"* 2>/dev/null || true
    bin/console cache:clear --no-interaction 2>/dev/null || true
    # Re-create after cache clear — cache:clear may recreate var/ and wipe subdirs.
    mkdir -p "${SW_DIR}/var/canary/rate-limit"
    chmod 777 "${SW_DIR}/var/canary" "${SW_DIR}/var/canary/rate-limit"
}

# ── Main ───────────────────────────────────────────────────────────────────────
wait_for_db

if [ ! -f "${MARKER}" ]; then
    install_shopware
else
    update_env
    cd "${SW_DIR}"
    # Re-require packages in case they were updated on the host
    composer require yorvolabs/canary:* yorvolabs/canary-bridge-shopware:* \
        --no-interaction 2>/dev/null || true
    bin/console plugin:refresh 2>/dev/null || true
fi

log "Starting php-fpm + nginx..."
exec supervisord -c /etc/supervisord.conf
