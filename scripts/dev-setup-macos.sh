#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 The Hearth Authors
#
# Hearth dev environment — macOS setup (Homebrew). Idempotent; safe to re-run.
# Installs the native toolchain (PHP 8.3+, Composer, Node 22, gh, Claude Code),
# runs MySQL 8 in Docker (via colima), then installs deps, migrates, and builds assets.
# After this finishes:  composer dev   →  http://localhost:8000
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

# 1) Homebrew --------------------------------------------------------------------
if ! command -v brew >/dev/null 2>&1; then
  say "Installing Homebrew"
  /bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
  eval "$([ -x /opt/homebrew/bin/brew ] && /opt/homebrew/bin/brew shellenv || /usr/local/bin/brew shellenv)"
fi

# 2) Toolchain -------------------------------------------------------------------
say "Installing toolchain (php, composer, node, git, gh, colima, docker)"
brew install php composer node git gh colima docker docker-compose

# Make `docker compose` (v2 plugin) available to the docker CLI under colima.
mkdir -p ~/.docker/cli-plugins
ln -sfn "$(brew --prefix)/opt/docker-compose/bin/docker-compose" ~/.docker/cli-plugins/docker-compose

# 3) Claude Code -----------------------------------------------------------------
if ! command -v claude >/dev/null 2>&1; then
  say "Installing Claude Code"
  curl -fsSL https://claude.ai/install.sh | bash
fi

# 4) Docker runtime (colima) + MySQL 8 ------------------------------------------
if ! colima status >/dev/null 2>&1; then
  say "Starting colima (Docker runtime)"
  colima start
fi
say "Starting MySQL 8 (Docker)"
docker compose up -d mysql
say "Waiting for MySQL to report healthy"
cid="$(docker compose ps -q mysql)"
for _ in $(seq 1 60); do
  [ "$(docker inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || echo starting)" = "healthy" ] && break
  sleep 2
done

# 5) App configuration -----------------------------------------------------------
say "Writing .env (matched to the Docker MySQL service)"
[ -f .env ] || cp .env.example .env
# BSD sed (macOS) needs the empty '' after -i
sed -i '' -E 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
sed -i '' -E 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
sed -i '' -E 's/^DB_PORT=.*/DB_PORT=3306/' .env
sed -i '' -E 's/^DB_DATABASE=.*/DB_DATABASE=hearth/' .env
sed -i '' -E 's/^DB_USERNAME=.*/DB_USERNAME=hearth/' .env
sed -i '' -E 's/^DB_PASSWORD=.*/DB_PASSWORD=secret/' .env

say "Installing PHP + JS dependencies"
composer install
npm install

# Only generate a key if one isn't already set (never rotate an existing key).
grep -qE '^APP_KEY=.+' .env || { say "Generating APP_KEY"; php artisan key:generate; }

say "Migrating the database"
php artisan migrate --force
php artisan storage:link 2>/dev/null || true

say "Building front-end assets"
npm run build

say "Done."
cat <<'EOF'

Next steps:
  • Authenticate (first time only):   gh auth login        claude   (then /login)
  • Run the app:                       composer dev    ->  http://localhost:8000
      (first visit redirects to /install — that's the installer; see docs/DEVELOPMENT.md
       to install a local forum or to work on the forum directly)
  • Tests & gates:                     composer test    vendor/bin/pint --test    vendor/bin/phpstan
  • Stop / wipe the DB:                docker compose stop mysql   |   docker compose down -v
EOF
