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
#
# WHAT MUST SHIP (deploy artifact): the allowlist below must stage EVERY path the running app reads at
# runtime. Easy to drop, all load-bearing: lang/ (i18n — without it the host renders raw auth.*/forum.*
# tokens), public/icons/ (PWA web-manifest icons + apple-touch-icon), public/build/ (the Vite manifest +
# hashed assets), bootstrap/cache/packages.php (RH-1), and the first-party modules/ + themes/ trees. See
# docs/product/release-checklist-1.0.md -> "Deploy artifact must include"; verify-release.sh guards lang +
# icons (and packages.php / no-services.php) on the truly-cold artifact.
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
# lang/ ships with the other whole-tree dirs: it is the root Laravel translation dir (auth/forum/profiles/…).
# Drop it and the host renders raw __('auth.login.*') / __('forum.*') tokens instead of localized labels.
for d in app config lang resources routes; do cp -a "$SRC/$d" "$STAGE/$d"; done
mkdir -p "$STAGE/database"
cp -a "$SRC/database/migrations" "$STAGE/database/"
cp -a "$SRC/database/seeders"    "$STAGE/database/"
mkdir -p "$STAGE/bootstrap/cache"
cp -a "$SRC/bootstrap/app.php" "$SRC/bootstrap/providers.php" "$STAGE/bootstrap/"
cp -a "$SRC/bootstrap/cache/.gitignore" "$STAGE/bootstrap/cache/"
mkdir -p "$STAGE/public"
for f in .htaccess index.php robots.txt favicon.ico; do cp -a "$SRC/public/$f" "$STAGE/public/$f"; done
cp -a "$SRC/public/build" "$STAGE/public/"
# public/icons/ — the PWA web-manifest icons (PwaController emits asset('icons/icon-192.png') etc.) and the
# apple-touch-icon (asset('icons/novfora.svg') in the app layout). Omit it and every manifest icon 404s.
cp -a "$SRC/public/icons" "$STAGE/public/"
# First-party content trees — the semver'd module + theme contracts (CLAUDE.md). Tracked in git, shipped so a
# fresh deploy's ACP Modules page lists novfora/{hello,kudos,qa} and NOVFORA_THEME can select the aurora/nebula
# example themes. They autoload at runtime via ModuleLoader/ThemeManager (NOT composer) and carry no enabled
# state, so a default install activates none — nothing boots them until an admin opts in. Conditional so a
# checkout that legitimately lacks either tree still builds.
for d in modules themes; do
  if [ -d "$SRC/$d" ]; then cp -a "$SRC/$d" "$STAGE/$d"; fi
done
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
echo "lang/ ships:        $(unzip -l "$OUT" | grep -c 'lang/en/auth.php')  (i18n — must be 1)"
echo "public/icons ships: $(unzip -l "$OUT" | grep -c 'public/icons/')  (PWA manifest icons — must be >0)"
echo "modules/ ships:     $(unzip -l "$OUT" | grep -c 'modules/novfora/')  (first-party plugins)"
echo "themes/ ships:      $(unzip -l "$OUT" | grep -c 'themes/aurora/theme.json')  (example themes — must be 1)"
