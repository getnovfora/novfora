# SPDX-License-Identifier: Apache-2.0
# NovFora local-dev / CI PHP image. NOTE: this is dev/CI convenience, NOT the baseline runtime —
# the baseline tier is plain PHP 8.3 + MySQL + cron on a shared host (no daemons, no Docker required).
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates default-mysql-client \
        libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
