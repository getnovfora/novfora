#!/usr/bin/env bash
# SPDX-License-Identifier: Apache-2.0
# Copyright 2026 The NevoBB Authors
#
# Hearth dev environment — Ubuntu/Debian setup (apt). Idempotent; safe to re-run.
# Installs the native toolchain (PHP 8.3+, Composer, Node 22, gh, Claude Code),
# runs MySQL 8 in Docker, then installs deps, migrates, and builds assets.
# After this finishes:  composer dev   →  http://localhost:8000
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
# Use docker with the active group, or fall back to sudo (the docker group is not
# live in this shell until you log out/in after install).
dc() { if docker info >/dev/null 2>&1; then docker "$@"; else sudo docker "$@"; fi; }

say "Updating apt + base packages"
sudo apt-get update -y
sudo apt-get install -y ca-certificates curl wget gnupg lsb-release software-properties-common git unzip
sudo mkdir -p -m 755 /etc/apt/keyrings

# 1) PHP 8.3 (+ extensions) ------------------------------------------------------
say "Adding the PHP repo (ondrej on Ubuntu, Sury on Debian)"
if grep -qi ubuntu /etc/os-release; then
  sudo add-apt-repository -y ppa:ondrej/php
else
  curl -fsSL https://packages.sury.org/php/apt.gpg | sudo tee /etc/apt/keyrings/sury-php.gpg >/dev/null
  echo "deb [signed-by=/etc/apt/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(lsb_release -sc) main" \
    | sudo tee /etc/apt/sources.list.d/sury-php.list >/dev/null
fi
sudo apt-get update -y
say "Installing PHP 8.3 + extensions"
sudo apt-get install -y \
  php8.3-cli php8.3-common php8.3-mbstring php8.3-xml php8.3-curl \
  php8.3-zip php8.3-gd php8.3-intl php8.3-mysql php8.3-bcmath php8.3-sqlite3

# 2) Composer --------------------------------------------------------------------
if ! command -v composer >/dev/null 2>&1; then
  say "Installing Composer"
  EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  [ "$EXPECTED" = "$ACTUAL" ] || { echo "Composer installer checksum mismatch — aborting."; exit 1; }
  sudo php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

# 3) Node.js 22 ------------------------------------------------------------------
if ! command -v node >/dev/null 2>&1 || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  say "Installing Node.js 22"
  curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
  sudo apt-get install -y nodejs
fi

# 4) Docker Engine + compose plugin ---------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
  say "Installing Docker"
  sudo apt-get install -y docker.io docker-compose-v2
  sudo systemctl enable --now docker || true
  sudo usermod -aG docker "$USER" || true
fi

# 5) GitHub CLI ------------------------------------------------------------------
if ! command -v gh >/dev/null 2>&1; then
  say "Installing GitHub CLI"
  wget -qO- https://cli.github.com/packages/githubcli-archive-keyring.gpg \
    | sudo tee /etc/apt/keyrings/githubcli-archive-keyring.gpg >/dev/null
  sudo chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg
  echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" \
    | sudo tee /etc/apt/sources.list.d/github-cli.list >/dev/null
  sudo apt-get update -y && sudo apt-get install -y gh
fi

# 6) Claude Code -----------------------------------------------------------------
if ! command -v claude >/dev/null 2>&1; then
  say "Installing Claude Code"
  curl -fsSL https://claude.ai/install.sh | bash
fi

# 7) MySQL 8 (Docker) ------------------------------------------------------------
say "Starting MySQL 8 (Docker)"
dc compose up -d mysql
say "Waiting for MySQL to report healthy"
cid="$(dc compose ps -q mysql)"
for _ in $(seq 1 60); do
  [ "$(dc inspect -f '{{.State.Health.Status}}' "$cid" 2>/dev/null || echo starting)" = "healthy" ] && break
  sleep 2
done

# 8) App configuration -----------------------------------------------------------
say "Writing .env (matched to the Docker MySQL service)"
[ -f .env ] || cp .env.example .env
sed -i -E 's/^DB_CONNECTION=.*/DB_CONNECTION=mysql/' .env
sed -i -E 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
sed -i -E 's/^DB_PORT=.*/DB_PORT=3306/' .env
sed -i -E 's/^DB_DATABASE=.*/DB_DATABASE=nevo/' .env
sed -i -E 's/^DB_USERNAME=.*/DB_USERNAME=nevo/' .env
sed -i -E 's/^DB_PASSWORD=.*/DB_PASSWORD=secret/' .env

say "Installing PHP + JS dependencies"
composer install
npm install

grep -qE '^APP_KEY=.+' .env || { say "Generating APP_KEY"; php artisan key:generate; }

say "Migrating the database"
php artisan migrate --force
php artisan storage:link 2>/dev/null || true

say "Building front-end assets"
npm run build

say "Done."
cat <<'EOF'

Next steps:
  • If Docker needed a group add, log out/in once (or run: newgrp docker) so `docker` works without sudo.
  • Authenticate (first time only):   gh auth login        claude   (then /login)
  • Run the app:                       composer dev    ->  http://localhost:8000
      (first visit redirects to /install — that's the installer; see docs/DEVELOPMENT.md
       to install a local forum or to work on the forum directly)
  • Tests & gates:                     composer test    vendor/bin/pint --test    vendor/bin/phpstan
  • Stop / wipe the DB:                docker compose stop mysql   |   docker compose down -v
EOF
