#!/bin/sh
# SPDX-License-Identifier: Apache-2.0
#
# Build the deployable novfora-release.zip in a PHP 8.3+ env with Composer (e.g. the forum-app docker image).
# Assets (public/build) are built with Vite/Node; the forum-app image has no Node, so build assets on the HOST
# first, then run this script with SKIP_NPM=1 (it asserts public/build exists and skips the in-build npm):
#
#   npm ci && npm run build                                                    # on the host (has Node)
#   docker exec -e SKIP_NPM=1 -w /app forum-dev sh scripts/build-release.sh /app /app/novfora-release.zip
#
# If the build env itself has Node, omit SKIP_NPM and the script refreshes public/build on its own, e.g.:
#   docker run --rm -v "$PWD:/src" -w /src <php+node image> sh scripts/build-release.sh /src /src/novfora-release.zip
#
# Prebuilt assets (public/build) are shipped as-is — the host needs no Node. The CRITICAL fix vs. the first
# cut of this bundle: after `composer install` we run `php artisan package:discover`, so the package manifest
# bootstrap/cache/packages.php is BUILT and SHIPS in the zip. Without it, a cold first boot on a host whose
# bootstrap/cache is not yet writable throws while building the manifest during RegisterFacades — BEFORE any
# provider (including the `view` service) registers — surfacing as "Target class [view] does not exist".
#
# We ship ONLY packages.php from bootstrap/cache. config.php / routes.php / events.php / services.php are
# environment-specific and are (re)generated per host at runtime — baking them in would break portability.
set -eu

SRC="${1:-$(cd "$(dirname "$0")/.." && pwd)}"
OUT="${2:-$SRC/novfora-release.zip}"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

if [ "${SKIP_NPM:-0}" = "1" ]; then
  echo ">> SKIP_NPM=1 — assets pre-built on the host; skipping in-build npm (this env has no Node)"
  [ -d "$SRC/public/build" ] || { echo "FATAL: SKIP_NPM=1 but $SRC/public/build is missing — run 'npm ci && npm run build' on the host first"; exit 1; }
else
  echo ">> npm ci && npm run build  (refresh public/build assets before staging)"
  ( cd "$SRC" && npm ci && npm run build )
fi

echo ">> staging the allowlist from $SRC"
for d in app config resources routes; do cp -a "$SRC/$d" "$STAGE/$d"; done
mkdir -p "$STAGE/database"
cp -a "$SRC/database/migrations" "$STAGE/database/"
cp -a "$SRC/database/seeders"    "$STAGE/database/"
mkdir -p "$STAGE/bootstrap/cache"
cp -a "$SRC/bootstrap/app.php" "$SRC/bootstrap/providers.php" "$STAGE/bootstrap/"
cp -a "$SRC/bootstrap/cache/.gitignore" "$STAGE/bootstrap/cache/"
mkdir -p "$STAGE/public"
for f in .htaccess index.php robots.txt favicon.ico; do cp -a "$SRC/public/$f" "$STAGE/public/$f"; done
cp -a "$SRC/public/build" "$STAGE/public/"
for rel in app/.gitignore app/private/.gitignore app/public/.gitignore \
           framework/.gitignore framework/cache/.gitignore framework/cache/data/.gitignore \
           framework/sessions/.gitignore framework/testing/.gitignore framework/views/.gitignore \
           logs/.gitignore; do
  mkdir -p "$STAGE/storage/$(dirname "$rel")"
  cp -a "$SRC/storage/$rel" "$STAGE/storage/$rel"
done
for f in artisan composer.json composer.lock .env.example LICENSE README.md; do cp -a "$SRC/$f" "$STAGE/$f"; done

echo ">> composer install --no-dev --optimize-autoloader (production deps only)"
( cd "$STAGE" && COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --optimize-autoloader \
    --no-interaction --no-progress --no-scripts )

echo ">> php artisan optimize:clear  (drop any cached config/routes/views/events BEFORE discovery)"
# Invariant #4: ship a clean, portable cache state. This MUST run BEFORE package:discover — in Laravel 13 the
# optimize:clear `compiled` step (clear-compiled) also removes bootstrap/cache/packages.php, so package:discover
# has to be the LAST cache writer or the RH-1 manifest wouldn't ship. CACHE_STORE=array keeps cache:clear a
# no-op in this .env-less STAGE (the default 'database' store would fail on the absent DB). Tolerate a non-zero
# exit; the targeted rm below enforces the end state regardless.
( cd "$STAGE" && NOVFORA_INSTALL_ENFORCE=false CACHE_STORE=array php artisan optimize:clear --ansi ) || true

echo ">> php artisan package:discover  (generates bootstrap/cache/packages.php — the RH-1 fix; LAST cache writer)"
# NOVFORA_INSTALL_ENFORCE=false keeps the pre-install boot hook from minting a setup token / .env into STAGE.
( cd "$STAGE" && NOVFORA_INSTALL_ENFORCE=false php artisan package:discover --ansi )

echo ">> keep ONLY packages.php in bootstrap/cache (drop env-specific / runtime caches)"
rm -f "$STAGE/bootstrap/cache/services.php" \
      "$STAGE/bootstrap/cache/config.php" \
      "$STAGE/bootstrap/cache/routes.php" \
      "$STAGE/bootstrap/cache/events.php" \
      "$STAGE/bootstrap/cache/compiled.php"
# Belt-and-suspenders: a release must never carry per-host secrets / runtime state.
rm -f "$STAGE/.env" "$STAGE/storage/installed" "$STAGE/storage/install-token.txt"
find "$STAGE/storage/logs" -type f ! -name '.gitignore' -delete 2>/dev/null || true

[ -f "$STAGE/bootstrap/cache/packages.php" ] || { echo "FATAL: packages.php was not generated"; exit 1; }

echo ">> zipping -> $OUT"
rm -f "$OUT"
php "$SRC/scripts/lib/zip-dir.php" "$STAGE" "$OUT"

echo "=== BUILD COMPLETE ==="
ls -l "$OUT"
echo "sha256:           $(sha256sum "$OUT" | awk '{print $1}')"
echo "packages.php ships: $(unzip -l "$OUT" | grep -c 'bootstrap/cache/packages.php')"
echo "services.php ships: $(unzip -l "$OUT" | grep -c 'bootstrap/cache/services.php')  (must be 0)"
