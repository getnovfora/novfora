# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (moved to keep this file lean).
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

---

## 🌙 Overnight mega-build on `claude/mega-build` — 2026-06-14 (LATEST · REVIEW + PUSH THIS)

**Unattended, owner-authorized autonomous build (Option 2): only Phase-4-INDEPENDENT units, off `main`
(Phase-3 base). 19 conventional, DCO-signed, `Tommy Huynh`-authored commits on branch `claude/mega-build`
(HEAD `6856b33`). NOTHING IS PUSHED** — push is interactive-only in the sandbox; owner pushes + opens the PR.

**Precondition note:** the original brief gated this on Phase 3 hardening **AND Phase 4** being merged. Phase 4
was confirmed **never built** → owner chose **Option 2**: build only the units that do **not** depend on Phase 4,
and record the Phase-4 deferrals (below). No Phase-4 feature was built or stubbed.

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1302 passed / 1 skipped / 0 failed** ·
`phpstan` (level 5) **0 errors** · `pint` clean · `php artisan migrate` clean. Every wave was committed only at a
green boundary; each new unit added apex-level tests for its security/permission/concurrency/untrusted-input
surface. Every ADR below is **"Accepted — owner-authorized overnight build; flagged for review"** — they want a
human pass before/at merge.

### What shipped, in build order (wave → commit → ADR)
- **0.1 permissions:sync** — `b4f3d2a` (ADR-0036). `novfora:permissions:sync` additively re-provisions role
  presets on upgrade (never `RoleExpander::reexpand`; additive-only). **Clears the Badges 403 on the live host.**
- **1.x Theme Studio** — `650afdc` 1.1 visual token editor (AA-checked) · `4ad749e` 1.2 sanitised custom
  header/footer · `b6f6856` 1.3 layout regions + widgets · `a1fdde3` 1.5 per-theme logo/favicon/bg ·
  `f3abe10` **1.6 sandboxed template editor (APEX — bespoke lexer/parser/evaluator, data-only, no raw
  Blade/PHP; independent adversarial review found+fixed a HIGH lint-bypass)** (ADR-0037, ADR-0038). **1.4
  club hook SKIPPED (needs Phase 4).**
- **2.x Member tools** — `4d548a2` 2.1 bookmarks · `590311f` 2.2 ignore/block · `190b4ba` 2.3 spoiler/CW
  blocks · `14ae657` 2.4 post scheduling (cron-tolerant) (ADR-0039).
- **3.x Discovery** — `56d0763` 3.1 trending/best-of · `90df8e2` 3.2 RSS/Atom feeds · `91f42e3` 3.3
  related-topic recommendations + 3.4 sitemap/SEO (ADR-0040). All permission-safe.
- **4 XenForo importer** — `1e0da04` (ADR-0041). Clean-room, behind `SourceDriver`/`ProvidesAttachments`,
  idempotent/resumable with 301 redirect emission.
- **6.1 Search** — `27026bb` (ADR-0042). Inline operators (`author:`/`in:`/`tag:`/`after:`/`before:`/`type:`)
  on the existing facet layer + own-only saved searches.
- **8.1 i18n + RTL** — `1722c4e` (ADR-0043). Laravel-native localisation framework, allowlist-guarded
  language switcher, `<html dir>` RTL switch. **Framework + Wave-6.1 surface externalised; full ~100-view
  string sweep is mechanical follow-up.**
- **8.2 WCAG 2.1 AA** — `b01e2c4` (ADR-0044). Deterministic DOMDocument auditor + Pest page gate (14
  surfaces, zero findings) + `novfora:a11y:audit` command + manual checklist. Fixed 3 real bugs
  (colour-mode toggle name, save-search input label, tag-input label).
- **8.3 load-test harness** — `ff75944` (ADR-0045). `novfora:loadtest:seed` (real write path) + k6 +
  artillery drivers + procedure. **SCAFFOLDED — no at-scale numbers measured/claimed.**
- **8.4 security sweep** — `6856b33` (ADR-0046). Verify-then-refute (2 independent reviewers + apex pass).
  One MEDIUM fixed (unauthenticated search-operator DB query amplification → bounded to ≤3 queries +
  `?q` length cap + `throttle:120,1`); rest of the new surface refuted.

### ⛔ DEFERRED pending Phase 4 (NOT built, NOT stubbed — record only)
**1.4 Theme-Studio club hook · 5.3 SAML · 6.2 Meilisearch execution path · 6.3 Reverb · Wave 7 monetization.**
These require Phase 4 to exist first. Where a seam was needed it stays dormant/driver-gated; no half-built
feature was shipped.

### ⚠ SCAFFOLDED — NOT VALIDATED against a real service (validate before relying on)
- **Load-test numbers (8.3):** the harness runs; **no at-scale run was performed**. Validate:
  `php artisan novfora:loadtest:seed --forums=… --topics=… --posts=…` then
  `k6 run -e BASE_URL=… load-tests/k6/browse.js` (or artillery) on representative hardware. See
  `docs/architecture/load-testing.md`.
- **Meilisearch / Reverb / SAML (6.2 / 6.3 / 5.3):** DEFERRED — not built this run (carried from prior
  scaffolding; enhanced-tier, need a real service to validate).
- **i18n non-`en` locales (8.1):** es/fr/de/pt_BR/ar/he are **registered scaffolding** — no `lang/<code>/`
  files yet, so they fall back to `en`. RTL `dir` flip is automated; a visual RTL pass is manual.
- **WCAG (8.2):** the automated auditor is a **floor, not conformance** — the manual checklist
  (contrast/keyboard/focus/SR/RTL visual) in `docs/architecture/accessibility.md` is still owner/QA work.

### ☀️ MORNING REPORT — what the owner does next
1. **Review** the 19 commits on `claude/mega-build` (all flagged-for-review ADRs 0036–0046), then **push** and
   open the PR from your terminal (push is interactive-only here; `gh` absent).
2. **Clear the Badges 403 on the live host** — run permissions:sync on the deployed site:
   ```
   php artisan novfora:permissions:sync
   ```
   (additive-only; safe to re-run; re-provisions the role presets the 403 is missing.)
3. New docs to skim: `docs/architecture/i18n-and-rtl.md`, `accessibility.md`, `load-testing.md`,
   `security-review-wave8.md`, `sandbox-template-threat-model.md`, `permissions-sync.md`.
4. New artisan commands available after merge: `novfora:permissions:sync`, `novfora:a11y:audit <url|file>`,
   `novfora:loadtest:seed`.

---

## 📦 Beta release bundle BUILT + Phase 3 now on main — 2026-06-13

**`main` is at `e5d724b` (= `origin/main`) and carries Phase 3 + the hardening pass** — merged via PR #24
(`claude/phase-3-hardening`) + PR #25 (build-release rename) and **pushed**. (The "nothing is pushed" note in the
hardening section below is from before that merge — superseded.) Re-confirmed gate on `main` HEAD: **Pest 1116
passed / 1 skipped / 0 failed**, `pint` clean, `phpstan` L5 0 errors, migrations apply clean. *(A first parallel
gate run showed 156 false failures from a stale compiled-view cache carrying WSL `/mnt/d` paths into the `/app`
container; cleared with `view:clear` and the authoritative single-process run is green.)*

**Deployable `novfora-release.zip` built from `main` HEAD** for the no-SSH in-place beta upgrade (per
[`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md)):
- **Artifact:** `D:\Forum\novfora-release.zip` · 12.66 MB (13,271,763 bytes) · sha256
  `9ea9623d8e329011f2f741463372a7bd670819fb1c41021794f94b423df8a3e5` · **gitignored (not committed)**.
- **Carries Phase 3:** `/api/v1`, module/theme registries, phpBB/MyBB/SMF importers, analytics rollup, H1 webhook
  SSRF guard; **60 migrations (10 Phase-3/Stage-A)** → `SchemaState::codeFingerprint()` advances so a
  `v1.0.0-beta.1` host sees `schema.pending = true` and auto-upgrades (RH-10).
- **Verified:** truly-cold HTTP boot (NO artisan first) `GET /` → **302 /install**, `/install` → **200**;
  `bootstrap/cache/packages.php` ships (RH-1) and no `.env` / install marker / env caches do.
- **ADR-0031…0035** given the flagged human pass — consistent with the locked decisions (see `DECISIONS.md →
  Phase 3 — ADR human review pass`).
- **Committed (script/doc only):** `scripts/build-release.sh` portability fix (`SKIP_NPM` + `optimize:clear`
  ordering), the `public/build` asset rebuild, and these notes. **Owner: push `main` + upload the zip per the
  live-deploy Part B runbook.**

---

## ⭐ Phase 3 — HARDENED · PROVEN · DOGFOODED — 2026-06-13 (REVIEW THIS FIRST)

A focused run to **prove and harden Phase 3 before more is built on it** (NOT a new phase). Phase 3 was first
merged into `main` (PR #23, Stage A + Phase 3 together), then this work landed on branch
**`claude/phase-3-hardening`** (off `main`) as 10 gated, conventional, DCO-signed commits.

**Gate status (final):** full suite **1116 passed / 1 skipped, 0 failed** (`pest`, parallel) · `pint` clean ·
`phpstan` (level 5) **0 errors** · `php artisan migrate` clean. Baseline on `main` was 1077; this run added the
hardening/dogfood tests. Run on the host's **PHP 8.5** (satisfies the `^8.3` floor) — see env assumptions below.

**⚠ Nothing is pushed.** All 10 commits are on `claude/phase-3-hardening` for you to review + push from your
terminal (push is interactive-only in this sandbox).

### HARDEN — closed every flagged Phase-3 follow-up (APEX)
- **H1 — Webhook SSRF / DNS-rebinding** (`feat(webhooks)…2e3c5e3`). New `App\Webhooks\WebhookUrlGuard`: delivery
  resolves the host, refuses any private/loopback/link-local/reserved/CGNAT/metadata/IPv6-ULA/mapped/6to4/NAT64
  address, **pins** the connection to a validated IP (CURLOPT_RESOLVE), and **re-validates every redirect hop**.
  Shared deny-list kernel `App\Support\Ssrf\IpClassifier` (the oEmbed guard now delegates to it — one source of
  truth). Tests: rebinding sim + metadata-endpoint attempt.
- **H2 — Importers verification & fidelity** (`879dd1a`, `50eb308`). MyBB + SMF promoted from scaffolds to
  **VERIFIED** against representative fixtures (full import + idempotency/resume); order-independent forum import
  + SMF title-from-first-message fidelity fixes; **attachment import + sha-256 checksum verification** across all
  three drivers; `verify()` now reconciles CONTENT, not just counts. (Fixed a latent `body_canonical`
  double-encode bug, caught by phpstan.)
- **H3 — Plugin trust guardrails** (`c8cbfdf`). Full-trust **consent gate** at enable, package **integrity hash**
  (verified/modified), **disable-on-fatal quarantine**, and a file-based **kill switch** — NOT a sandbox (none
  built; a real sandbox + full package signature stay out of scope, documented).
- **H4 — Module migration rollback** (`d21b2f8`). `remove()` uses `migrate:reset` (all batches), not
  `migrate:rollback` (last batch only). Remaining items are intentional future enhancements (scope-fenced).

### PROVE — adversarial review + coverage
- **P1 — Adversarial review** (`666f6d5`, APEX). Verify-then-refute over the whole surface (lifecycle/path,
  manifest, hook/filter/slot, REST authz, tokens+rate-limit, webhook HMAC+SSRF, importer dumps). **1 MEDIUM found
  + fixed** — a throwing hook filter / slot renderer is now isolated (caught + reported + skipped) so a faulty
  full-trust extension can't 500 every render. All other vectors **verified-safe, no HIGH**. Full per-vector
  writeup in `DECISIONS.md`.
- **P2 — Coverage + fuzz** (`04fea56`). Property/fuzz tests for the untrusted-input parsers (`ManifestFuzzTest`
  ~400 cases → total + fail-closed; `BbcodeFuzzTest` ~600 cases → total, no tag leak, no ReDoS); API-token
  rotation flow. (No Dusk — flows are server-rendered Livewire, fully covered; no browser driver here.)

### DOGFOOD — used the contract to find gaps (the real payoff)
- **D1 — two first-party plugins** (`63f5072`): `novfora/qa` (accepted answer) + `novfora/kudos`, each exercising
  EVERY seam (event, filter, slot, migration, setting, permission, route; kudos also a layout widget) — **zero
  core edits**. Surfaced **3 contract gaps, all closed ADDITIVELY → Module API `1.1.0`:** (1) no per-post UI slot
  → added `topic.post.aside`; (2) no plugin-settings path → `SettingsRegistry::register()`; (3) `widgets` missing
  from the manifest `provides` vocabulary.
- **D2 — one first-party theme** (`f138d57`): `themes/nebula`, a polished child theme overriding the documented
  `ThemeApi` token contract + branding, proven to coexist with slots + the layout configurator. **No new gaps**;
  `ThemeApi::VERSION` stays `1.0.0`.
- Guide: **`docs/architecture/phase3-extensibility/writing-plugins-and-themes.md`** (write-your-first plugin/theme,
  grounded in the proven contract). The phase-3 arch docs were updated with the proven security model.

### Recorded assumptions (also in `DECISIONS.md`)
- **Environment (sandbox only — no repo impact):** the host's root-owned, unreadable `.env` (an overnight-Docker
  artifact) was renamed to **`.env.root-stale`** and a clean baseline (sqlite) `.env` written so gates run as
  `tommy`; restore it with `sudo mv .env.root-stale .env` if it held real settings. A `conf.d` ini raises the
  PHP-8.5 CLI `memory_limit` to 512M (the lexbor html-sanitizer parser + Pest need it). Parallel Pest runs with
  `--cache-directory=/tmp/...` (the bundled `vendor/pestphp/pest/.temp` is root-owned). Several stale root-owned
  runtime files under `storage/` were moved aside. Docker is NOT available in this WSL distro.
- **`scripts/build-release.sh` is STASHED** (`git stash` — "build-release.sh tweak (push from my terminal
  later)") so it couldn't block branch switches; apply + push it yourself.

---

## Overnight autonomous build — 2026-06-13 (Stage A + Phase 3 build)

An unattended run completed **Stage A (6 M5-deferred fast-follows)** and **Stage B (Phase 3 Extensibility — all
5 subsystems)**. Everything is gated green and committed; **nothing is pushed** (see push status). Every Phase-3
ADR is marked **"Accepted — owner-authorized overnight build; flagged for review"** — give them a human pass
before 1.0.

**Gate status (final):** `composer test` (parallel) **1077 passed, 1 skipped (3598 assertions)** · `pint`
clean (620 files) · `phpstan` (level 5) clean · run via `docker exec forum-dev`. (Baseline started at 972.)

**Branches (⚠ owner must push — `git push` is interactive-only and times out in-sandbox; `gh` absent):**
- `claude/stage-a-fast-follows` — Stage A, 7 commits `869c0db..93e83ea`, atop `origin/main` (152276f).
- `claude/phase-3-extensibility` — Stage B, 7 commits `45407eb..37f5e45`, **branched off the Stage-A tip**
  (so it contains Stage A too). Suggested merge order: Stage A PR first, then Phase 3 PR (which then shows only
  its own commits); or merge Phase 3 directly (includes Stage A).

### Stage A — fast-follows (branch `claude/stage-a-fast-follows`)
| # | What | Commit |
|---|---|---|
| A1 | Staff notes — private, staff-only (`bans.manage`, never the subject); audited; ADR-0025 author de-id | `869c0db` |
| A2 | Public "Top members" leaderboard (rep/posts × all-time/30d/7d); shares the directory visibility gate | `fdc7b1f` |
| A3 | **APEX** Trust auto-promotion by reputation — a PROMOTION-ONLY floor (never spurious-demotes) + upgrade migration | `cc01545` |
| A4 | Aurora filesystem child theme + two core override seams (AA-safe palette); ships inactive | `73b9f8f` |
| A5 | **APEX** isSoleAdmin TOCTOU — locked re-check inside the deletion transaction | `57d0669` |
| A6 | **APEX** ActivityVersion/AclVersion lost-bump — atomic `Cache::add`+`increment` | `b11eb46` |

### Stage B — Phase 3 Extensibility (branch `claude/phase-3-extensibility`)
| # | Subsystem | ADR | Commit(s) | Notes |
|---|---|---|---|---|
| B1 | Module/plugin foundation — manifest+validation, lifecycle, deps/compat, events/filter-hooks/slots, perms, ACP, example plugin | 0031 | `45407eb`, `b54e858` | **APEX boundary.** Post-build adversarial review found + fixed a HIGH path-traversal (`b54e858`) |
| B2 | Visual theming + layout configurator — `ThemeApi` token contract, widget/region system, ACP | 0032 | `8633f28` | |
| B3 | REST API (`/api/v1`, token auth, engine-authorized, paginated, rate-limited) + outbound webhooks (HMAC, cron retry, SSRF guard) | 0033 | `cc936e5`, `160745e` | **APEX boundary** |
| B4 | Importers — clean-room, driver-based | 0034 | `fce128d` | **phpBB built + tested; MyBB + SMF SCAFFOLDED** (schema mapped, unverified) |
| B5 | Admin analytics — privacy-conscious aggregate daily rollup (cron) + dashboard | 0035 | `37f5e45` | |

Phase-3 design set: **`docs/architecture/phase3-extensibility/`** (module-system, theming-layout, api-webhooks,
importers, analytics).

### Partial / scaffolded / flagged for review
- **B4 MyBB + SMF drivers are scaffolds** — schema mapped behind the same `SourceDriver`, **not verified against
  a live board**; their hash schemes aren't Laravel-verifiable so those users reset on first login. Importer
  **verify is count-reconciliation** (attachment import + checksum verify is a documented follow-up).
- **B1 module trust model is full-PHP-trust** (no PHP sandbox is feasible — documented). The SSRF guard (B3) is
  literal-host/IP based (no DNS-rebinding protection). These are documented in their ADRs, not bugs.
- The 6 untracked `docs/product/*.md` kickoff files + `provider-symfony~var-dumper.json` were present **before**
  this run and are left untouched (not part of any commit).

### Key assumptions recorded inline + in `DECISIONS.md`
Staff notes reuse the existing `bans.manage` (no new permission key). Trust rep-gate is promotion-only (floor at
current level). Module permission keys only ADD to the catalog (never escalate). Slot/filter/widget HTML is
re-sanitised through the post-HTML allowlist. Webhooks degrade on the baseline tier via the cron runner; the
dispatcher is gated on a cached "any active endpoints" flag so the no-endpoints case adds zero hot-path queries.
Importers go through the Eloquent models (not the services) so a bulk import fires no domain events. Analytics
store aggregates only (no PII); `daily_metrics.metric_date` is a plain `Y-m-d` string. Full per-subsystem
rationale: `DECISIONS.md` ADR-0031…0035 + the "Fast-follow backlog notes" section.

---

## What this is

**NovFora** (name locked 2026-06-10, ADR-0026; "Hearth" and "NevoBB" are **retired codenames**; in-code
rename complete 2026-06-11, commit `b0cc294`) — open-source (**Apache-2.0**), self-hosted forum/community
platform; modern PHP; **two tiers from one codebase** (baseline shared PHP host / enhanced Docker-VPS);
WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

## Current stack

**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered. PHP 8.3 floor. MySQL 8 / MariaDB default;
PostgreSQL on Docker/VPS. Vite, prebuilt assets (no host Node). Approved — ADR-0001/0002 (Accepted).

## How we work

- **Claude Code (build):** scaffolds and writes the Laravel app. Plan-before-code per phase.
- **Claude Cowork (knowledge work):** reviews plans/docs, preps gate packets, writes status summaries. No app code.
- **Don't run both against the working tree at the same time.** Commit between handoffs; git is the source of truth.
- **Two stages, gated:** Stage A (Discovery) → Phase 0 gate **passed** → Stage B phased implementation
  (plan-before-code, wait for approval per phase).

## Status (as of 2026-06-12)

**Phase 1 / Core MVP · Phase 1.5 hardening · real-host fixes RH-6–RH-11 — all COMPLETE.** Default theme +
polish R1, ACP v1/v1.1, Spike P2 deliverability (GO), and **ACP v2** all merged. **Phase 2 (Community) —
COMPLETE.** P2-M1 through **P2-M5** are all merged to `main`: the M5 ADR-0028 **social pack (follow +
reputation + badges)** + beta polish + the full regression (executed RH-10/RH-11 rehearsals) shipped, and
**`v1.0.0-beta.1` is tagged → 🚩 Public Beta**. **Next: build + deploy the beta to the live host, gather
feedback, then open Phase 3 (Extensibility) — see Immediate next actions.**

> Per-milestone build detail (gates, test counts, adversarial-review findings, scope fences) →
> [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline, NovFora rename (ADR-0024/0026), **ACP v2** (PR #9,
`30bc466`), **P2-M1** engagement/content-depth, **P2-M2 Half-A** deliverability light-up, **P2-M2 Half-B**
multi-participant PMs (PR #17, `535a924`), **P2 account deletion** (ADR-0025, `b006163`), **P2-M3** activity
feed & community-feel core (`ae9bba3`), **P2-M4** moderation depth / search facets / preferences (PR #19,
`c56126e`), and **P2-M5** the social pack (follow / reputation / badges) + beta polish + full regression —
**tagged `v1.0.0-beta.1` (🚩 Public Beta)**. **Origin `main` is the source of truth; nothing is left unpushed.**

**Post-beta polish — built + green, on branch `claude/acp-themes-members-directory` (pending owner push → PR →
merge):** **DB-backed style themes** / in-admin visual theme editor (ADR-0029) · **public members directory**
with admin-controlled visibility (ADR-0030) · `users.post_count` now **maintained** (atomic ±1 on
create/soft-delete/restore) **+ backfilled** — closes the M0 "unmaintained seam" flagged in ADR-0028 · minor
UI width + profile-link polish. 6 conventional commits; full suite **972 green** (pint/larastan/audit clean).

**Stage A fast-follows — DONE (2026-06-13, owner-authorized overnight build, branch
`claude/stage-a-fast-follows`, pending owner push → PR → merge).** All six M5-deferred / review-flagged
fast-follows shipped, each its own gated + committed unit; full suite **1012 green** (pint/larastan/audit
clean). Design notes in `DECISIONS.md → Fast-follow backlog notes`.
- **A1 staff notes** (`869c0db`) — private staff-only notes on a member (`bans.manage`-gated, never the
  subject); `staff_notes` table, `StaffNote`, `App\Moderation\StaffNotes` authority, profile SFC, audited;
  ADR-0025 cascade NULLs the author.
- **A2 reputation leaderboard** (`fdc7b1f`) — public `/members/top` board (reputation / posts, all-time /
  30-day / 7-day), shares the directory visibility gate; windowed views aggregate the source of truth.
- **A3 trust auto-promotion by reputation** (`cc01545`, APEX) — `min_reputation` on tl2/tl3, a PROMOTION-ONLY
  gate (never spurious-demotes), seeder + upgrade-backfill migration.
- **A4 second example theme** (`73b9f8f`) — `themes/aurora` filesystem child theme + two core override seams
  (head palette / footer); AA-safe palette via `AccentPalette`; ships inactive.
- **A5 isSoleAdmin TOCTOU** (`57d0669`, APEX) — locked re-check inside the deletion transaction.
- **A6 ActivityVersion / AclVersion lost-bump** (`b11eb46`, APEX) — atomic `Cache::add`+`increment`.

*Follow + reputation/points + badges shipped earlier in M5 Core per ADR-0028.*

## Immediate next actions

1. **▶ NEXT — ship & validate the 🚩 Public Beta.** Build the deployable upgrade package from `main` and
   deploy it live (in-place, no-SSH RH-10 upgrade) per
   [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md) — back up off-host, extract
   over the running install, watch `GET /health` `schema.pending` flip true→false. Then gather
   private/public-beta feedback (product-plan §8 may reorder later work).

2. **Phase 3 — Extensibility — the next major phase (its own discovery + plan-before-code gate).**
   Module/plugin API + hook/event/slot system (semver'd public contract) + compatibility check; visual
   theming + layout configurator; REST API + webhooks; phpBB/MyBB/SMF importers (verify + 301 redirects);
   admin analytics.

3. ~~**Fast-follows queued by M5**~~ — **DONE 2026-06-13** (Stage A, see the "Stage A fast-follows" block
   above): staff notes · reputation leaderboard / top-members · TL auto-promotion by reputation · 2nd example
   theme · `isSoleAdmin` TOCTOU + `ActivityVersion`/`AclVersion` lost-bump hardenings. On branch
   `claude/stage-a-fast-follows` (owner push → PR → merge).

4. **Design-first items still queued (do not build without a plan):**
   - RH-4: subdirectory install (ADR needed)
   - Layman "simple-mode" permissions UX (ACP v3, separate cycle)

## Working rules

Full rules in `CLAUDE.md`. Short form: strict clean-room · progressive enhancement · reversible migrations ·
security by default · tests with every feature · semver'd module/theme API · conventional commits + ADRs.

## Model & effort

Full routing in `CLAUDE.md §Model routing`. Short form:
- **`ultracode` (default):** start at **Fable @ max** (apex), downgrade as fit when work is pattern-replication.
- **Fable @ max:** permission/security/concurrency core, adversarial reviews, spikes, mechanism/API design.
- **Opus 4.8 `xhigh`/`high`:** heavy correctness work below the apex.
- **Sonnet 4.6:** CRUD, scaffolding, view boilerplate, mechanical breadth, multi-site sweeps (sub-agents).
- **Docker gates are free** — verify with `pest`/`pint`/`larastan`, not by re-reasoning.
- Never re-read a file you just edited (the harness tracks state). Cap gate output — tail/`Select-Object -Last`.
