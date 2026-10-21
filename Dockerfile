# syntax=docker/dockerfile:1
FROM php:8.4.26-fpm-bookworm@sha256:845c7ba3487a47607f12cd35d09ac0e0880e8703f92a54ad517b69faef136072 AS base
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libpq-dev libzip-dev libfcgi-bin unzip git \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql opcache zip pcntl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf
COPY docker/php/app.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/healthcheck /usr/local/bin/php-healthcheck
RUN mkdir -p var/cache var/log && chown -R www-data:www-data /app
HEALTHCHECK --interval=15s --timeout=3s --start-period=10s CMD ["php-healthcheck"]

FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM base AS dev
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer
USER www-data
CMD ["php-fpm"]

FROM dev AS dependencies
COPY --chown=www-data:www-data composer.json composer.lock symfony.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

FROM base AS runtime
RUN apt-mark manual libicu72 libpq5 libzip4 libfcgi-bin \
    && apt-get purge -y --auto-remove libicu-dev libpq-dev libzip-dev git unzip autoconf dpkg-dev g++ gcc make pkg-config re2c \
    && rm -rf /var/lib/apt/lists/*
ENV APP_ENV=prod APP_DEBUG=0 APP_RUNTIME_OPTIONS='{"disable_dotenv":true}'
COPY docker/php/runtime.ini /usr/local/etc/php/conf.d/zz-runtime.ini
COPY --chown=www-data:www-data . .
COPY --from=dependencies --chown=www-data:www-data /app/vendor /app/vendor
USER www-data
CMD ["php-fpm"]
