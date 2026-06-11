# Throwaway Spike 0 PHP environment for NevoBB — NOT production.
# PHP 8.3 + Composer + the extensions Laravel 13 needs for the SQLite spike.
# JS/Vite runs on the host (Node 24); this image is the PHP/Composer/Artisan/Pest side.
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates \
        libzip-dev libonig-dev libsqlite3-dev \
    && docker-php-ext-install pdo_sqlite mbstring zip \
    && rm -rf /var/lib/apt/lists/*

# Composer from the official image
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
