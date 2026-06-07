<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The Hearth Authors
-->
# Hearth — Real-Host Validation Runbook

> **Purpose.** A concrete, step-by-step procedure a **human** follows to install Hearth on **≥2 real shared
> hosts** and confirm the core promise — *safe self-hosting on cheap hosting* — actually holds. The Code
> session produced this runbook and the `hearth:doctor` preflight; the live install is the human step that
> follows (the build container has no real-host access).
>
> **Recommended targets:** (1) a **cPanel** host on **PHP 8.3** (e.g. a mainstream shared plan), and (2) a
> **budget** host (a no-frills `$3–5/mo` plan) — deliberately different, to surface host-specific gotchas.
>
> **What "pass" means:** the no-SSH wizard completes; the one cron line drives the tier; a member can
> register, sign in (admin with 2FA), post with the editor, upload an avatar; outbound mail works; `/health`
> is `ok`; a backup runs and restores. Capture any failure with the artifacts in §7.

---

## 0. Before you start — the 60-second security note

The web installer is **unauthenticated until it locks**. Whoever reaches `/install` first can take over the
site (point it at their DB, become admin). **Mitigation: install immediately after upload, in one sitting,
and confirm the installer has locked (`/install` returns 403) before walking away.** Do not upload the files
days before you intend to install. (Tracked as finding **F‑A** in [SECURITY-REVIEW.md](SECURITY-REVIEW.md).)

---

## 1. Host-compatibility checklist

Confirm (or discover) these before/while installing. `php artisan hearth:doctor` checks **all** of them
automatically if you have shell access; otherwise the installer's **System** step covers the hard ones, and
this table is your manual fallback.

| # | Requirement | Hard? | How to check | If missing |
|---|---|---|---|---|
| 1 | **PHP ≥ 8.3** | **Yes** | cPanel "Select PHP Version"; `php -v` | Switch the PHP version in the panel |
| 2 | Extensions: `pdo`, `mbstring`, `openssl`, `tokenizer`, `ctype`, `json`, `fileinfo`, `zip` | **Yes** | cPanel PHP extensions; `php -m` | Enable in the panel; ask support |
| 3 | `pdo_mysql` | **Yes** (MySQL) | `php -m` | Enable; or use SQLite for a tiny site |
| 4 | `gd` **or** `imagick` | No (warn) | `php -m` | Avatars/thumbnails won't resize; enable if possible |
| 5 | MySQL 8 / MariaDB database + user | **Yes** | cPanel → MySQL Databases | Create an empty DB + user, grant all |
| 6 | Writable: `.env`, `storage/`, `bootstrap/cache` | **Yes** | doctor / File Manager perms | `chmod 775` (or `755`) the dirs |
| 7 | **Cron** (one entry, ≥ every 5 min) | **Yes** | cPanel → Cron Jobs | Add the one line (§4) |
| 8 | `symlink()` allowed | No | doctor "Symlink support" | Copy fallback kicks in automatically |
| 9 | `proc_open` allowed | No | doctor "Disabled PHP functions" | Pure-PHP backups kick in automatically |
| 10 | `open_basedir` scope | No | doctor "open_basedir" | Keep app + storage paths inside it |
| 11 | Outbound mail (SMTP or sendmail) | No (warn) | doctor + `hearth:mail:test` | Use a transactional provider (§5) |

**Hearth is hardened so #8 and #9 are *not* blockers** — symlink-disabled hosts get a copied `public/storage`,
and `proc_open`-disabled hosts get pure-PHP database backups, both automatically.

---

## 2. Build a deployable bundle

The baseline host needs **no Node** (assets are prebuilt) and ideally **no Composer** (vendor bundled).

**Easiest — the committed builder** (does every step below, in the project's Docker image):

```bash
docker run --rm -v "$PWD:/src" -w /src forum-app:latest sh scripts/build-release.sh /src /src/hearth-release.zip
docker run --rm -v "$PWD:/src" -w /src forum-app:latest sh scripts/verify-release.sh /src/hearth-release.zip
```

**Or by hand**, on a build machine (or the Docker image):

```bash
# 1. PHP deps, production only, optimized autoloader
composer install --no-dev --optimize-autoloader

# 2. Frontend assets (already committed under public/build; rebuild only if you changed source)
npm ci && npm run build

# 3. THE COLD-BOOT FIX — pre-build the package manifest so a fresh host doesn't have to. Without
#    bootstrap/cache/packages.php a cold first boot (no prior artisan run) builds it during RegisterFacades,
#    BEFORE the `view` service registers; if bootstrap/cache isn't writable yet that throws and the page 500s
#    with "Target class [view] does not exist". This one command ships the manifest and removes that path.
HEARTH_INSTALL_ENFORCE=false php artisan package:discover --ansi

# 4. Drop env-specific / per-host caches so the bundle stays portable. SHIP packages.php; NEVER ship
#    services.php / config.php / routes.php / events.php (Laravel regenerates them per host at runtime).
rm -f bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes.php \
      bootstrap/cache/events.php bootstrap/cache/compiled.php

# 5. Zip the app EXCEPT local-only, per-host, and build-only files. vendor/, public/build/, AND
#    bootstrap/cache/packages.php ARE included. NEVER bundle storage/installed or storage/install-token.txt.
zip -r hearth-release.zip . \
  -x ".git/*" "node_modules/*" "tests/*" "docker/*" ".github/*" "docs/*" "scripts/*" \
     ".env" ".env.*" "auth.json" \
     "storage/logs/*" "storage/framework/cache/*" "storage/framework/sessions/*" "storage/framework/views/*" \
     "storage/installed" "storage/install-token.txt" "storage/backups/*" "storage/*.key" \
     "bootstrap/cache/services.php" "bootstrap/cache/config.php" "bootstrap/cache/routes.php" \
     "bootstrap/cache/events.php" "bootstrap/cache/compiled.php" \
     "database/*.sqlite" "hearth-release.zip"

# Laravel 500s if the empty runtime dirs are missing. If the excludes above dropped any, recreate them:
#   mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache && chmod -R 755 storage bootstrap/cache
```

You now have `hearth-release.zip` containing `vendor/`, `public/build/`, `bootstrap/cache/packages.php`, and the
app — uploadable to any host with no toolchain. (If your host *does* offer SSH + Composer, you can instead clone
the repo and run `composer install --no-dev && php artisan package:discover` there; the prebuilt assets are
committed.)

---

## 3. Upload & document root

1. Upload `hearth-release.zip` via cPanel **File Manager** (or SFTP) and **Extract** it. Put the app
   **above** the web root where you can (e.g. `~/hearth`), not inside `public_html`.
2. Point the site's document root at Hearth's **`public/`** directory. Two common ways:
   - **Preferred (subdomain/addon domain):** in cPanel set the domain's *Document Root* to `~/hearth/public`.
   - **Shared `public_html` only:** move the **contents** of `hearth/public/` into `public_html/`, then edit
     the two paths near the top of `public_html/index.php` to point at `__DIR__.'/../hearth/vendor/autoload.php'`
     and `__DIR__.'/../hearth/bootstrap/app.php'`. (Keeping the app out of the web root is the secure layout —
     only `public/` should be servable.)
3. Set safe permissions — **see §3a below** (required on CloudLinux/suEXEC). `storage/` and `bootstrap/cache/`
   must be **owner-writable**; nothing should be group/world-writable.

> **Never** let `.env`, `storage/`, or `vendor/` be directly web-served. With the document root on `public/`
> they aren't. Hearth writes `.env` as `0600` (owner-only). **Do not copy `docs/` or any project `*.md` into the
> web folder** — only `public/` should be servable.

---

## 3a. Set safe permissions (REQUIRED on CloudLinux/suEXEC — good practice everywhere)

cPanel's **Extract** often leaves files **0777** (world-writable). On CloudLinux/suEXEC hosts that causes a blank
**HTTP 500** (the server refuses to run group/world-writable code), and on any shared host world-writable files
let another account overwrite your code. **After extracting**, from the app root (e.g. `~/hearth`):

```bash
find . -type d -exec chmod 755 {} \;     # directories -> rwxr-xr-x
find . -type f -exec chmod 644 {} \;     # files       -> rw-r--r--
chmod 755 artisan                        # keep the CLI entry executable
```

`storage/` and `bootstrap/cache/` stay **owner-writable** at 755 (owner may write; group/other may not) — that is
all Hearth needs. With SSH, `php artisan hearth:doctor` flags any remaining group/world-writable paths.

> **Why `bootstrap/cache/` must be owner-writable:** the bundle ships the prebuilt `bootstrap/cache/packages.php`,
> so package discovery never runs on the host. But Laravel still writes a small `services.php` cache there on the
> first request. If `bootstrap/cache/` is not writable, that first page 500s with *"Target class [view] does not
> exist"* — the same symptom, one step later. Owner-writable (755) is enough; it does **not** need 777.

---

## 3b. Install into a `public_html` subfolder (e.g. `https://example.com/forum/`)

Prefer pointing a subdomain/addon-domain document root at `~/hearth/public` (§3.2 — cleanest). If you must serve
Hearth from a **subfolder** of an existing `public_html`, keep the app **outside** the web root and copy only
`public/`:

```bash
# app stays at ~/hearth (outside the web root); SUBDIR is the URL path segment, e.g. "forum"
mkdir -p ~/public_html/forum
cp -a ~/hearth/public/. ~/public_html/forum/      # copy the CONTENTS of public/ (incl. .htaccess)
```

Then:

1. **Edit `~/public_html/forum/index.php`** — repoint its two `require` paths up out of the web root to the app
   (paths are **case-sensitive**):
   ```php
   require __DIR__.'/../../hearth/vendor/autoload.php';
   $app = require_once __DIR__.'/../../hearth/bootstrap/app.php';
   ```
2. **Set the Site URL to include the subpath:** in the installer's **Site & administrator** step use
   `https://example.com/forum`, or set `APP_URL=https://example.com/forum` in `.env`.
3. **If routes 404,** add `RewriteBase /forum/` to `~/public_html/forum/.htaccess` (just under `RewriteEngine On`).
4. **Publish storage into the web dir** so avatars/images load:
   - symlink host: `ln -s ~/hearth/storage/app/public ~/public_html/forum/storage`
   - no-symlink host: `php artisan hearth:storage:publish` (writes a copy; the cron line refreshes it).

Re-apply the **§3a** permissions to the copied `~/public_html/forum/` as well, and (again) **do not** copy `docs/`
or any `*.md` into `public_html`.

---

## 4. Run the installer (the no-SSH path)

1. Visit `https://your-domain/` → you are redirected to **`/install`**.
2. **Step 1 — System check + setup token.** Every required row must be green (fix red items and re-check). The
   installer is locked to whoever can read a file on your server: open **`storage/install-token.txt`** via FTP
   or your host's File Manager, copy its contents, and paste it into the **Setup token** field. (This is what
   makes the unauthenticated installer safe in the upload window — finding `/install` first isn't enough; an
   attacker would also need filesystem access. With SSH: `cat storage/install-token.txt`, and
   `php artisan hearth:doctor` for the fuller host picture.) The token is single-use — consumed once you finish.
3. **Step 2 — Database.** Enter the MySQL host (usually `localhost` or `127.0.0.1`), port `3306`, and the DB
   name/user/password you created. Click **Test connection** → expect ✓.
4. **Step 3 — Site & administrator.** Community name, the public site URL (**use `https://`** so the secure
   cookie is enabled), and the admin username/email/password (≥10 chars, mixed case + a number).
5. **Step 4 — Install.** Click **Install Hearth**. The runner writes `.env`, migrates, seeds, creates the
   admin, publishes `public/storage`, and **locks last**.
6. **Confirm the lock:** reload `/install` → it must return **403**. ✔️ The site is now sealed.

**The one cron line** (shown on the final screen) — add it in cPanel → **Cron Jobs**, every minute (or every
5 minutes on a budget host that limits cron frequency):

```
* * * * * cd /home/USER/hearth && php artisan schedule:run >> /dev/null 2>&1
```

This single line drives the queue drain (mail/search/notifications), trust-level automation, the storage
mirror refresh, and backups. No daemon.

---

## 5. Mail & storage specifics

**Mail.** Edit `.env` (File Manager) for your host:
- **Host SMTP:** `MAIL_MAILER=smtp`, `MAIL_HOST`, `MAIL_PORT` (465/587), `MAIL_USERNAME`/`MAIL_PASSWORD`,
  `MAIL_FROM_ADDRESS` (a real mailbox on your domain so SPF/DKIM pass).
- **Transactional provider (recommended for deliverability):** SES/Postmark/Mailgun/Resend per
  [.env.example](../.env.example).
- **Verify:** with SSH, `php artisan hearth:mail:test you@example.com`. Without SSH, register a test user and
  confirm the verification email arrives (check spam). Mail is **queued** — it sends on the next cron tick.

**Storage (avatars/covers).** The installer runs the symlink-or-copy publisher automatically:
- If symlinks are allowed → `public/storage` is a live symlink (nothing to do).
- If **not** (many budget hosts) → `public/storage` is a **copy** that the cron line refreshes each tick. The
  install "done" screen tells you which. After a bulk change you can force a refresh with
  `php artisan hearth:storage:publish` (or just wait for cron).

---

## 6. Verify the install (acceptance checklist)

Run through these on **each** host and record pass/fail:

- [ ] `/install` returns **403** (locked).
- [ ] **Admin sign-in** works; you are guided through **2FA enrolment** on first admin visit.
- [ ] A **new member can register** and receives the **verification email**.
- [ ] A member can **create a topic and reply** using the WYSIWYG editor; formatting renders.
- [ ] A member can **upload an avatar** and it **displays** (proves storage publishing).
- [ ] **TL0 link suppression**: a brand-new member's links don't render as links (anti-spam gate).
- [ ] `GET /health` returns JSON `"status":"ok"` (and `"installed":true`).
- [ ] After ~2 minutes, `/health` shows the **queue heartbeat** fresh (proves cron is firing); or
      `hearth:doctor` "Cron" is green.
- [ ] A **backup runs**: with SSH `php artisan hearth:backup` produces a `.zip` under `storage/backups`; or
      wait for the daily cron and confirm the file appears. (Bonus: `hearth:restore <zip> --force` on a
      throwaway DB.)
- [ ] A **no-SSH restore works** (RH-11) — the full round-trip from §6b below: post a marker, restore an
      earlier backup from *Admin → System → Backups*, confirm the marker is gone and the site is healthy.
- [ ] **Private forum** attachments are **not** reachable by a logged-out user (IDOR fix H‑1) — set a forum's
      `forum.view` to NEVER for guests and confirm `/attachments/{id}` returns 403.

---

## 6a. Upgrading a live no-SSH install (RH-10)

The whole point of the baseline tier is that **upgrading is the same gesture as the first upload**: extract
the new release zip over the existing install and walk away. The cron line takes a backup, migrates, and
brings the site back — no SSH, no manual SQL. This section is the live validation for that mechanism
(ADR-0021); run it once you have a working install from §6.

**Do the upgrade.**
- Re-build the bundle from the new code (§2) and **extract it over your existing install** (overwrite
  `app/`, `public/build`, `vendor/`, etc.). Do **not** touch `.env`, `storage/installed`, or `storage/`.
  Nothing else — no installer, no commands.

**Watch it happen (no login needed).**
- Within ~2 minutes the site may briefly show a branded **"Just a moment…"** maintenance page (it
  auto-refreshes). On a 1-minute cron the window is ≤~2 minutes.
- Poll the health endpoint and watch the upgrade flip:

  ```bash
  curl -s https://your-site.example/health | python3 -m json.tool
  # schema.pending: true   → migrations are outstanding (you'll see the maintenance page)
  # schema.upgrading: true → the run is applying them right now
  # …then schema.pending: false → done; the site is back on the new version
  ```

**Acceptance checklist (record pass/fail on each host):**
- [ ] During the window, an end-user page returns a **branded 503 maintenance page**, not a 500 / SQL error.
- [ ] `/health` `schema.pending` flips **true → false** within ~2 minutes (and `schema.upgrading` is briefly
      true). `/health` itself stays reachable throughout.
- [ ] *Admin → System → Backups* shows a **pre-upgrade snapshot** (`hearth-<timestamp>.zip`) dated to the run.
- [ ] Sign in → **Settings → Appearance** works and dark/compact toggles persist (proves the new columns
      exist — i.e. the migration actually applied).
- [ ] *Admin → System → Upgrade* shows **"Last upgrade: Succeeded"** with the migration count; the **audit
      log** has an `upgrade.completed` entry.

**Manual mode (optional).** Set `HEARTH_AUTO_UPGRADE=false` in `.env` first, then upload. The site does **not**
auto-migrate; apply from *Admin → System → Upgrade* ("Apply pending migrations") or `php artisan hearth:upgrade`.
Note the asymmetry (§5 of getting-started): in manual mode signed-in pages may error on new columns until you
apply — auto mode is what shields them.

**If it gets stuck.** A failed migration holds the site in maintenance (`/health` → `schema.stuck: true`); it
does **not** retry in a loop. To recover without SSH, **re-upload the previous release zip** — the code then
matches the rolled-back schema and the site returns on its own within a cron tick. The maintenance page names
the pre-upgrade backup; with shell access you can `php artisan hearth:restore storage/backups/hearth-…zip`
instead. Capture the `upgrade.failed` audit entry and `storage/logs/laravel.log` for the report (§7).

---

## 6b. Restoring a backup from the panel (RH-11)

The companion to §6a: recovering — or rolling back — **without a shell**. The *Admin → System → Backups*
panel can now restore, behind the same backup-first, maintenance-safe machinery the auto-upgrade uses
(ADR-0022). Run this once you have a working install.

**Do the restore (no SSH).**
1. *Admin → System → Backups* → **Create backup** (call this the *restore point*), and **Download** it — get
   into the habit of keeping an off-host copy.
2. Make a **marker change** you'll recognise — post one throwaway topic.
3. Click **Restore** on the restore point. Confirm by **typing the backup's exact name** (admin + 2FA).

**Watch it happen (no login needed).**
- The site shows a branded **"Just a moment…"** maintenance page (it auto-refreshes). The restore runs from
  the cron line, so the window is ≤~2 minutes on a 1-minute cron — and it never times out a web request.
- Poll the health endpoint:

  ```bash
  curl -s https://your-site.example/health | python3 -m json.tool
  # restore.requested: true → queued   restore.running: true → restoring now
  # …then restore.running: false → done; the site is back, the marker post is gone
  ```

**Acceptance checklist (record pass/fail on each host):**
- [ ] During the window, an end-user page returns a **branded 503 maintenance page**, not a 500 / SQL error.
- [ ] `/health` `restore.running` flips **true → false** within ~2 minutes; `/health` itself stays reachable.
- [ ] After it's back, the **marker post is gone** (the earlier state was restored) and the site is healthy
      (`/health` `status: ok`, `/forums` renders).
- [ ] A **pre-restore safety snapshot** (`hearth-<timestamp>.zip`, dated to the restore) appears in the
      Backups list — restoring *it* returns you to the marker state (proves the restore is reversible).
- [ ] You were **signed out** during the restore (the session table was replaced) and can sign back in.
- [ ] The **audit log** has a `restore.completed` entry (actor + which backup).

**Restoring an older-schema backup (the RH-11 → RH-10 hand-off).** If the backup predates a schema change in
the running code, the restore brings back the older schema and then the **auto-upgrade migrates it forward
automatically** on the next cron tick (you'll see the maintenance page persist briefly as `schema.pending`
flips, per §6a). No action needed in automatic mode.

**If a restore gets stuck.** A restore is single-attempt and fail-safe: if it can't finish it holds the site
in maintenance (`/health` → `restore.stuck: true`) rather than serving a half-restored database — it is never
auto-retried. The maintenance page shows the recovery steps. **No-SSH recovery** (the panel is gated while
held): delete `storage/hearth-restore.json` via FTP / the file manager to lift the hold, then sign in and
restore a known-good backup — or the pre-restore safety snapshot it names — from *Admin → System → Backups*.
With shell access, `php artisan hearth:restore storage/backups/hearth-…zip` instead. Capture the
`restore.failed` audit entry and `storage/logs/laravel.log` for the report (§7).

---

## 7. Capturing & reporting failures

For any failure, capture:

1. **`hearth:doctor` output** (if SSH): `php artisan hearth:doctor` — the single most useful artifact.
2. **`/health` JSON**: `curl -s https://your-domain/health`.
3. **The error**: the on-screen wizard message, and (if reproducible) the relevant lines from
   `storage/logs/laravel.log`. **Redact** any secret before sharing.
4. **Host facts**: provider + plan, PHP version, `php -m` (extensions), and `php -i | grep -i
   disable_functions` / `open_basedir` if available.

Report each issue as: **host → step → expected vs actual → artifacts**. File against the repo so the fold-back
fix session can act on it.

---

## 8. Known host-specific gotchas (and how Hearth handles them)

| Symptom | Cause | Hearth's handling / your action |
|---|---|---|
| Avatars/images 404 after install | `symlink()` disabled | Automatic **copy** fallback; cron refreshes it. Confirm via `hearth:doctor`. |
| Backups never appear / "could not run mysqldump" | `proc_open`/`exec` disabled or no `mysqldump` | Automatic **pure-PHP** MySQL dump (set `HEARTH_BACKUP_DB_METHOD=php` to force). |
| Mail never arrives | Host blocks SMTP / no sendmail | Use a transactional provider; verify with `hearth:mail:test`. |
| `/health` queue heartbeat stale | Cron not added or too infrequent | Add the one cron line; budget hosts: every 5 min is fine. |
| White page / 500 on first visit | `storage/` or `bootstrap/cache` not writable | `chmod 775`; `hearth:doctor` flags it. |
| "Could not write env file" in the wizard | `.env`/project dir not writable | Make the project dir writable for the install, then it's `0600`. |
| Paths denied unexpectedly | `open_basedir` too narrow | Ensure the app + storage + backup paths are inside it. |
| PostgreSQL backup fails on shared host | `pg_dump` needs `proc_open` | PostgreSQL is an **enhanced-tier** DB; use MySQL/MariaDB on shared hosting. |

---

## 9. After both hosts pass

Record the two host profiles (provider, plan, PHP version, which fallbacks engaged) and attach the
`hearth:doctor` output from each. Hand the results + the [SECURITY-REVIEW.md](SECURITY-REVIEW.md) flagged
items to the fold-back session, which triages both and preps the focused fix kickoff toward **private beta**.
(An independent third-party security review and the full WCAG/load/i18n pass remain for the pre-1.0 "Path C"
work.)
