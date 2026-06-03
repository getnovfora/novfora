#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Run the Laravel Dusk browser journeys against the REAL app (M5). Serves the app with prebuilt assets on
# a file-backed SQLite DB, starts the system ChromeDriver + headless Chromium, and runs `php artisan dusk`.
#
# A dedicated .env is written for the run (the original is backed up and restored on exit) because
# `php artisan serve` re-reads .env per worker and does NOT forward arbitrary process env vars. Writing it
# means the serve process and the test process share ONE identical config: the same APP_KEY (so loginAs
# cookies decrypt across processes) and the same SQLite file (so both see the seeded data). Crucially,
# HEARTH_INSTALL_ENFORCE=false keeps the served app from redirecting to the installer.
set -euo pipefail
cd /app

[ -d vendor ] || composer install --no-interaction --no-progress --prefer-dist

APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"

# Back up any existing .env and restore it on exit.
[ -f .env ] && cp .env .env.dusk-backup
cleanup() {
  kill "${SERVE_PID:-}" "${CHROMEDRIVER_PID:-}" 2>/dev/null || true
  if [ -f .env.dusk-backup ]; then mv -f .env.dusk-backup .env; else rm -f .env; fi
}
trap cleanup EXIT

cat > .env <<EOF
APP_NAME=HearthDusk
APP_ENV=local
APP_KEY=${APP_KEY}
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
HEARTH_INSTALL_ENFORCE=false
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/dusk.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=array
SCOUT_DRIVER=database
BROADCAST_CONNECTION=log
EOF

export DUSK_DRIVER_URL=http://localhost:9515
export DUSK_CHROME_BINARY=/usr/bin/chromium

php artisan config:clear
php artisan view:clear
: > /app/database/dusk.sqlite
php artisan migrate:fresh --force

# Background: the system ChromeDriver + the app server (both read the .env written above).
chromedriver --port=9515 >/tmp/chromedriver.log 2>&1 &
CHROMEDRIVER_PID=$!
php artisan serve --host=127.0.0.1 --port=8000 >/tmp/serve.log 2>&1 &
SERVE_PID=$!

# Wait for the server to answer the health route.
for _ in $(seq 1 30); do
  curl -sf http://127.0.0.1:8000/up >/dev/null 2>&1 && break || sleep 1
done

set +e
php artisan dusk --without-tty
CODE=$?
set -e

if [ "$CODE" -ne 0 ]; then
  echo "================ serve.log (tail) ================"; tail -n 30 /tmp/serve.log || true
  echo "================ chromedriver.log (tail) ========="; tail -n 15 /tmp/chromedriver.log || true
fi

exit "$CODE"
