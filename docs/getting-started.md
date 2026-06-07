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

- **Create one now / download:** *Admin → System → Backups*, or `php artisan hearth:backup`. Download a copy
  off-host every so often — that's your insurance if the whole server is lost.
- **Restore — no SSH needed.** In *Admin → System → Backups*, click **Restore** on a backup. Because this
  overwrites the current database and uploaded files, it asks you to **type the backup's name** to confirm
  (admin + 2FA), then:
  1. A **pre-restore safety snapshot** of the *current* state is taken first, so the restore is itself
     reversible (it appears in the backups list).
  2. The site shows a brief, branded **maintenance page** while the restore runs from the cron line (within
     ~1 minute — the restore never runs inside your web request, so it isn't bound by PHP's time limit, and a
     restore can't half-serve the site). The archive's integrity (a SHA-256 in its manifest) is verified
     **before** anything is touched — a corrupt or foreign archive is refused without changing your data.
  3. You'll be **signed out** (the restore replaces the session table); sign back in when it's done. Watch
     progress without logging in at **`GET /health`** → the `restore` block (`requested`/`running`/`stuck`).
- **Restore from the command line** (if you have shell access) — the same safety pipeline:

  ```bash
  php artisan hearth:restore storage/backups/hearth-YYYYmmdd-HHMMSS.zip
  ```

  A restore is **single-attempt and fail-safe**: if it can't finish, the site stays in maintenance
  (`/health` → `restore.stuck: true`) rather than serving a half-restored database — it is never
  auto-retried. **To recover without a shell** (the panel is gated while held): delete
  `storage/hearth-restore.json` via FTP / your host file manager (the same kind of deliberate filesystem
  action that resets the installer), then sign in and restore a known-good backup — or the pre-restore safety
  snapshot named on the maintenance page — from *Admin → System → Backups*. With a shell, `php artisan
  hearth:restore <archive>` does it directly and clears the hold.

### Upgrading (no SSH needed)

To upgrade, **extract the new release zip over your existing install — that's the only step.** Hearth
notices the new code carries database changes and migrates itself from the same cron line that runs
everything else. Concretely, with `HEARTH_AUTO_UPGRADE=true` (the default):

1. You upload/extract the new version. For up to ~2 minutes the site may show a brief, branded
   **"Just a moment…"** maintenance page (it refreshes itself). This window is what stops a half-upgraded
   site from throwing database errors on a column the schema doesn't have yet.
2. On its next run (within one cron interval) the scheduler **takes a backup first** — your pre-upgrade
   restore point — then applies the pending migrations, clears caches, and lifts the maintenance page.
3. You're back, on the new version. The pre-upgrade snapshot is in *Admin → System → Backups*.

You can watch it happen without logging in: **`GET /health`** has a `schema` block —
`{"pending": …, "upgrading": …, "stuck": …}` — and `schema.pending` flips `true → false` as the upgrade
completes (no secrets, so it's safe to poll from a monitor).

**Manual mode.** Set `HEARTH_AUTO_UPGRADE=false` to apply upgrades yourself instead — via
*Admin → System → Upgrade* (**Apply pending migrations**, admin + 2FA + confirm) or
`php artisan hearth:upgrade`. **Note the asymmetry:** automatic mode applies the upgrade behind one clean
maintenance window; manual mode keeps the site live on a **partly-migrated schema**, so actions that touch
the new schema can error **until** you apply (e.g. saving a setting the new release adds, or — for a release
that drops/renames a column — pages that read it). The admin panel itself stays reachable so you can get
there and apply. Leave automatic mode on unless you specifically want manual control.

**If an upgrade gets stuck.** A failed migration holds the site in maintenance (it does **not** retry in a
loop) — `GET /health` shows `schema.stuck: true` and the maintenance page names the pre-upgrade backup. To
recover, no SSH required: **re-upload the previous release zip** (the code then matches the rolled-back
schema and the site comes back on its own within a cron tick). Once it's back, you can **restore the
pre-upgrade snapshot from *Admin → System → Backups*** (no SSH) — or, with shell access, restore it directly
with `php artisan hearth:restore storage/backups/hearth-…zip`. Because migrations are **reversible**, none of
these paths requires manual database surgery. *(The whole-site maintenance gate also covers a backup restore
in progress; the two never collide — and a restored older schema is migrated forward automatically by the
same auto-upgrade above.)*

---

## 6. Health checks

`GET /health` returns a compact JSON status (database, cache, queue freshness, tier, install state, a
`schema` upgrade block, and a `restore` block — see §5) for uptime monitoring — `200` when healthy, `503`
when the database is unreachable, `degraded` when an auto-upgrade **or** a restore is stuck. It exposes no
secrets.

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
