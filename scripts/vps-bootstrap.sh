#!/usr/bin/env bash
# =============================================================================
# NovFora VPS bootstrap  —  Ubuntu 24.04  |  Baseline + Enhanced  |  native gates
# PHP 8.3 pinned for the `dev` user only (existing 8.5 / FPM / other users untouched)
#
# SAFE TO RE-RUN. Idempotent and NON-DESTRUCTIVE: installs only what's missing,
# creates a dedicated NovFora DB + user, and never reconfigures the box's existing
# nginx / MySQL / Redis / firewall. Run as the `dev` user:
#
#     bash vps-bootstrap.sh
# =============================================================================
set -uo pipefail

### ---- config ---------------------------------------------------------------
REPO_SSH="git@github.com:getnovfora/novfora.git"   # NovFora platform repo
REPO_DIR="$HOME/novfora"
DB_NAME="novfora"
DB_TEST="novfora_test"
DB_USER="novfora"
CREDS_FILE="$HOME/novfora-credentials.txt"
PHP_V="8.3"
GIT_NAME="Tommy Huynh"
GIT_EMAIL="tommy@saturnhq.net"

### ---- helpers --------------------------------------------------------------
log(){  printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok(){   printf '   \033[1;32m[ok]\033[0m %s\n' "$*"; }
skip(){ printf '   \033[1;33m[skip]\033[0m %s\n' "$*"; }
warn(){ printf '   \033[1;31m[warn]\033[0m %s\n' "$*"; }
have(){ command -v "$1" >/dev/null 2>&1; }
genpass(){ tr -dc 'A-Za-z0-9' < /dev/urandom | head -c 32; }
# idempotent KEY=VALUE writer for .env (uses # as sed delimiter to allow URLs)
set_env(){ local k="$1" v="$2" f="$REPO_DIR/.env"
  if grep -q "^$k=" "$f" 2>/dev/null; then sed -i "s#^$k=.*#$k=$v#" "$f"; else echo "$k=$v" >> "$f"; fi; }

### ---- preflight ------------------------------------------------------------
log "Preflight"
. /etc/os-release 2>/dev/null || true
[ "${VERSION_ID:-}" = "24.04" ] || warn "Expected Ubuntu 24.04, found ${VERSION_ID:-unknown} — continuing anyway."
[ "$(whoami)" = "dev" ] || warn "Expected to run as 'dev', running as $(whoami) — continuing."
log "Caching sudo credentials (you'll be prompted once)"
sudo -v || { warn "sudo is required"; exit 1; }
( while true; do sudo -n true; sleep 50; kill -0 "$$" 2>/dev/null || exit; done ) 2>/dev/null &

umask 077
[ -f "$CREDS_FILE" ] || { echo "# NovFora credentials — generated $(date -u)"; } > "$CREDS_FILE"
chmod 600 "$CREDS_FILE"

### ---- PHP 8.3 (ondrej PPA, alongside existing 8.5) -------------------------
log "PHP $PHP_V"
if ! have "php$PHP_V"; then
  sudo apt-get install -y software-properties-common ca-certificates >/dev/null 2>&1
  grep -Rqs "ondrej/php" /etc/apt/sources.list.d/ || sudo add-apt-repository -y ppa:ondrej/php
  sudo apt-get update -y
  sudo apt-get install -y \
    "php$PHP_V-cli" "php$PHP_V-common" "php$PHP_V-curl" "php$PHP_V-mbstring" \
    "php$PHP_V-xml" "php$PHP_V-zip" "php$PHP_V-bcmath" "php$PHP_V-intl" \
    "php$PHP_V-gd" "php$PHP_V-mysql" "php$PHP_V-sqlite3" "php$PHP_V-redis" \
    "php$PHP_V-gmp" "php$PHP_V-soap" "php$PHP_V-readline" \
    && ok "php$PHP_V + extensions installed" || warn "php$PHP_V install hit an error"
else
  skip "php$PHP_V already installed"
  sudo apt-get install -y "php$PHP_V-redis" "php$PHP_V-intl" "php$PHP_V-mbstring" \
    "php$PHP_V-xml" "php$PHP_V-zip" "php$PHP_V-bcmath" "php$PHP_V-gd" \
    "php$PHP_V-mysql" "php$PHP_V-sqlite3" >/dev/null 2>&1 || true
fi

### ---- make `php` resolve to 8.3 for the `dev` user ONLY -------------------
# (system default, php-fpm, cron, and other users keep 8.5 — fully reversible)
log "Pinning php -> $PHP_V for user dev"
mkdir -p "$HOME/.local/bin"
ln -sf "/usr/bin/php$PHP_V" "$HOME/.local/bin/php"
grep -q 'HOME/.local/bin' "$HOME/.bashrc" 2>/dev/null || \
  printf '\n# NovFora: prefer PHP %s for this user\nexport PATH="$HOME/.local/bin:$PATH"\n' "$PHP_V" >> "$HOME/.bashrc"
export PATH="$HOME/.local/bin:$PATH"
ok "php now: $(php -v 2>/dev/null | head -n1)"

### ---- tmux -----------------------------------------------------------------
log "tmux"
have tmux && skip "tmux present" || { sudo apt-get install -y tmux && ok "tmux installed"; }

### ---- Claude Code CLI (user-global; no sudo, no root-owned files) ----------
log "Claude Code CLI"
if have claude; then
  skip "claude present: $(claude --version 2>/dev/null)"
else
  mkdir -p "$HOME/.npm-global"
  npm config set prefix "$HOME/.npm-global" >/dev/null 2>&1
  grep -q '.npm-global/bin' "$HOME/.bashrc" 2>/dev/null || \
    echo 'export PATH="$HOME/.npm-global/bin:$PATH"' >> "$HOME/.bashrc"
  export PATH="$HOME/.npm-global/bin:$PATH"
  npm install -g @anthropic-ai/claude-code && ok "Claude Code installed" || warn "Claude Code install failed"
fi

### ---- Meilisearch (Enhanced search; bound to localhost only) ---------------
log "Meilisearch"
if [ -x /usr/local/bin/meilisearch ]; then
  skip "meilisearch binary present"
else
  ( cd /tmp && curl -fsSL https://install.meilisearch.com | sh && sudo mv ./meilisearch /usr/local/bin/ ) \
    && ok "meilisearch binary installed" || warn "meilisearch download failed"
fi
# ensure the service user can execute it (our umask 077 can strip group/other +x)
[ -e /usr/local/bin/meilisearch ] && sudo chmod 0755 /usr/local/bin/meilisearch
id meilisearch >/dev/null 2>&1 || sudo useradd -r -s /usr/sbin/nologin meilisearch
sudo mkdir -p /var/lib/meilisearch
if grep -q '^MEILI_MASTER_KEY=' "$CREDS_FILE"; then
  MEILI_KEY="$(grep '^MEILI_MASTER_KEY=' "$CREDS_FILE" | cut -d= -f2-)"
else
  MEILI_KEY="$(genpass)"; echo "MEILI_MASTER_KEY=$MEILI_KEY" >> "$CREDS_FILE"
fi
sudo chown -R meilisearch:meilisearch /var/lib/meilisearch
if [ ! -f /etc/systemd/system/meilisearch.service ]; then
  sudo tee /etc/systemd/system/meilisearch.service >/dev/null <<UNIT
[Unit]
Description=Meilisearch (NovFora)
After=network.target

[Service]
User=meilisearch
Group=meilisearch
WorkingDirectory=/var/lib/meilisearch
ExecStart=/usr/local/bin/meilisearch --env production --master-key ${MEILI_KEY} --db-path /var/lib/meilisearch --http-addr 127.0.0.1:7700
Restart=on-failure

[Install]
WantedBy=multi-user.target
UNIT
  sudo systemctl daemon-reload
fi
[ -x /usr/local/bin/meilisearch ] && sudo systemctl enable --now meilisearch >/dev/null 2>&1 \
  && ok "meilisearch active on 127.0.0.1:7700 ($(systemctl is-active meilisearch))"

### ---- MySQL: dedicated NovFora DBs + user (Percona 8.4 already running) -----
log "MySQL databases & user"
if grep -q '^DB_PASSWORD=' "$CREDS_FILE"; then
  DB_PASS="$(grep '^DB_PASSWORD=' "$CREDS_FILE" | cut -d= -f2-)"
else
  DB_PASS="$(genpass)"; echo "DB_PASSWORD=$DB_PASS" >> "$CREDS_FILE"
fi
read -r -d '' MYSQL_SQL <<SQL || true
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS \`$DB_TEST\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_TEST\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
if printf '%s\n' "$MYSQL_SQL" | sudo mysql 2>/dev/null; then
  ok "DBs '$DB_NAME' + '$DB_TEST' and user '$DB_USER'@'localhost' ready"
else
  warn "Could not connect via 'sudo mysql' (root likely uses a password)."
  printf '%s\n' "$MYSQL_SQL" > "$HOME/novfora-db-setup.sql"
  warn "SQL saved to ~/novfora-db-setup.sql — run:  mysql -u root -p < ~/novfora-db-setup.sql  then re-run this script."
fi

### ---- Git identity + SSH deploy key ---------------------------------------
log "Git identity"
git config --global user.name "$GIT_NAME"
git config --global user.email "$GIT_EMAIL"
git config --global init.defaultBranch main
git config --global pull.ff only
ok "git as $GIT_NAME <$GIT_EMAIL>"

log "SSH key for GitHub"
if [ ! -f "$HOME/.ssh/id_ed25519" ]; then
  ssh-keygen -t ed25519 -C "$GIT_EMAIL (novfora-vps)" -f "$HOME/.ssh/id_ed25519" -N "" && ok "generated ~/.ssh/id_ed25519"
else
  skip "ssh key present"
fi
ssh-keyscan -t ed25519 github.com >> "$HOME/.ssh/known_hosts" 2>/dev/null
[ -f "$HOME/.ssh/known_hosts" ] && sort -u "$HOME/.ssh/known_hosts" -o "$HOME/.ssh/known_hosts" 2>/dev/null
echo
echo "   ----- ADD THIS PUBLIC KEY TO GITHUB (deploy key w/ write, or account SSH key) -----"
sed 's/^/   /' "$HOME/.ssh/id_ed25519.pub" 2>/dev/null
echo "   Repo keys: https://github.com/getnovfora/novfora/settings/keys"
echo "   -----------------------------------------------------------------------------------"

### ---- Clone repo -----------------------------------------------------------
log "Repo"
if [ -d "$REPO_DIR/.git" ]; then
  skip "repo already at $REPO_DIR"
elif git clone "$REPO_SSH" "$REPO_DIR" 2>/dev/null; then
  ok "cloned to $REPO_DIR"
else
  warn "Clone failed — add the deploy key above to GitHub, then re-run this script."
fi

### ---- App bring-up (only if the repo is present) ---------------------------
if [ -d "$REPO_DIR/.git" ]; then
  log "Composer install"
  ( cd "$REPO_DIR" && composer install --no-interaction --prefer-dist ) \
    && ok "composer install done" || warn "composer install failed"

  log ".env"
  if [ ! -f "$REPO_DIR/.env" ] && [ -f "$REPO_DIR/.env.example" ]; then
    cp "$REPO_DIR/.env.example" "$REPO_DIR/.env" && ok "created .env from example"
  else
    skip ".env already exists (values updated in place, not overwritten)"
  fi
  if [ -f "$REPO_DIR/.env" ]; then
    set_env APP_ENV local
    set_env APP_URL "http://localhost"   # keep Laravel's default — tests assert this; serve still runs on :8000
    set_env DB_CONNECTION mysql
    set_env DB_HOST 127.0.0.1
    set_env DB_PORT 3306
    set_env DB_DATABASE "$DB_NAME"
    set_env DB_USERNAME "$DB_USER"
    set_env DB_PASSWORD "$DB_PASS"
    set_env REDIS_HOST 127.0.0.1
    set_env REDIS_PORT 6379
    set_env CACHE_STORE redis
    set_env QUEUE_CONNECTION redis
    set_env SESSION_DRIVER redis
    set_env SCOUT_DRIVER meilisearch
    set_env MEILISEARCH_HOST "http://127.0.0.1:7700"
    set_env MEILISEARCH_KEY "$MEILI_KEY"
    # Reverb only if the package is actually in the tree
    if ( cd "$REPO_DIR" && composer show laravel/reverb >/dev/null 2>&1 ); then
      set_env BROADCAST_CONNECTION reverb
      set_env REVERB_HOST 127.0.0.1
      set_env REVERB_PORT 8080
      ok ".env set for Baseline + Enhanced (incl. Reverb)"
    else
      skip "laravel/reverb not installed — left BROADCAST default; add via: composer require laravel/reverb && php artisan reverb:install"
      ok ".env set for Baseline + Enhanced (DB/Redis/Meilisearch)"
    fi
  fi

  log "App key + migrate"
  ( cd "$REPO_DIR" && php artisan key:generate --force ) && ok "app key set" || warn "key:generate failed"
  ( cd "$REPO_DIR" && php artisan migrate --force )      && ok "migrations applied" || warn "migrate failed — check DB creds, then re-run"
else
  warn "Skipping app bring-up — repo not cloned yet."
fi

### ---- systemd: queue worker (+ Reverb unit template) ----------------------
log "Background services (systemd)"
PHP_BIN="/usr/bin/php$PHP_V"
if [ -d "$REPO_DIR/.git" ]; then
  if [ ! -f /etc/systemd/system/novfora-queue.service ]; then
    sudo tee /etc/systemd/system/novfora-queue.service >/dev/null <<UNIT
[Unit]
Description=NovFora queue worker
After=network.target redis-server.service mysql.service

[Service]
User=dev
WorkingDirectory=$REPO_DIR
ExecStart=$PHP_BIN artisan queue:work redis --sleep=1 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    sudo systemctl daemon-reload
  fi
  sudo systemctl enable --now novfora-queue >/dev/null 2>&1 \
    && ok "queue worker: $(systemctl is-active novfora-queue)" || warn "queue worker not started (check .env/migrate)"

  if [ ! -f /etc/systemd/system/novfora-reverb.service ]; then
    sudo tee /etc/systemd/system/novfora-reverb.service >/dev/null <<UNIT
[Unit]
Description=NovFora Reverb websocket server
After=network.target

[Service]
User=dev
WorkingDirectory=$REPO_DIR
ExecStart=$PHP_BIN artisan reverb:start --host=127.0.0.1 --port=8090
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    sudo systemctl daemon-reload
    ok "Reverb unit created (left disabled — enable after: composer require laravel/reverb && php artisan reverb:install)"
  fi
else
  skip "repo missing — skipped systemd units"
fi

### ---- summary --------------------------------------------------------------
log "Done — summary"
echo "   PHP (dev shell): $(php -v 2>/dev/null | head -n1)    [8.5 still available as 'php8.5']"
echo "   Composer:        $(composer --version 2>/dev/null | head -n1)"
echo "   Claude Code:     $(claude --version 2>/dev/null || echo 'installed — start with: claude  then /login')"
echo "   Meilisearch:     $(systemctl is-active meilisearch 2>/dev/null)  (127.0.0.1:7700)"
echo "   Redis:           $(systemctl is-active redis-server 2>/dev/null)"
echo "   MySQL:           $(systemctl is-active mysql 2>/dev/null)"
echo "   Queue worker:    $(systemctl is-active novfora-queue 2>/dev/null)"
echo "   Repo:            $REPO_DIR"
echo "   Credentials:     $CREDS_FILE   (chmod 600 — DB + Meilisearch keys)"
echo
echo "   NEXT STEPS"
echo "   1. If the clone failed: add the SSH key above to GitHub, then re-run this script."
echo "   2. Authenticate Claude Code:  cd $REPO_DIR && claude   (run /login — uses your Max plan)"
echo "   3. Durable session:           tmux new -s novfora     (detach Ctrl-b d / reattach: tmux attach -t novfora)"
echo "   4. Run the gates natively:    cd $REPO_DIR && ./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan && composer audit"
echo "   5. Serve for testing:         php artisan serve --host=127.0.0.1 --port=8000   (reach it over Tailscale)"
echo
echo "   NOTE: your 'dev' shell's \`php\` is now 8.3. Open a NEW shell (or: source ~/.bashrc) so PATH takes effect."
echo "   Undo the pin anytime:  rm ~/.local/bin/php  and remove the PATH line in ~/.bashrc"
