# SPDX-License-Identifier: Apache-2.0
# Chrome-enabled image for running the Laravel Dusk browser journeys (M5). NOT a runtime image — the
# baseline tier needs no browser. Pairs with docker/dusk/run.sh, which serves the app, starts the system
# ChromeDriver, and runs `php artisan dusk` headless.
FROM php:8.3-cli-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip ca-certificates curl \
        libzip-dev libonig-dev libsqlite3-dev \
        chromium chromium-driver \
        fonts-liberation libnss3 libatk-bridge2.0-0 libatk1.0-0 libcups2 \
        libdrm2 libgbm1 libgtk-3-0 libasound2 libxkbcommon0 libxdamage1 libxrandr2 \
    && docker-php-ext-install pdo_mysql pdo_sqlite mbstring zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Use the system Chromium + ChromeDriver (versions match, from the same Debian package set).
ENV DUSK_CHROME_BINARY=/usr/bin/chromium
ENV DUSK_DRIVER_URL=http://localhost:9515

WORKDIR /app
ENTRYPOINT ["bash", "docker/dusk/run.sh"]
