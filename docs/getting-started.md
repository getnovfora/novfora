<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Getting started with Hearth

Hearth is a self-hosted forum that runs on an ordinary **shared PHP host** — no SSH, no Node runtime, no
background daemons. This guide takes you from an empty host to a running community, then shows how to
light up the optional "enhanced" services when you outgrow the baseline.

- **Baseline tier** — a shared host with **PHP 8.3+**, **MySQL/MariaDB**, and **cron**. Everything works
  here; this is the default.
- **Enhanced tier** — a VPS/Docker host that adds Redis, Meilisearch, Reverb, and S3/MinIO. The *same
  code* runs on both — enhanced services are detected, never required (ADR-0003).

---

## 1. Requirements

| Need | Baseline (shared host) | Notes |
|---|---|---|
| PHP | **8.3+** (8.4 recommended) | extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `fileinfo`, `zip`, plus `pdo_mysql` |
| Database | MySQL 8 / MariaDB | PostgreSQL is supported on the enhanced tier |
| Cron | one entry | drives the queue, email, search, trust levels, and backups |
| Image thumbnails | **GD or Imagick** | optional but recommended — without it, uploaded images aren't resized into thumbnails |

You do **not** need Node.js on the host — Hearth ships prebuilt assets.

---

## 2. Install with the web installer (no SSH)

1. Upload the Hearth files to your web root (or a subdirectory) via your host's file manager or FTP.
2. Point your domain's document root at Hearth's **`public/`** directory.
3. Create an empty database in your host's control panel and note its name, user, and password.
4. Visit your site in a browser. Hearth detects it isn't installed yet and sends you to the **installer
   wizard** (`/install`), which walks you through:
   - a **system check** (PHP version, extensions, writable paths) + your detected deployment tier;
   - the **database** connection (with a "Test connection" button);
   - your **site name** and the **administrator** account;
   - a choice of a **demo community** or an empty start.
5. Click **Install**. The installer writes your settings, sets up the database, creates your admin, and
   then **locks itself** — it will not run again.

> **Security:** the installer is a one-time, self-locking step. Once installed it returns `403`, and there
> is no web route to re-open it — the only reset is removing the `storage/installed` file on the host.

### CLI install (VPS / SSH)

On a host with shell access you can install from the command line instead:

```bash
php artisan hearth:install \
  --name="My Community" --url="https://forum.example.com" \
  --db-database=hearth --db-username=hearth --db-password=secret \
  --admin-username=admin --admin-email=you@example.com \
  --demo
```

Omit any option to be prompted (the password prompts are hidden). Both paths run the same installer.

---

## 3. The one cron line (this is what makes the baseline tier work)

Add **one** cron entry in your host's control panel (ADR-0011):

```cron
* * * * * cd /path/to/hearth && php artisan schedule:run >> /dev/null 2>&1
```

That single line drives everything on the baseline tier:

- **Queued email** (reply/mention/moderation notifications) — drained in bounded batches;
- **Search indexing** and the **trust-level** recompute;
- **Anti-spam** retention/cleanup;
- **Automated backups** (see below).

No daemon or persistent worker is needed — every job is idempotent and correct within one cron interval,
even if your host only runs cron every 5–15 minutes.

---

## 4. Secure your admin account (2FA)

Administrator and moderator accounts **must** use two-factor authentication. On your first sign-in you'll
be guided to scan a TOTP QR code (any authenticator app) and save recovery codes. Until 2FA is enabled,
the admin panels stay locked.

---

## 5. Backups & restore

Backups run automatically from the cron line (daily by default — configurable via
`HEARTH_BACKUP_SCHEDULE=daily|weekly|off`). Each backup is a single `.zip` containing a database dump, a
copy of your uploaded files, and an integrity manifest. They're kept to the newest `HEARTH_BACKUP_KEEP`
(default 7).

- **Create one now / download:** *Admin → System → Backups*, or `php artisan hearth:backup`.
- **Restore** (destructive — overwrites the current DB + files; validates the archive first):

  ```bash
  php artisan hearth:restore storage/backups/hearth-YYYYmmdd-HHMMSS.zip
  ```

Because migrations are **reversible**, upgrading never requires manual database surgery — and a backup is
your safety net. The recommended upgrade rehearsal: take a backup, deploy the new version (it migrates
automatically), and if anything looks wrong, `hearth:restore` returns you to the exact prior state.

---

## 6. Health checks

`GET /health` returns a compact JSON status (database, cache, queue freshness, tier, install state) for
uptime monitoring — `200` when healthy, `503` when the database is unreachable. It exposes no secrets.

---

## 7. Growing up: enabling the enhanced tier (no code change)

When your community outgrows a shared host, move to a VPS/Docker host and enable services by editing
`.env` — **no application code changes**. Each one is detected automatically (see *Admin → System →
Service Tier*, or `php artisan hearth:tier`).

| Service | What it unlocks | How to enable |
|---|---|---|
| **Redis** | Shared cache/session + a real queue worker (instead of the cron drain) | set `CACHE_STORE`/`SESSION_DRIVER`/`QUEUE_CONNECTION=redis`, run `php artisan queue:work` |
| **Meilisearch** | Instant, typo-tolerant search | `SCOUT_DRIVER=meilisearch` + `MEILISEARCH_HOST`/`MEILISEARCH_KEY` |
| **S3 / MinIO** | Object storage for uploads (stateless app, CDN-friendly) | `FILESYSTEM_DISK=s3` + the `AWS_*` keys |
| **Reverb** | Real-time notifications over WebSockets (Phase 4) | `BROADCAST_CONNECTION=reverb` + the `REVERB_*` keys |

The commented blocks in [`.env.example`](../.env.example) show every key. A Docker Compose reference for
local development is in [`docker-compose.yml`](../docker-compose.yml).

---

## 8. Reference

- **Configuration:** every key is documented in [`.env.example`](../.env.example).
- **Architecture & decisions:** [`ARCHITECTURE.md`](../ARCHITECTURE.md), [`DECISIONS.md`](../DECISIONS.md).
- **Theming:** [`docs/THEME-API.md`](THEME-API.md).
- **Live-host caveat:** the installer ships with requirement/writable-path probes and a host-compatibility
  checklist, but real shared-host validation across providers is an ongoing task — test on at least two
  real shared hosts before a production launch.
