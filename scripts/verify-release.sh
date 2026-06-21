#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
#
# Acceptance test for a release bundle. Run in a PHP 8.3 env:
#
#   docker run --rm -v "$PWD:/src" -w /src forum-app:latest sh scripts/verify-release.sh /src/novfora-release.zip
#
# Three parts:
#   1. Filesystem assertions on the extracted tree — bootstrap/cache/packages.php PRESENT (the RH-1 fix), the
#      env-specific caches (services/config/routes/events) + per-host artifacts ABSENT, and the runtime-required
#      trees that are easy to drop from the allowlist PRESENT: lang/ (i18n), public/icons/ (PWA manifest icons),
#      and the first-party modules/ + themes/ content (see release-checklist-1.0.md "Deploy artifact must include").
#   2. A TRULY COLD HTTP boot: extract -> php -S with bootstrap/cache exactly as shipped and a minimal env
#      (APP_KEY empty, no DB) -> GET / -> assert 302 -> /install. Crucially it NEVER runs `php artisan` first
#      (the old verify did, which regenerated the manifest and masked the missing-packages.php bug). This is
#      the exact thing a fresh no-SSH host does on the operator's first visit.
#   3. An i18n RESOLVE probe (lib/i18n-probe.php): boots the extracted tree and resolves auth.login.* to its
#      localized labels — proves lang/ resolves at runtime, not just that it shipped. /login can't be used (it
#      redirects to /install pre-install), so resolving a key directly is the robust cold check for the Fix-2 gap.
set -eu

ZIP="${1:?usage: verify-release.sh <zip> [port]}"
PORT="${2:-8123}"
SELF="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
# Clean up the cold-boot php -S server (if still running) + the temp tree. GUARDED: only kill when SV names a
# real PID — an unguarded `kill ${SV:-0}` becomes `kill 0` once SV is reset after the boot, which SIGTERMs the
# whole process group and makes a PASS run exit 143 (a CI gate on the exit code would misread PASS as failure).
trap 'if [ -n "${SV:-}" ]; then kill "$SV" 2>/dev/null || true; fi; rm -rf "$WORK"' EXIT
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
ck public/favicon.ico
# PWA: every icon the web manifest (PwaController::manifest) references must ship — public/icons/ was the
# second confirmed deploy gap (manifest + apple-touch-icon 404 without it). This is the exact manifest set.
ck public/icons/icon-192.png
ck public/icons/icon-512.png
ck public/icons/maskable-512.png
ck public/icons/novfora.svg
# i18n: the ROOT lang/ tree must ship or the host renders raw auth.*/forum.* tokens (the Fix-2 deploy gap).
# Shipping is asserted here; runtime RESOLUTION is proven by the i18n probe after the cold HTTP boot below.
ck lang/en/auth.php
ck lang/es/auth.php
# First-party content trees — the shipped module + theme contracts must not vanish from the artifact.
ck modules/novfora/qa/module.json
ck modules/novfora/qa/src/QaServiceProvider.php
ck themes/aurora/theme.json
ck themes/nebula/theme.json
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
printf 'APP_NAME=NovFora\nAPP_ENV=production\nAPP_KEY=\nAPP_DEBUG=false\nAPP_URL=http://localhost\nLOG_CHANNEL=stderr\n' > "$APP/.env"
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

echo ">> i18n runtime resolve (boots the extracted tree, resolves auth.login.* -> 'Sign in' / 'Email')"
# Proves lang/ both SHIPPED and RESOLVES at runtime. /login is unreachable pre-install (RedirectIfNotInstalled
# sends it to /install), so a direct key resolve is the robust cold proof — a dropped lang/ returns the raw
# dotted token and fails here. Runs AFTER the filesystem assertions so a boot-written services.php can't taint
# the absent-services.php check above.
php "$SELF/lib/i18n-probe.php" "$APP"
i18n=$?

echo "=== RESULT ==="
if [ "$fail" -eq 0 ] && [ "$boot" -eq 0 ] && [ "$i18n" -eq 0 ]; then
  echo "RELEASE_VERIFY=PASS"
else
  echo "RELEASE_VERIFY=FAIL (assert_fail=$fail cold_boot_rc=$boot i18n_rc=$i18n)"
  exit 1
fi
