# Deploy `main` to the demo (live test) — upgrade runbook

> The demo (`demo.novfora.com`, Hostinger, **Baseline tier**) is already installed, so deploying `main` is an
> **upgrade** (RH-10 / ADR-0021): build the release on the VPS, extract it over the existing install, and the
> cron auto-upgrade backs up + migrates + brings the site back — no SSH, no manual SQL. Full recipe:
> `docs/REAL-HOST-VALIDATION.md` §2 (build) + §6a (upgrade). Steps are tagged **[VPS]** (Code/you on the build
> box) or **[hPanel]** (you, in Hostinger).
>
> **Note — Baseline tier:** the demo has no Redis/Meilisearch/Reverb, so search falls back to the DB driver,
> realtime to polling, the queue to the cron drain. That's progressive enhancement working as designed — this
> deploy validates the **Baseline** path live. (Enhanced is validated separately on the VPS.)

## 0. Pre-flight (safety)
- **[hPanel/Admin]** On the live demo, *Admin → System → Backups* → **Create backup** and **Download** it (an off-host copy before a big upgrade — this upgrade carries every ACP v3 migration since the demo was last deployed).
- **[VPS]** Confirm `main` is current and green: `cd ~/novfora && git checkout main && git pull --ff-only && ./vendor/bin/pest && ./vendor/bin/pint --test && ./vendor/bin/phpstan`.

## 1. Build the release bundle — [VPS]
```bash
cd ~/novfora
./scripts/build-release.sh           # → novfora-release.zip (prod autoloader, prebuilt assets, the cold-boot manifest fix, ships lang/+public/icons)
./scripts/verify-release.sh novfora-release.zip   # acceptance check on the bundle
```
(`scripts/lib/i18n-probe.php` + the `ck lang/en/forum.php` check confirm the i18n strings ship.)

## 2. Get the zip to Hostinger — [VPS → hPanel]
- Pull `novfora-release.zip` off the VPS (scp/Tailscale `scp dev@129.121.115.222:~/novfora/novfora-release.zip .`, or download it), then in **hPanel → File Manager** upload it into your home dir (the parent of `public_html`, where `~/novfora` lives).
- **Extract it over the existing `~/novfora`** — overwrite `app/`, `public/build/`, `vendor/`, `bootstrap/cache/packages.php`, etc. **Do NOT touch `.env`, `storage/installed`, or `storage/`.** Nothing else — no installer, no commands.

## 3. Watch the auto-upgrade — [hPanel/curl]
Within ~2 min the cron tick takes a **pre-upgrade backup**, then migrates. The site may briefly show a branded "Just a moment…" maintenance page.
```bash
curl -s https://demo.novfora.com/health | python3 -m json.tool
# schema.pending: true → migrating (maintenance page shows);  schema.upgrading: true → applying;  then pending:false → done, new version live
```
This run applies **all the ACP v3 migrations at once** — `expires_at`, `moderator_assignments`, `is_co_owner` **+ the co-owner backfill that crowns your existing admin**, the `admin.<section>.access` keys (via `PermissionSync`), the `delegations` table, and the staff-flair group columns. The backfill is why you don't get locked out of the new co-owner-only Security section.

## 4. Verify — [browser]
Run the §6a checklist (maintenance page was branded not a 500; `schema.pending` flipped true→false; a pre-upgrade snapshot appears in *Admin → System → Backups*; "Last upgrade: Succeeded"), **plus** spot-check the new work landed:
- **Sign in as your admin → you can reach *Admin → Security*** (proves the co-owner backfill worked — you're a co-owner; a non-backfilled admin would 403 here).
- *Security → Co-owners*, *Admin accounts*, *Active Delegations* render (v3-a / v3-f).
- *Groups → Group permissions* shows the **Simple / Advanced** switch; the simple toggles read/write correctly (simple-mode); a trust-NEVER capability is **locked** with the restricted note.
- The **Permission Inspector** explains a verdict in plain language (names, not codes).
- **Staff flair** shows on a post author + the **`/staff` "The Team"** page lists co-owners/admins/mods (if you flip `members.staff_roster_enabled` on).
- Search returns results (DB-driver fallback — Baseline), the queue heartbeat is fresh (`/health`), a new member can register + post.

## 5. If it gets stuck — [hPanel]
A failed migration holds the site in maintenance (`/health → schema.stuck: true`); it does **not** retry-loop. Recover with no SSH by **re-uploading the previous release zip** (the code then matches the rolled-back schema and the site returns on the next cron tick); the maintenance page names the pre-upgrade backup. Capture the `upgrade.failed` audit entry + `storage/logs/laravel.log` for a report.

## Sequencing
Run this **after the `ui-audit-reconcile` PR merges** so the deploy carries the UI fixes too — *or* deploy current `main` now to test the full ACP v3 program live and re-upgrade (same gesture) once the UI fixes land. Either way, the upgrade is idempotent and backup-first.
