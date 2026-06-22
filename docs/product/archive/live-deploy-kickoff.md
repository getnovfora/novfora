<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Build & deploy live (portable bundle → in-place no-SSH upgrade) — Claude Code kickoff

> **Goal:** produce a ready-to-upload `novfora-release.zip` from **current `main`** and deploy it as a
> **no-SSH, backup-first in-place upgrade** onto the already-running live host. The bundle is **portable /
> tier-adaptive** (ADR-0003): the *same* artifact runs on a baseline shared host and lights up enhanced
> services (Redis / Meilisearch / S3 / Reverb) from `.env` if they're present — no separate build.
>
> **Timing:** the build captures whatever is on `main` when you run it. Run it **after the milestone you want
> live has merged** — e.g. after Code lands **P2-M5** for the public-beta build. The in-place upgrade then
> applies whatever new migrations that bundle carries.
>
> **Two parts:** **Part A** is the paste-into-Claude-Code prompt (build + verify the artifact, in Code's Docker
> `php:8.3` env — the owner has no local PHP). **Part B** is your operator runbook for the live host (upload →
> cron migrates itself). Grounded in: `scripts/build-release.sh`,
> [release-bundle-fix-kickoff.md](release-bundle-fix-kickoff.md) (the RH-1 `packages.php` fix),
> [rh10-auto-upgrade-kickoff.md](rh10-auto-upgrade-kickoff.md) + [getting-started.md](../../getting-started.md) §5
> (the upgrade mechanism), [REAL-HOST-VALIDATION.md](../../REAL-HOST-VALIDATION.md).

---

## Part A — Claude Code: build + verify the release bundle

```
Build the deployable, portable novfora-release.zip from current main for an in-place no-SSH upgrade. No
new features — this is a build + verify task.

STEP 0: read PROJECT-STATE.md, scripts/build-release.sh, docs/product/release-bundle-fix-kickoff.md, and
docs/REAL-HOST-VALIDATION.md. Confirm the full suite is green and the tree is clean. Build from main HEAD.
Commit identity per CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution).

BUILD (in the Docker php:8.3 env + host Node) — prefer scripts/build-release.sh, but it MUST embody these
invariants (fix the script if it has drifted, with a note in the commit):
  1. composer install --no-dev --optimize-autoloader      (production deps only)
  2. npm ci && npm run build                              (refresh the committed public/build assets)
  3. php artisan package:discover --ansi                  (RH-1: bootstrap/cache/packages.php MUST ship —
                                                           a cold first boot 500s "Target class [view] does
                                                           not exist" without it)
  4. php artisan optimize:clear                           (drop any cached config/routes/views)
  5. DO NOT run config:cache / route:cache / event:cache / optimize — they bake the build env's config into
     the bundle and break portability across hosts. Ship ONLY packages.php from bootstrap/cache.
  6. Ensure a CLEAN pre-install state: storage/installed and storage/install-token.txt must NOT be in the
     bundle (created per-host at runtime — bundling either bricks the installer).
  7. RELEASE MARKER: confirm the build advances the release/version fingerprint the RH-10 detector keys off
     (so the live host sees schema.pending = true and auto-upgrades). If the build doesn't bump it, FLAG it —
     a stale marker means the upgrade won't trigger.

  Zip to novfora-release.zip. INCLUDE: vendor/, public/build/, bootstrap/cache/packages.php, app, bootstrap
  (dirs), config, database/{migrations,seeders}, resources, routes, artisan, composer.json/lock, LICENSE,
  README, .env.example. EXCLUDE: .git/ node_modules/ tests/ docker/ .github/ docs/ ; .env .env.* auth.json ;
  storage/logs/* storage/framework/{cache,sessions,views}/* storage/installed storage/install-token.txt
  storage/backups/* storage/*.key ; bootstrap/cache/{config,routes,events,services}.php (ship ONLY
  packages.php) ; database/*.sqlite ; novfora-release.zip itself. PRESERVE the empty runtime dirs + their
  .gitkeep (storage/framework/{cache,sessions,views}, storage/logs, bootstrap/cache) — Laravel 500s without
  them.

VERIFY (the acceptance test — a TRULY COLD HTTP boot, NO artisan run first, per RH-1):
  • Extract to a temp dir. Assert present: vendor/autoload.php, public/build/, bootstrap/cache/packages.php,
    the empty runtime dirs. Assert ABSENT: .env, storage/installed, storage/install-token.txt,
    bootstrap/cache/{config,routes,events,services}.php.
  • Serve the extracted copy with PHP's built-in server (php -S 127.0.0.1:PORT -t <extract>/public) with a
    minimal env (APP_KEY empty, no DB) and curl GET / — assert 302 → /install (boots clean to pre-install,
    does NOT fatal, does NOT think it's installed). Do not invoke artisan before this boot check.
  • Report the final zip path, size, and sha256.

DELIVER:
  • Write the artifact to D:\Forum\novfora-release.zip (repo root). Keep /novfora-release.zip gitignored —
    do NOT commit the binary.
  • Commit ONLY any script/doc changes (conventional, DCO). Full suite + Pint/Larastan/audit stay green.

REPORT BACK: the artifact path + size + sha256, the cold-boot result (302 /install with zero commands),
confirmation that packages.php ships, and the release-marker check (#7) so we know the live host will detect
the upgrade.
```

---

## Part B — Operator runbook: deploy it live (in-place, no SSH)

The bundle migrates itself from the cron line. Your job is upload + watch.

**Pre-flight (5 minutes):**
1. **Take a backup now and download it off-host** — *Admin → System → Backups → Create*, then download the
   `.zip`. (The auto-upgrade also snapshots first, but an off-host copy is your real insurance.)
2. Confirm **`NOVFORA_AUTO_UPGRADE=true`** (the default) for the hands-off path — or plan manual mode (below).
3. Confirm the **cron line** is live (this is what runs the upgrade):
   ```bash
   * * * * * cd /path/to/novfora && php artisan schedule:run >> /dev/null 2>&1
   ```
4. Keep the **current (previous) `novfora-release.zip` on hand** — it's your one-step rollback.

**Deploy (the only step):** upload and **extract `novfora-release.zip` over your existing install** (host file
manager / FTP / cPanel extract). Do **not** touch `.env` or the database — both are preserved.
- *CloudLinux / suEXEC hosts only* — after extract, normalise perms (extraction can produce 0777, which 500s):
  ```bash
  find /path/to/novfora -type d -exec chmod 755 {} \;
  find /path/to/novfora -type f -exec chmod 644 {} \;
  chmod 755 /path/to/novfora/artisan
  # storage/ and bootstrap/cache/ must stay owner-writable
  ```

**What happens automatically:** the site shows a brief branded **"Just a moment…"** maintenance page (≤~2 min).
On the next cron tick the scheduler **backs up first** (pre-upgrade restore point) → `migrate --force` → clears
caches → lifts maintenance. The whole run is audit-logged.

**Verify it's live:**
```bash
curl -s https://YOUR-DOMAIN/health    # watch schema.pending flip true → false; schema.stuck stays false
```
Then sign in, exercise the new release (the new feature surfaces work), and check *Admin → System* for the
upgrade run record + the pre-upgrade snapshot in *Backups*.

**If it gets stuck** (`/health` → `schema.stuck: true`): no SSH needed —
1. **Re-upload the previous `novfora-release.zip`** over the install. The code now matches the rolled-back
   schema and the site returns on its own within a cron tick (migrations are reversible).
2. Then **restore the pre-upgrade snapshot** from *Admin → System → Backups* (its name is on the maintenance
   page). With shell access: `php artisan novfora:restore storage/backups/novfora-…zip`.

**Manual mode** (only if you set `NOVFORA_AUTO_UPGRADE=false`): after extract, the site stays live on a
partly-migrated schema (new-schema actions can error until you apply) — go to *Admin → System → Upgrade →
Apply pending migrations* (admin + 2FA + confirm), or with shell `php artisan novfora:upgrade`.

**Enhanced tier (optional, anytime after):** the same bundle lights up Redis / Meilisearch / S3 / Reverb from
`.env` — see [getting-started.md](../../getting-started.md) §7. Your `.env` survives the upgrade, so enabling a
service is an `.env` edit, not a rebuild.

---

## After this

The live deploy of a bundle carrying new migrations **is** the RH-10 mechanism's real-world validation (the
new release's migrations apply themselves via cron). If anything surfaces on the host, capture the `/health`
output + the audit-log upgrade record and bring it back — Cowork triages it the same way it did RH-6→RH-11.
