ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli-alpine

# System packages
RUN apk add --no-cache \
    git \
    unzip \
    bash \
    icu-dev \
    $PHPIZE_DEPS \
    linux-headers

# PHP extensions
RUN docker-php-ext-install \
    intl \
    opcache \
    pcntl \
    sockets

# Redis (required for CacheAdapterCheck integration tests)
RUN pecl install redis && docker-php-ext-enable redis

# Optional: Xdebug (pass --build-arg XDEBUG=true to enable)
ARG XDEBUG=false
RUN if [ "$XDEBUG" = "true" ]; then \
    pecl install xdebug && docker-php-ext-enable xdebug; \
fi

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP config
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY docker/php/conf.d/xdebug.ini  /usr/local/etc/php/conf.d/xdebug.ini

RUN echo "memory_limit=512M"   >> /usr/local/etc/php/conf.d/memory.ini \
 && echo "error_reporting=E_ALL" >> /usr/local/etc/php/conf.d/errors.ini \
 && echo "display_errors=On"   >> /usr/local/etc/php/conf.d/errors.ini

# Fix git dubious-ownership warnings when the host volume is a different user
RUN git config --global --add safe.directory /app/src/YorvoLabs/Canary \
 && git config --global --add safe.directory /app/src/YorvoLabs/Bridge/Shopware

ENV COMPOSER_ROOT_VERSION=1.0.0-dev

CMD ["bash"]
