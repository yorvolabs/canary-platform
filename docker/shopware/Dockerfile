ARG NODE_VERSION=22
ARG PHP_VERSION=8.4

FROM node:${NODE_VERSION}-alpine AS node

FROM php:${PHP_VERSION}-fpm-alpine

# ─── System packages ───────────────────────────────────────────────────────────
RUN apk add --no-cache \
    nginx supervisor git unzip bash curl \
    # gd
    libpng-dev libjpeg-turbo-dev libwebp-dev freetype-dev \
    # intl
    icu-dev \
    # mbstring / zip / xml / sodium
    oniguruma-dev libzip-dev libxml2-dev libsodium-dev \
    # sockets extension
    linux-headers \
    # pecl / phpize
    $PHPIZE_DEPS \
    # mysql client (healthcheck + console)
    mysql-client \
    # needed for composer's git operations
    openssh-client \
    jq

# ─── PHP extensions required by Shopware 6.5 / 6.6 / 6.7 ─────────────────────
RUN docker-php-ext-configure gd \
        --with-freetype --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" \
    bcmath gd intl mbstring pdo_mysql \
    opcache pcntl sockets zip xml simplexml fileinfo sodium

RUN pecl install redis && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# ─── Node.js (pinned via build arg, default 22) ────────────────────────────────
# Copy the binary and the npm package; then create shell wrappers for npm/npx so
# that relative requires inside npm-cli.js resolve correctly from their own path.
COPY --from=node /usr/local/bin/node         /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN printf '#!/bin/sh\nexec node /usr/local/lib/node_modules/npm/bin/npm-cli.js "$@"\n' \
        > /usr/local/bin/npm \
 && printf '#!/bin/sh\nexec node /usr/local/lib/node_modules/npm/bin/npx-cli.js "$@"\n' \
        > /usr/local/bin/npx \
 && chmod +x /usr/local/bin/npm /usr/local/bin/npx

# ─── PHP config ────────────────────────────────────────────────────────────────
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
RUN echo "memory_limit=512M"       >> /usr/local/etc/php/conf.d/memory.ini \
 && echo "upload_max_filesize=32M" >> /usr/local/etc/php/conf.d/upload.ini \
 && echo "post_max_size=32M"       >> /usr/local/etc/php/conf.d/upload.ini

# ─── nginx + supervisor ────────────────────────────────────────────────────────
COPY docker/shopware/nginx.conf       /etc/nginx/http.d/default.conf
COPY docker/shopware/supervisord.conf /etc/supervisord.conf

# ─── Entrypoint ────────────────────────────────────────────────────────────────
COPY docker/shopware/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Shopware version is injected as a build arg so different versions share the
# same PHP layer and only differ in the Shopware application layer.
ARG SHOPWARE_VERSION=~6.6.0
ENV SHOPWARE_VERSION=${SHOPWARE_VERSION}

RUN git config --global --add safe.directory /var/www/html \
 && git config --global --add safe.directory /app/src/YorvoLabs/Canary \
 && git config --global --add safe.directory /app/src/YorvoLabs/Bridge/Shopware

ENV COMPOSER_ROOT_VERSION=1.0.0-dev

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
