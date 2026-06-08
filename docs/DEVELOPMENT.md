<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Developing Hearth — local environment setup (macOS & Linux)

This guide gets a **full Hearth dev environment** running on a fresh machine, so you can work from any
computer. The layout: the **native PHP/Node toolchain on the host** (so Claude Code, `artisan`,
`composer`, and the test suite run directly) plus **MySQL 8 in Docker** (identical on every machine,
matches the shared-host baseline, disposable).

> Deploying Hearth to a *production* host is a different document: [getting-started.md](getting-started.md).
> Contribution standards (DCO, conventional commits, tests-with-every-feature): [CONTRIBUTING.md](../CONTRIBUTING.md).

---

## TL;DR (automated)

```bash
gh auth login                                   # first time on a new machine
gh repo clone echo5tech/hearth && cd hearth     # or: git clone https://github.com/echo5tech/hearth.git

./scripts/dev-setup-macos.sh                    # MacBook (Homebrew)
./scripts/dev-setup-linux.sh                    # Ubuntu/Debian laptop (apt)

composer dev                                    # server + queue + vite + logs → http://localhost:8000
```

The setup scripts are **idempotent** — safe to re-run any time. They install the toolchain, start MySQL 8
in Docker, write `.env` (matched to that database), install dependencies, generate the app key (only if
missing — never rotates an existing one), migrate, and build assets.

---

## What gets installed

| Tool | Version | Why |
|---|---|---|
| PHP | **8.3+** | runtime; extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `fileinfo`, `zip`, `pdo_mysql`, `intl`, `gd` |
| Composer | latest | PHP dependencies |
| Node.js | 22 LTS | Vite 8 asset builds (dev only — production ships prebuilt assets) |
| Docker | latest | runs MySQL 8 (`docker compose up -d mysql`); macOS uses [colima](https://github.com/abiosoft/colima) (no Docker Desktop needed) |
| MySQL | 8 (container) | the baseline database — db `hearth`, user `hearth`, password `secret`, `127.0.0.1:3306` |
| GitHub CLI | latest | clone, push, PRs (`gh auth login` once per machine) |
| Claude Code | latest | the coding agent (`claude`, then `/login` once per machine) |

The repo's committed `.claude/settings.json` (AI attribution off) and `CLAUDE.md` travel with the clone,
so Claude Code behaves identically on every machine.

---

## Manual setup (what the scripts do)

**macOS (Homebrew):**

```bash
brew install php composer node git gh colima docker docker-compose
mkdir -p ~/.docker/cli-plugins && \
  ln -sfn "$(brew --prefix)/opt/docker-compose/bin/docker-compose" ~/.docker/cli-plugins/docker-compose
colima start                                    # headless Docker runtime
curl -fsSL https://claude.ai/install.sh | bash  # Claude Code
```

**Ubuntu/Debian (apt):**

```bash
sudo add-apt-repository -y ppa:ondrej/php       # Debian: use the packages.sury.org/php repo instead
sudo apt-get update
sudo apt-get install -y php8.3-cli php8.3-{mbstring,xml,curl,zip,gd,intl,mysql,bcmath,sqlite3} \
                        docker.io docker-compose-v2 git
sudo usermod -aG docker "$USER"                 # then log out/in once
# Composer → https://getcomposer.org/download/ · Node 22 → NodeSource setup_22.x
# gh → https://cli.github.com · Claude Code → curl -fsSL https://claude.ai/install.sh | bash
```

**Then, on either OS (from the repo root):**

```bash
docker compose up -d mysql        # MySQL 8 on 127.0.0.1:3306 (hearth / hearth / secret)
cp .env.example .env              # then set DB_PASSWORD=secret
composer install && npm install
php artisan key:generate          # only on a fresh .env
php artisan migrate
php artisan storage:link
npm run build
```

---

## Running the app

```bash
composer dev      # php artisan serve + queue:listen + vite + pail, together → http://localhost:8000
```

A fresh dev site **redirects to `/install`** — the app enforces the web installer until installed
(`HEARTH_INSTALL_ENFORCE`, default on). That is the *real pre-install state*, which is exactly what you
want when working on installer bugs (e.g. RH-7). To get a usable forum instead, pick one:

- **Install it** (exercises the real install path, recommended):

  ```bash
  php artisan hearth:install --name="Dev" --url="http://localhost:8000" \
    --db-database=hearth --db-username=hearth --db-password=secret \
    --admin-username=admin --admin-email=dev@example.com --demo
  ```

- **Skip the installer** for a quick loop: set `HEARTH_INSTALL_ENFORCE=false` in `.env`, then
  `php artisan migrate:fresh --seed` for the demo community.

To re-test the installer later, remove the lock: `rm storage/installed` (CLI-only by design).

---

## Tests & quality gates

```bash
composer test               # Pest/PHPUnit (the full suite)
vendor/bin/pint --test      # code style
vendor/bin/phpstan          # static analysis (Larastan)
php artisan dusk            # browser tests (Chrome) — see note
```

**Dusk note:** the browser suite (WYSIWYG editor + full installer wizard) uses a disposable MySQL via
`docker/dusk/compose.yml` (see `docker/dusk/run.sh`) and needs Chrome/Chromium on the machine. Day-to-day
work only needs `composer test`; run Dusk before merging anything that touches the editor or installer.

---

## The database

```bash
docker compose up -d mysql    # start
docker compose stop mysql     # stop (keeps data)
docker compose down -v        # destroy (wipes the hearth-dbdata volume)
```

Credentials live in `docker-compose.yml` and `.env`: db `hearth`, user `hearth`, password `secret`,
`127.0.0.1:3306`. Want a zero-dependency quick loop instead? SQLite works:
`DB_CONNECTION=sqlite`, `DB_DATABASE=/absolute/path/database.sqlite`, `touch` the file, migrate. (MySQL
stays the parity target — run the suite against MySQL before merging DB-touching changes.)

---

## Working across machines

GitHub (`echo5tech/hearth`) is the source of truth:

```bash
git pull       # when you sit down at any machine
git push       # after committing (sign-off: git commit -s)
```

Claude Code on the web works against the same repo and returns its work as a **PR branch** — review,
merge, then `git pull` locally.

---

## Troubleshooting

- **Argon2 hashing error on `php artisan` commands** — your PHP build lacks libargon2: set
  `HASH_DRIVER=bcrypt` in `.env` (documented fallback).
- **`docker: permission denied` (Linux)** — log out/in once after setup (docker group), or `newgrp docker`.
- **Port 3306 already in use** — another MySQL is running; stop it, or change the published port in
  `docker-compose.yml` *and* `DB_PORT` in `.env`.
- **`claude` or `composer` not found right after install** — open a new shell; the installers extend your
  `PATH` via your shell profile.
- **Assets look stale after pulling** — `npm run build` (committed prebuilt assets are for *hosts*, not a
  substitute for rebuilding while developing — see RH-5 in
  [product/real-host-findings.md](product/real-host-findings.md)).
- **Windows/WSL: `Permission denied` on unlink/create that survives reboots and `icacls /reset`** — the
  "Docker-on-`/mnt/d` curse": files **created by a Docker container bind-mounted to the Windows drive**
  can be left with alien ownership/attributes that WSL cannot clear. Remedy ladder, in order:
  (1) stop all containers / quit Docker Desktop (open handles); (2) PowerShell as Admin:
  `takeown /F <path> /R /D Y` + `icacls <path> /reset /T /C`; (3) if a path *still* refuses,
  `Remove-Item -Recurse -Force` the affected **directory** (e.g. `public\build\assets` — all tracked;
  git recreates it) and `git reset --hard`. Prevention: run build tooling with the worktree on
  WSL-native disk (`~/`), never bind-mount `/mnt/d` into containers.
