#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
#
# Run the Laravel Dusk browser journeys against the REAL app in TWO sequential serve passes (the RH-7
# follow-up — the enforce-ON harness split):
#
#   PASS 1 — INSTALLER (enforcement ON, no install marker, fresh DB): drives the FULL web installer wizard
#            (tests/Browser/InstallerWizardTest.php) against a served app with HEARTH_INSTALL_ENFORCE=true, so
#            every wire:click's Livewire POST flows through RedirectIfNotInstalled exactly like production —
#            the real-browser belt for RH-7. (The enforcement-ON FEATURE tests, InstallerEnforcedLivewireTest,
#            remain the authoritative regression; this adds the genuine in-browser proof.)
#   PASS 2 — APP (enforcement OFF, app reachable): drives the WYSIWYG editor journey
#            (tests/Browser/EditorJourneyTest.php) against a reachable app — unchanged behaviour.
#
# Why two passes (not one served app): the installer journey needs enforcement ON with NO install marker,
# while the editor journey needs /forums reachable — which that same enforcement would 302 to /install. So we
# serve TWICE, each pass with its own .env + DB + installer sandbox and NO shared state, under one ChromeDriver.
#
# A dedicated .env is written for each pass (the original is backed up and restored on exit) because
# `php artisan serve` re-reads .env per worker and does NOT forward arbitrary process env vars. Writing it
# means the serve process and the test process share ONE identical config: the same APP_KEY (so loginAs
# cookies decrypt across processes, and the Livewire hashed-update prefix is stable) and the same SQLite file
# (so both see the seeded data).
set -euo pipefail
cd /app

[ -d vendor ] || composer install --no-interaction --no-progress --prefer-dist

# One APP_KEY for both passes (kept stable so PASS 2's loginAs cookies decrypt across the serve/test split).
APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
SANDBOX=/app/storage/dusk-install

# Back up any existing .env and restore it on exit; reap background processes.
[ -f .env ] && cp .env .env.dusk-backup
SERVE_PID=
CHROMEDRIVER_PID=
cleanup() {
  kill "${SERVE_PID:-}" "${CHROMEDRIVER_PID:-}" 2>/dev/null || true
  if [ -f .env.dusk-backup ]; then mv -f .env.dusk-backup .env; else rm -f .env; fi
}
trap cleanup EXIT

export DUSK_DRIVER_URL=http://localhost:9515
export DUSK_CHROME_BINARY=/usr/bin/chromium

# One ChromeDriver shared by both passes.
chromedriver --port=9515 >/tmp/chromedriver.log 2>&1 &
CHROMEDRIVER_PID=$!

# ── helpers ──────────────────────────────────────────────────────────────────────────────────────────
start_serve() {                       # (re)reads the just-written .env; clears stale config/view first
  php artisan config:clear >/dev/null
  php artisan view:clear >/dev/null
  php artisan serve --host=127.0.0.1 --port=8000 >/tmp/serve.log 2>&1 &
  SERVE_PID=$!
  # /up is allowlisted by RedirectIfNotInstalled, so it answers even pre-install with enforcement ON.
  for _ in $(seq 1 30); do
    curl -sf http://127.0.0.1:8000/up >/dev/null 2>&1 && break || sleep 1
  done
}
stop_serve() {
  kill "${SERVE_PID:-}" 2>/dev/null || true
  wait "${SERVE_PID:-}" 2>/dev/null || true
  SERVE_PID=
}
dump_logs() {
  echo "================ serve.log (tail) ================"; tail -n 30 /tmp/serve.log || true
  echo "================ chromedriver.log (tail) ========="; tail -n 15 /tmp/chromedriver.log || true
}

# ── PASS 1 — INSTALLER (enforcement ON) ──────────────────────────────────────────────────────────────
# The served app enforces install (HEARTH_INSTALL_ENFORCE=true) with NO marker, so the wizard's own AJAX
# flows through the enforce middleware like production. Every install side effect (.env, marker, token,
# public-storage) points at a throwaway sandbox so the wizard never clobbers THIS .env, the dusk.sqlite, or a
# real marker; the wizard installs into a DISPOSABLE MySQL database (prepared empty below).
cat > .env <<EOF
APP_NAME=HearthDusk
APP_ENV=local
APP_KEY=${APP_KEY}
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000
HEARTH_INSTALL_ENFORCE=true
DB_CONNECTION=sqlite
DB_DATABASE=/app/database/dusk.sqlite
CACHE_STORE=file
SESSION_DRIVER=file
QUEUE_CONNECTION=sync
MAIL_MAILER=array
SCOUT_DRIVER=database
BROADCAST_CONNECTION=log
HEARTH_INSTALL_REQUIRE_TOKEN=true
HEARTH_INSTALL_TOKEN_PATH=${SANDBOX}/install-token.txt
HEARTH_INSTALL_ENV_PATH=${SANDBOX}/installed.env
HEARTH_INSTALL_MARKER=${SANDBOX}/installed
HEARTH_PUBLIC_LINK=${SANDBOX}/public-storage
HEARTH_DUSK_INSTALL_DB_HOST=mysql
HEARTH_DUSK_INSTALL_DB_NAME=hearth_install
HEARTH_DUSK_INSTALL_DB_USER=root
HEARTH_DUSK_INSTALL_DB_PASS=secret
EOF

# Fresh installer sandbox (no marker → un-installed). InstallerWizardTest mints its own setup token at the
# config-driven token_path (the served app reads the SAME path), so we only clear stale state here.
rm -rf "$SANDBOX"; mkdir -p "$SANDBOX/public-storage"

# A clean, empty MySQL database for the wizard to install INTO. Retry until the service answers.
for _ in $(seq 1 30); do
  if php -r '$pdo=new PDO("mysql:host=mysql","root","secret",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $pdo->exec("DROP DATABASE IF EXISTS hearth_install"); $pdo->exec("CREATE DATABASE hearth_install CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");' 2>/tmp/dbprep.log; then
    echo "prepared install database: hearth_install"; break
  fi
  sleep 2
done

: > /app/database/dusk.sqlite
php artisan migrate:fresh --force

start_serve
set +e
php artisan dusk --without-tty tests/Browser/InstallerWizardTest.php
INSTALLER_CODE=$?
set -e
[ "$INSTALLER_CODE" -ne 0 ] && dump_logs
stop_serve

# ── PASS 2 — APP / editor (enforcement OFF, app reachable) ───────────────────────────────────────────
# Reset the installer sandbox so PASS 1's marker/install can't leak into PASS 2, then serve a reachable app
# (enforcement off) for the editor journey — its unchanged config.
rm -rf "$SANDBOX"
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

: > /app/database/dusk.sqlite
php artisan migrate:fresh --force

start_serve
set +e
php artisan dusk --without-tty tests/Browser/EditorJourneyTest.php
EDITOR_CODE=$?
set -e
[ "$EDITOR_CODE" -ne 0 ] && dump_logs
stop_serve

# ── Result ───────────────────────────────────────────────────────────────────────────────────────────
echo "dusk passes — installer(enforce ON)=${INSTALLER_CODE}  editor(enforce OFF)=${EDITOR_CODE}"
if [ "$INSTALLER_CODE" -ne 0 ] || [ "$EDITOR_CODE" -ne 0 ]; then
  exit 1
fi
exit 0
