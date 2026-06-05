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

The baseline host needs **no Node** (assets are prebuilt) and ideally **no Composer** (vendor bundled). On a
build machine (or the project's Docker image):

```bash
# 1. PHP deps, production only, optimized autoloader
composer install --no-dev --optimize-autoloader

# 2. Frontend assets (already committed under public/build, but rebuild to be current)
npm ci && npm run build

# 3. Zip the app EXCEPT local-only, per-host, and build-only files. vendor/ and public/build/ ARE
#    included — that's the point (no toolchain on the host). NEVER bundle storage/installed or
#    storage/install-token.txt: they are created per-host at runtime, and bundling either bricks the installer.
zip -r hearth-release.zip . \
  -x ".git/*" "node_modules/*" "tests/*" "docker/*" ".github/*" "docs/*" \
     ".env" ".env.*" "auth.json" \
     "storage/logs/*" "storage/framework/cache/*" "storage/framework/sessions/*" "storage/framework/views/*" \
     "storage/installed" "storage/install-token.txt" "storage/backups/*" "storage/*.key" \
     "bootstrap/cache/*.php" "database/*.sqlite" "hearth-release.zip"

# Laravel 500s if the empty runtime dirs are missing. If the excludes above dropped any, recreate them:
#   mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache && chmod -R 775 storage bootstrap/cache
```

You now have `hearth-release.zip` containing `vendor/`, `public/build/`, and the app — uploadable to any host
with no toolchain. (If your host *does* offer SSH + Composer, you can instead clone the repo and run
`composer install --no-dev` there; the prebuilt assets are committed.)

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
3. Make sure `storage/` and `bootstrap/cache/` are writable (`chmod 775`).

> **Never** let `.env`, `storage/`, or `vendor/` be directly web-served. With the document root on `public/`
> they aren't. Hearth writes `.env` as `0600` (owner-only).

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
- [ ] **Private forum** attachments are **not** reachable by a logged-out user (IDOR fix H‑1) — set a forum's
      `forum.view` to NEVER for guests and confirm `/attachments/{id}` returns 403.

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
