#!/bin/bash
# SPDX-License-Identifier: Apache-2.0
#
# In-place upgrade of an existing NovFora install on a Baseline (shared, SSH-less) host, driven by a one-shot
# cron job. It builds the release ON THE HOST from a pinned commit, then upgrades the live app:
#
#   1. clone getnovfora/novfora at <commit> and run scripts/build-release.sh (SKIP_NPM=1 — public/build is
#      committed, the host needs no Node)
#   2. back up first: `novfora:backup` (DB + storage) AND a tarball of the current code tree (minus storage)
#   3. maintenance mode, then swap the code trees from the release (app, bootstrap, config, database, lang,
#      resources, routes, vendor, public/build, public/icons, first-party modules/themes) — .env, storage/, the
#      public/storage link and any PRIVATE module (e.g. modules/novfora/populate) are never touched
#   4. optimize:clear, then `novfora:upgrade` (backup-first, resumable migrations — ADR-0021), then up
#   5. verify: no pending migrations, /health answers; on a failure BEFORE migrations the old code tree is
#      restored and the app brought back up; a failure DURING migrations leaves maintenance on (the runner's
#      pre-upgrade backup is the restore point) and says so
#
# Idempotent and overlap-safe for an every-minute cron: a lock dir prevents concurrent runs, and a done- or
# failed-marker per commit makes later ticks no-ops (delete failed-<sha> to retry), so the cron can be deleted
# at leisure.
#
# Usage: upgrade-in-place.sh <commit-sha> [app-dir] [public-url] [report-file]
#   report-file: where to copy the run log on exit (e.g. a web-DENIED folder readable through a panel file API).
set -uo pipefail

# Panel-managed cron may run with a minimal environment: derive HOME from the passwd entry when it is unset.
HOME="${HOME:-$(getent passwd "$(id -un)" | cut -d: -f6)}"
export HOME PATH="/usr/local/bin:/usr/bin:/bin:${PATH:-}"

SHA="${1:?usage: upgrade-in-place.sh <commit-sha> [app-dir] [public-url] [report-file]}"
APP="${2:-$HOME/novfora}"
URL="${3:-}"
REPORT="${4:-}"
# Relative arguments (handy for length-capped panel cron lines) resolve against the starting directory.
case "$APP" in /*) ;; *) APP="$PWD/$APP" ;; esac
case "$REPORT" in ""|/*) ;; *) REPORT="$PWD/$REPORT" ;; esac
REPO="https://github.com/getnovfora/novfora.git"
PHP="${PHP:-/usr/bin/php}"
WORK="$HOME/novfora-deploy"
TS="$(date -u +%Y%m%dT%H%M%SZ)"
LOG="$WORK/upgrade-$SHA.log"
DONE="$WORK/done-$SHA"
LOCK="$WORK/lock"

mkdir -p "$WORK"
if [ -n "$REPORT" ]; then
  # The report may live under a web root (so a panel file API can read it): deny HTTP access to its folder.
  mkdir -p "$(dirname "$REPORT")" && printf 'Require all denied\n' > "$(dirname "$REPORT")/.htaccess"
fi
[ -e "$DONE" ] && exit 0
# A failed run stops further cron ticks (no repeated backups/maintenance windows); delete the marker to retry.
[ -e "$WORK/failed-$SHA" ] && exit 0
mkdir "$LOCK" 2>/dev/null || exit 0
trap 'rmdir "$LOCK" 2>/dev/null; [ -z "$REPORT" ] || cp "$LOG" "$REPORT" 2>/dev/null' EXIT
exec >>"$LOG" 2>&1

say() { echo "[$(date -u +%H:%M:%S)] $*"; }
fail() { say "FAILED: $*"; touch "$WORK/failed-$SHA"; exit 1; }

say "=== NovFora in-place upgrade to $SHA (app: $APP) ==="
[ -f "$APP/artisan" ] && [ -f "$APP/.env" ] || fail "no NovFora install with a .env at $APP"
command -v git >/dev/null || fail "git not available"
if ! command -v composer >/dev/null; then
  # Shared hosts often lack composer on cron's PATH: fetch the stable phar, verified against its published SHA-256.
  mkdir -p "$WORK/bin"
  curl -fsSL -o "$WORK/bin/composer.phar" https://getcomposer.org/download/latest-stable/composer.phar \
    && want="$(curl -fsSL https://getcomposer.org/download/latest-stable/composer.phar.sha256 | awk '{print $1}')" \
    && [ -n "$want" ] && [ "$(sha256sum "$WORK/bin/composer.phar" | awk '{print $1}')" = "$want" ] \
    || fail "composer not available and the verified phar download failed"
  printf '#!/bin/sh\nexec "%s" "%s" "$@"\n' "$PHP" "$WORK/bin/composer.phar" > "$WORK/bin/composer"
  chmod +x "$WORK/bin/composer"
  export PATH="$WORK/bin:$PATH"
  say "using verified composer.phar ($(composer --version 2>/dev/null | head -1))"
fi
command -v unzip >/dev/null || fail "unzip not available"

# 1. Build the release on the host from the pinned commit.
SRC="$WORK/src-$SHA"
if [ ! -f "$WORK/release-$SHA.zip" ]; then
  rm -rf "$SRC"
  git clone --quiet --filter=blob:none "$REPO" "$SRC" || fail "clone"
  git -C "$SRC" checkout --quiet "$SHA" || fail "checkout $SHA"
  say "building release from $(git -C "$SRC" log --oneline -1)"
  SKIP_NPM=1 PATH="$(dirname "$PHP"):$PATH" sh "$SRC/scripts/build-release.sh" "$SRC" "$WORK/release-$SHA.zip" \
    || fail "build-release.sh"
fi
STAGE="$WORK/stage-$SHA"
rm -rf "$STAGE" && mkdir -p "$STAGE" && unzip -q "$WORK/release-$SHA.zip" -d "$STAGE" || fail "unzip release"
[ -f "$STAGE/bootstrap/cache/packages.php" ] && [ ! -e "$STAGE/.env" ] || fail "release artifact failed sanity checks"
[ ! -e "$STAGE/modules/novfora/populate" ] || fail "release must not contain the private populate module"

cd "$APP" || fail "cd $APP"
say "before: $(grep -m1 "'version'" config/app.php | tr -s ' ')"
"$PHP" artisan novfora:upgrade --check || true

# 2. Back up first: data + current code.
"$PHP" artisan novfora:backup || fail "novfora:backup (nothing changed yet)"
tar -czf "$WORK/code-before-$TS.tgz" --exclude=./storage -C "$APP" . || fail "code snapshot (nothing changed yet)"
say "backups taken: novfora:backup + $WORK/code-before-$TS.tgz"

rollback_code() {
  say "rolling back: restoring the moved-aside trees, then the code snapshot over everything else"
  for d in app config database lang resources routes vendor public/build public/icons; do
    if [ -d "$APP/$d.old" ]; then rm -rf "$APP/$d" && mv "$APP/$d.old" "$APP/$d"; fi
    rm -rf "$APP/$d.new"
  done
  tar -xzf "$WORK/code-before-$TS.tgz" -C "$APP" || say "WARNING: code snapshot extract failed"
  "$PHP" artisan optimize:clear || true
  "$PHP" artisan up || true
}

# 3. Maintenance + swap the shipped trees.
"$PHP" artisan down --retry=60 || fail "artisan down (nothing changed yet)"
SWAPPED=0
for d in app bootstrap config database lang resources routes vendor public/build public/icons; do
  [ -d "$STAGE/$d" ] || continue
  rm -rf "$APP/$d.new" && cp -a "$STAGE/$d" "$APP/$d.new" || { rollback_code; fail "stage $d"; }
done
for d in app bootstrap config database lang resources routes vendor public/build public/icons; do
  [ -d "$APP/$d.new" ] || continue
  if [ "$d" = "bootstrap" ]; then
    # Keep the host's bootstrap/cache contents except the shipped packages.php (runtime caches are cleared below).
    cp -a "$APP/$d.new/app.php" "$APP/$d.new/providers.php" "$APP/bootstrap/" && \
      cp -a "$APP/$d.new/cache/packages.php" "$APP/bootstrap/cache/packages.php" && rm -rf "$APP/$d.new" \
      || { rollback_code; fail "swap bootstrap"; }
    continue
  fi
  rm -rf "$APP/$d.old" && { [ ! -e "$APP/$d" ] || mv "$APP/$d" "$APP/$d.old"; } && mv "$APP/$d.new" "$APP/$d" \
    || { rollback_code; fail "swap $d"; }
  SWAPPED=1
done
# First-party module/theme trees: overwrite in place (never delete — private modules live alongside).
for d in modules themes; do
  [ -d "$STAGE/$d" ] && cp -a "$STAGE/$d/." "$APP/$d/" || true
done
for f in artisan composer.json composer.lock .env.example; do cp -a "$STAGE/$f" "$APP/$f" || true; done
cp -a "$STAGE/public/index.php" "$STAGE/public/.htaccess" "$APP/public/" 2>/dev/null || true
say "code swapped"

# 4. Clear caches, migrate backup-first, come back up.
"$PHP" artisan optimize:clear || { rollback_code; fail "optimize:clear on the new code (rolled back)"; }
if ! "$PHP" artisan novfora:upgrade; then
  say "migrations failed — staying in maintenance. The upgrade runner's pre-upgrade backup is the restore point;"
  say "old code: $WORK/code-before-$TS.tgz. Fix, then re-run, or restore with: php artisan novfora:restore <backup>"
  touch "$WORK/failed-$SHA"; exit 1
fi
"$PHP" artisan up || true

# 5. Verify. novfora:upgrade can SKIP (e.g. not-installed, locked) with exit 0, so success is judged by the
#    pending-migration count afterwards, never by the exit code alone.
CHECK="$("$PHP" artisan novfora:upgrade --check 2>&1)"
PENDING_COUNT="$(printf '%s\n' "$CHECK" | grep -i 'Pending migrations' | grep -oE '[0-9]+' | tail -1)"
say "after: $(grep -m1 "'version'" config/app.php | tr -s ' ') — pending migrations: ${PENDING_COUNT:-?}"
if [ "${PENDING_COUNT:-1}" != "0" ]; then
  printf '%s\n' "$CHECK"
  say "migrations still pending after novfora:upgrade — NOT marking done (the app is up on the new code;"
  say "investigate the upgrade runner state above; old code: $WORK/code-before-$TS.tgz)"
  touch "$WORK/failed-$SHA"; exit 1
fi
if [ -n "$URL" ] && command -v curl >/dev/null; then
  code=$(curl -s -o "$WORK/health-$SHA.json" -w '%{http_code}' -m 20 "$URL/health?cb=$TS")
  say "health HTTP $code: $(head -c 400 "$WORK/health-$SHA.json")"
  home=$(curl -s -o /dev/null -w '%{http_code}' -m 20 "$URL/")
  say "home HTTP $home"
  if [ "$code" != "200" ] || [ "$home" != "200" ]; then
    say "post-upgrade probe failed — check the log above; old code: $WORK/code-before-$TS.tgz"
    touch "$WORK/failed-$SHA"; exit 1
  fi
fi
for d in app config database lang resources routes vendor public/build public/icons; do rm -rf "$APP/$d.old"; done
rm -rf "$STAGE" "$SRC"
touch "$DONE"
say "=== DONE: upgraded to $SHA ==="
