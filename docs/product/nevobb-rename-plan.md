<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NevoBB Authors
-->
# Hearth → NevoBB rename plan (ADR-0024)

**Status: APPROVED 2026-06-07 (owner), including the §6 exception list.** Owner: Tommy Huynh. Executed as
**one reviewed change** (per ADR-0024). **Target: Phase 5 exit criterion — v1.0.0 ships branded NevoBB
end-to-end.** Domains and GitHub org registration are handled by the owner outside this change.

## 1. Goal & release gate

No operator- or user-visible "Hearth" anywhere in v1.0.0: installer, UI, mails, error/503 pages, admin
panel, CLI, env keys, docs, package names, release artifact. **Gate:** `grep -ri hearth` over the repo
returns only the documented historical exceptions (§6), enforced by a CI check so it cannot regress.

## 2. Inventory (measured 2026-06-07)

~215 files reference "hearth": app 52 · docs 46 · resources 44 · tests 43 · root files 13 · config 3 ·
database 4 · scripts 4 · docker 3 · public 4 · routes 2 · bootstrap 1. Plus: **44 distinct `HEARTH_*` env
keys** · **29 `hearth:*` artisan commands** · **41 files** carrying the "The Hearth Authors" SPDX line ·
`config/hearth.php` · `hearth-release.zip`.

**Verified clean (no action needed):** no `Hearth\` PHP namespace (root stays `App\`); **0 migration
filenames** contain hearth (no `migrations`-table risk — migration files are never renamed); settings
**DB keys are brand-free** (`moderation.*`, `antispam.*`, `appearance.*` — the `hearth.*` strings in
`SettingsRegistry` are *config paths*, not stored keys, so **no settings data migration**).

## 3. Rename map

| Surface | From → To | Notes |
|---|---|---|
| Config file | `config/hearth.php` → `config/nevobb.php` | + every `config('hearth.…')` call site → `config('nevobb.…')` |
| Env keys | `HEARTH_*` → `NEVOBB_*` (44 keys) | Clean break, **no fallback shims** — pre-release; §5 covers the one live install |
| Artisan | `hearth:*` → `nevobb:*` (29 commands) | `nevo:*` considered and rejected — single brand, no second quasi-name |
| Class | `HearthBackupCommand` → `NevoBBBackupCommand` | The only branded class name |
| JS global | `window.Hearth` → `window.NevoBB` | Density/appearance helpers |
| App name | `APP_NAME="Hearth"` → `"NevoBB"`; `config('app.name', 'Hearth')` fallbacks → `'NevoBB'` | `.env.example`, installer default, mail strings ("Hearth email self-test"), 503/error pages, demo seed |
| DB default | `DB_DATABASE=hearth` → `nevobb` | `.env.example`, docker-compose, `HEARTH_DUSK_INSTALL_DB_*` → `NEVOBB_DUSK_INSTALL_DB_*` |
| Backups | `hearth-*.zip` → `nevobb-*.zip` | Builder + the restore validator's exact-pattern match; §5 for old archives |
| Cache keys | `'hearth.acl.v'`, settings bag, schema state → `nevobb.*` | One-time cache invalidation; rebuilds on next request/tick |
| SPDX | "The Hearth Authors" → "The NevoBB Authors" (41 files) | |
| Packages | composer `"laravel/laravel"` → `"nevobb/nevobb"`; package.json name `nevobb` | Also fixes the never-renamed skeleton leftover. Packagist vendor `nevobb` verified free 2026-06-07 |
| Release artifact | `hearth-release.zip` deleted → `nevobb-release.zip` rebuilt | + `scripts/` build references, docker labels |
| Docs | Full sweep: README, getting-started, ARCHITECTURE, GOVERNANCE, CONTRIBUTING, THEME-API, docs/** | Exceptions in §6 |
| Theme API | `hearth::slot` (docs-level reference) | 0 matches in PHP/Blade — appears docs-only; **verify at execution**. Pre-1.0 is the last chance to rename a public-contract string (THEME API v1.0) |

The **sim subsystem** (`HEARTH_SIM_*`, `hearth:sim:*`) renames mechanically with everything else
(`NEVOBB_SIM_*`, `nevobb:sim:*`); it remains **exploratory and default-off per ADR-0024** — this change
alters no scope.

## 4. Execution order

Branch `rename/nevobb`; conventional commits, signed off (`-s`), authored/committed as
`Tommy Huynh <tommy@saturnhq.net>`. Each commit leaves the suite green; the series merges as one
reviewed change.

0. **Prereqs:** remove the stale `.git/index.lock`; land or stash the currently dirty files
   (`CLAUDE.md`, `DECISIONS.md`, `PROJECT-STATE.md`, `app/Settings/Settings.php`) so the rename diff is
   pure; on the validation host: drain the DB queue, confirm no upgrade/restore pending.
1. `refactor!:` config + env — file rename, `config()` paths, `NEVOBB_*` keys, `.env.example`.
2. `refactor!:` artisan namespace + class rename + scheduler/cron lines + command references in docs.
3. UI/branding sweep — Blade, mails, installer wizard, error/503 pages, `window.NevoBB`, demo seed.
4. Backup/restore naming — file pattern, and **check the manifest for embedded name strings**.
5. SPDX headers + composer/package names.
6. Docs sweep + historical-exception notes (§6).
7. Rebuild release artifact; docker/scripts; add the CI grep gate.

## 5. Compatibility notes (single known install: the real-host validation site)

- `.env`: one-time `HEARTH_` → `NEVOBB_` find/replace (documented in the release notes of the change).
- Old backup archives match `hearth-*.zip`; to restore one post-rename, rename the file (works unless
  the manifest embeds the name — verified in step 4; if it does, add a one-time legacy match).
- Pending queued jobs serialized with an old class name would fail after deploy → drain first (step 0).
- Cache / settings-bag / schema-state keys change names → caches rebuild on the next request/tick; no
  action, momentary cold cache only.
- Release fingerprint = sha256 of **migration filenames** → unchanged (none renamed), so the rename
  deploy does **not** trigger a spurious upgrade gate.
- **The validation host is interim (owner decision, 2026-06-07):** approaching v1.0, the owner performs a
  **fresh install on a new webhost at the new domain** (nevobb.com). The compat notes above only need to
  hold until then — no long-term migration support for the Hearth-era install is required. The fresh
  install on the new host doubles as the §7 "fresh install" verification, on real production hosting.

## 6. Historical exceptions — the only surviving "Hearth" mentions (owner to approve)

1. **DECISIONS.md ADR-0024** — records the rename itself.
2. **CLAUDE.md** — reduced to one line: "Hearth = pre-rename working codename (ADR-0024)."
3. **Dated records quoting executed evidence** (spike-0-memo, real-host-findings, PROJECT-STATE history,
   pre-0024 ADR bodies): kept as written, each gaining a one-line codename note at the top. Rewriting
   dated evidence would falsify the record — and the public git history says "Hearth" regardless.

**Decision needed:** approve this exception list, or order a true 100% rewrite of historical docs too.

## 7. Verification checklist (the release gate)

- [ ] `grep -ri hearth` → §6 exceptions only; CI check added
- [ ] Full Pest suite + Dusk journeys green; pint + larastan clean; `composer validate`
- [ ] Fresh no-SSH web install on the baseline tier: installer, UI, mails, 503 page, admin panel — all NevoBB
- [ ] Backup → restore round-trip post-rename; upgrade path exercised on the validation host with the `.env` swap
- [ ] THEME-API.md reviewed for contract strings; version note added if any renamed
- [ ] `nevobb-release.zip` rebuilt, sha256 recorded in PROJECT-STATE

## 8. Rollback

One reviewed change → a single revert; restore the `.env` keys on the validation host. Old backups
remain restorable either way.
