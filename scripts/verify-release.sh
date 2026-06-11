#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
#
# Acceptance test for a release bundle. Run in a PHP 8.3 env:
#
#   docker run --rm -v "$PWD:/src" -w /src forum-app:latest sh scripts/verify-release.sh /src/nevo-release.zip
#
# Two parts:
#   1. Filesystem assertions on the extracted tree — including bootstrap/cache/packages.php PRESENT (the RH-1
#      fix) and the env-specific caches (services/config/routes/events) + per-host artifacts ABSENT.
#   2. A TRULY COLD HTTP boot: extract -> php -S with bootstrap/cache exactly as shipped and a minimal env
#      (APP_KEY empty, no DB) -> GET / -> assert 302 -> /install. Crucially it NEVER runs `php artisan` first
#      (the old verify did, which regenerated the manifest and masked the missing-packages.php bug). This is
#      the exact thing a fresh no-SSH host does on the operator's first visit.
set -eu

ZIP="${1:?usage: verify-release.sh <zip> [port]}"
PORT="${2:-8123}"
SELF="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
trap 'kill ${SV:-0} 2>/dev/null || true; rm -rf "$WORK"' EXIT
APP="$WORK/app"
mkdir -p "$APP"

echo ">> extracting $ZIP"
unzip -q "$ZIP" -d "$APP"

fail=0
ck(){ if [ -e "$APP/$1" ]; then echo "PASS exists  : $1"; else echo "FAIL missing : $1"; fail=1; fi; }
ab(){ if [ -e "$APP/$1" ]; then echo "FAIL present : $1"; fail=1; else echo "PASS absent  : $1"; fi; }

echo ">> filesystem assertions"
ck vendor/autoload.php
ck public/build/manifest.json
ck public/index.php
ck public/.htaccess
ck artisan
ck composer.json
ck .env.example
ck bootstrap/cache/packages.php          # RH-1 fix: the package manifest ships so a cold boot needs no build
ck storage/framework/cache
ck storage/framework/sessions
ck storage/framework/views
ck storage/logs
ck bootstrap/cache
ab .env
ab storage/installed
ab storage/install-token.txt
ab bootstrap/cache/services.php          # env-specific: written per-host at runtime, must NOT ship
ab bootstrap/cache/config.php
ab bootstrap/cache/routes.php
ab bootstrap/cache/events.php
ab tests
ab docs
ab node_modules
echo "ASSERT_FAIL=$fail"

echo ">> COLD HTTP boot (php -S, NO artisan first; minimal env: APP_KEY empty, no DB)"
printf 'APP_NAME=NevoBB\nAPP_ENV=production\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=http://localhost\nLOG_CHANNEL=stderr\n' > "$APP/.env"
php -S 127.0.0.1:"$PORT" -t "$APP/public" >"$WORK/serve.log" 2>&1 &
SV=$!
php "$SELF/lib/cold-client.php" "http://127.0.0.1:$PORT/"
boot=$?
kill "$SV" 2>/dev/null || true
SV=
if [ "$boot" -ne 0 ]; then
  echo "--- php -S log (first 20) ---"
  sed -n '1,20p' "$WORK/serve.log"
fi

echo "=== RESULT ==="
if [ "$fail" -eq 0 ] && [ "$boot" -eq 0 ]; then
  echo "RELEASE_VERIFY=PASS"
else
  echo "RELEASE_VERIFY=FAIL (assert_fail=$fail cold_boot_rc=$boot)"
  exit 1
fi
