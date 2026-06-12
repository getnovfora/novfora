<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Architecture Decision Records (ADRs)

Decision log for **NovFora**. Each ADR is the short, durable record; the linked Stage A doc
holds the full rationale, tradeoffs, and detail. New significant decisions are added here via the RFC/ADR
process in [GOVERNANCE.md](GOVERNANCE.md). Status values: **Accepted · Proposed · Superseded · Revises-brief
(flagged for sign-off)**.

> **Accepted at the Phase 0 gate (2026-06-01):** ADR-0001 and ADR-0002 revised the brief's stated versions
> (Laravel 11 → **13**, Livewire 3 → **4**) and the **PHP 8.2 → 8.3** floor, on live-validated evidence and the
> project lead's explicit sign-off. `CLAUDE.md` and `docs/PROJECT-BRIEF.md` were updated to match.

| ADR | Decision | Status | Detail |
|---|---|---|---|
| 0001 | Stack: **Laravel 13 + Livewire 4 + Alpine + Blade**, server-rendered | **Accepted** (revised brief 11/3; signed off at Phase 0 gate) | [stack](docs/architecture/technical-stack-recommendation.md) |
| 0002 | **Min PHP 8.3** (recommend 8.4+) | **Accepted** (revised 8.2 floor; signed off at Phase 0 gate) | [stack §5](docs/architecture/technical-stack-recommendation.md) |
| 0003 | **Two-tier progressive enhancement** + driver abstraction + service-tier detection | Accepted | [system-arch §1](docs/architecture/system-architecture.md) |
| 0004 | **MySQL/MariaDB default**, PostgreSQL parity (Docker/VPS), nullable `tenant_id` seam | Accepted | [data-model](docs/architecture/data-model-initial.md) |
| 0005 | **Canonical content storage** = structured canonical + server-sanitized HTML cache + text projection | Accepted | [data-model §3](docs/architecture/data-model-initial.md) |
| 0006 | **Permission-mask resolution** (ALLOW/NO/NEVER, global→thread scope, cache, inspector) | Accepted | [security §1](docs/architecture/security-and-permissions.md) |
| 0007 | **First-class anti-spam** subsystem (trust levels ↔ ACL, blocklist, CAPTCHA abstraction, rate limits) | Accepted | [security §2](docs/architecture/security-and-permissions.md#anti-spam) |
| 0008 | **Module/hook/event/slot system** — semver'd public contract, no core edits | Accepted | [plugin-theme §2](docs/architecture/plugin-and-theme-system.md) |
| 0009 | **Dual theming** — visual configurator + Blade override layer, a11y floor, no core edits | Accepted | [plugin-theme §3](docs/architecture/plugin-and-theme-system.md) |
| 0010 | **Search abstraction** (Scout DB → Meilisearch) + documented illustrative threshold | Accepted | [system-arch §4](docs/architecture/system-architecture.md) |
| 0011 | **Queue via cron** (DB queue, bounded drain, coarse-cron tolerant, idempotent) | Accepted | [system-arch §3](docs/architecture/system-architecture.md) |
| 0012 | **Editor** = TipTap-class WYSIWYG as an Alpine island (`wire:ignore`, prebuilt) — #1 risk/spike | **Accepted · validated 2026-06-02 (Spike 0 GO)** | [stack §7](docs/architecture/technical-stack-recommendation.md) · [memo](docs/product/spike-0-memo.md) |
| 0013 | **Importers** = resumable, dry-run/verify, attachment-verified, password-rehash, 301 redirect maps | Accepted | [plugin-theme §4](docs/architecture/plugin-and-theme-system.md) |
| 0014 | **Email deliverability** = provider abstraction + SPF/DKIM/DMARC guidance + bounce/suppression; baseline best-effort | Accepted | [system-arch §5](docs/architecture/system-architecture.md) |
| 0015 | **Dependency licensing** = Apache-2.0-compatible only; no GPL/AGPL bundled; **TipTap MIT-core only** | Accepted | [stack §8](docs/architecture/technical-stack-recommendation.md) |
| 0016 | **a11y/i18n baseline** = WCAG 2.1 AA baked in; utf8mb4; externalized strings; RTL not precluded | Accepted | [data-model §10](docs/architecture/data-model-initial.md), [plugin-theme §3.3](docs/architecture/plugin-and-theme-system.md) |
| 0017 | **License = Apache-2.0** + SPDX headers from commit one | Accepted (brief) | [LICENSE](LICENSE) |
| 0018 | **Strict clean-room** vs all reference forums; copy *data* (importers), never *program* | Accepted (brief) | [CONTRIBUTING](CONTRIBUTING.md) |
| 0019 | **Auth = Laravel Fortify (headless) + our own Blade views**; **argon2id**; **2FA/TOTP mandatory for staff**, opt-in for users; passkeys deferred | Accepted | [security §1](docs/architecture/security-and-permissions.md) |
| 0020 | **No-SSH web installer** with a **post-install file-marker lock** (no re-trigger / admin-reset vector); one shared `InstallRunner` for web + CLI; pre-install boot hardening; **portable backup/restore** (manifest + SHA-256 integrity) | Accepted | [getting-started](docs/getting-started.md) |
| 0021 | **No-SSH automatic upgrade** — cron-driven, backup-first, maintenance-safe migration; cheap cached schema-state detection (release-fingerprint, O(cache-read) request path); `NOVFORA_AUTO_UPGRADE` default-on with a manual admin/CLI path; held-not-looping failure policy | Accepted | [getting-started §5](docs/getting-started.md) · [RH-10](docs/product/real-host-findings.md) |
| 0024 | **Project name = NevoBB** — single brand; "NevoBB" codename retired; NevoForums parked as redirect/future hosted tier | **Superseded by ADR-0026** | [§ADR-0024](#adr-0024--project-name-nevobb-2026-06-07) |
| 0025 | **Account deletion + content-cascade policy** — posts pseudonymised (`[Deleted]`); reactions / poll votes / tags hard-deleted; owner-confirmable cascade; voluntary + admin-forced paths share one service | Accepted | [§ADR-0025](#adr-0025--account-deletion-and-content-cascade-policy-2026-06-10) |
| 0026 | **Project name = NovFora** (supersedes ADR-0024) — domains `novfora.com` + `novfora.net` registered; Hearth/NevoBB are retired codenames; in-code rename complete | Accepted | [§ADR-0026](#adr-0026--project-name-novfora-2026-06-10) |

---

## ADR detail (concise)

### ADR-0001 — Framework & rendering stack
**Context:** need one server-rendered, SEO-safe codebase that installs on a shared host (no Node SSR, no
daemon) yet supports a modern product surface. **Decision:** Laravel 13 + Livewire 4 + Alpine + Blade,
server-rendered; reject Inertia+Vue/React SPA (needs Node SSR for SEO). **Consequences:** one codebase across
tiers; rich client widgets become Alpine islands; revises brief's "Laravel 11 / Livewire 3" to current stable.

### ADR-0002 — Minimum PHP 8.3
**Context:** Laravel 13 requires PHP 8.3; PHP 8.2 reaches EOL 2026-12-31. **Decision:** floor at 8.3, recommend
8.4+. **Consequences:** **revised the Checkpoint-approved 8.2 floor** — starting a 2026 project on a soon-EOL
runtime is indefensible, and reputable shared hosts offer 8.3/8.4 by mid-2026. **Signed off at the Phase 0 gate (2026-06-01).**

### ADR-0005 — Canonical content storage
**Context:** WYSIWYG-first, but must render safely, search well, re-transform, and import legacy BBCode.
**Decision:** store a lossless **canonical** doc (TipTap JSON / Markdown / legacy) + a **server-sanitized HTML
cache** + a **plain-text** projection; HTML always (re)generated server-side from canonical (the security
boundary). **Consequences:** lossless edit, safe render, future-proof re-render, easy search; needs a server
JSON→HTML renderer (MIT lib) + allowlist sanitizer (license vetted here).

### ADR-0006 — Permission-mask resolution
**Context:** phpBB-grade ACL is a primary requirement. **Decision:** three-state ALLOW/NO/**NEVER** (NEVER
absolute), holders = users + primary/secondary groups, scope chain global→category→forum→thread (local
overrides global, user overrides group, most-permissive group, deny-by-default), role presets, cached resolved
masks (>95% hit), admin "why can/can't X" inspector. **Consequences:** the spine of the app; dedicated
truth-table tests; anti-spam gating reuses it (ADR-0007). **NO semantics (owner-confirmed 2026-06-02):**
`NO` = neutral/inherit (**interpretation "ii"**) — an `ALLOW` lifts it and inheritance continues past it; use
`NEVER` to hard-deny. The single decision point is marked inline in `PermissionResolver::compute()` and pinned
by the truth-table suite.

### ADR-0007 — First-class anti-spam
**Context:** spam is the #1 evidenced operator burden and NovFora's differentiator. **Decision:** layered
defense (registration blocklist + CAPTCHA abstraction + honeypot + velocity; trust levels as ACL groups;
post-time scanning + rate limits + moderation queue; Spam Cleaner), **gating expressed through the
permission-mask engine** (NEVER = hard gate, NO = soft gate), all baseline-safe/graceful. **Consequences:**
unified with permissions + inspectable; external services optional; documented threshold defaults; privacy/GDPR
retention on registration checks.

**M3 implementation notes (2026-06-03):** TL gating is seeded as `acl_entries` on the TL groups from a
config matrix (`config/novfora.php`), enforced by link/image **suppression at the shared sanitize step** (the
canonical stays lossless, ADR-0005); auto promotion/demotion runs via the idempotent `novfora:trust:recompute`
cron. Registration is a tri-state screener (StopForumSpam live→cron-cached→no-signal; disposable-email;
honeypot+timing; IP velocity) + a `CaptchaProvider` abstraction that **degrades to Q&A** when an external
provider is absent. Post-time moderation = a `ContentScanner` **contract** (local heuristics now; **Akismet is
Phase 2** behind the same contract), word filters, and a new-user/`status=pending` queue. **Deliberate
deviations (recorded per the conventions):** the `rate_limit_hits` table is **not** created — Laravel's
cache-backed `RateLimiter` is already DB-on-baseline→Redis-on-enhanced (tier-graceful); `mod_actions` is **not**
created — the append-only `audit_log` subsumes it. Issuing/lifting a **ban now bumps the ACL version** so a
cached verdict can't outlive it. **No new runtime/dev dependencies** were added in M3 (Http via the
framework's bundled client; `symfony/html-sanitizer` and `league/commonmark` already present).

### ADR-0011 — Queue via cron
**Context:** baseline hosts can't run a worker daemon. **Decision:** DB queue drained by a single
`schedule:run` cron with a bounded `queue:work --stop-when-empty`, overlap-locked; all async work idempotent
and correct within one (possibly coarse 5–15 min) cron interval. **Consequences:** "one cron line" baseline;
swap to Redis+worker on enhanced with no code change.

### ADR-0015 — Dependency licensing
**Context:** Apache-2.0 + clean-room. **Decision:** every dependency Apache-2.0-compatible (MIT/BSD/Apache/
ISC); **no GPL/AGPL bundled**; **TipTap: MIT core/extensions only** (its Pro/collaboration packages are
commercial — never pulled); Typesense (GPL server) only as an optional out-of-process service, never bundled.
**Consequences:** per-dependency license recorded here before merge.

### ADR-0012 — Editor integration (validated by Spike 0, 2026-06-02)
**Context:** the TipTap↔Livewire-4 integration was the project's #1 technical risk; ADR-0012 proposed a
`wire:ignore` Alpine island. **Validation:** Spike 0 returned **GO** — all six acceptance criteria passed with
executed evidence (Pest 10 tests / 82 assertions incl. the XSS battery; Playwright 6/6 incl. the #1a
state-survival GO-blocker). See [spike-0-memo.md](docs/product/spike-0-memo.md). **No fallback was needed; the
decision stands.** **Binding implementation constraint:** the TipTap instance must live in **per-instance
closure state, never a reactive Alpine property** — a reactive proxy wraps ProseMirror's state and makes
programmatic commands throw *"Applying a mismatched transaction."* Canonical TipTap JSON syncs to Livewire via a
**deferred `$wire.set` (no debounce)**; HTML is always rendered + sanitized server-side (ADR-0005). Livewire 4
components are **single-file** (`⚡`-prefixed). Full M2 notes: [phase-1-plan.md](docs/product/phase-1-plan.md) §4.

### ADR-0019 — Authentication & two-factor (M1)
**Context:** M1 needs register / verify / login / password-reset on the baseline tier (server-rendered,
no SPA), with strong hashing and mandatory 2FA for privileged accounts. **Decision:** use **Laravel
Fortify** (headless — backend only) behind **our own clean-room Blade views**, not a starter kit;
**argon2id** as the default password hash (bcrypt fallback for hosts lacking libargon2); rate-limited
login (5/min per email+IP) and 2FA challenge; **TOTP 2FA mandatory for staff** (admins & moderators —
enforced by the `RequireTwoFactorForStaff` middleware on privileged routes), **opt-in** for general users
(Phase 2 "Should"). The admin panels are gated on the `admin.access` permission via the engine (ADR-0006).
**WebAuthn/passkeys are deferred** — `laravel/passkeys` ships as a Fortify dependency but the feature,
routes, and table are intentionally not enabled in M1. **Consequences:** no client framework required for
auth; 2FA secrets/recovery codes encrypted at rest; the first admin is created by the installer (a later
slice), not seeded. Dedicated feature tests cover registration, throttling, reset, verification, the full
2FA enable/confirm/challenge flow, and the staff-2FA gate.

### ADR-0020 — No-SSH web installer, lock, and backups (M5)
**Context:** NovFora must be installable by ordinary operators on a shared host with no SSH, and the
installer is an **unauthenticated pre-install surface** that writes secrets, runs migrations, and creates
the admin — the highest-risk code path in the project. **Decision:** a browser wizard (requirement/tier
probes → DB → site & admin → run) backed by a single `App\Install\InstallRunner` shared with a
`novfora:install` CLI, so there is exactly one audited install sequence. The **lock** is a `storage/installed`
**file marker** (not a DB flag — checkable before the DB exists, survives a DB wipe), written **last**; once
present, `EnsureNotInstalled` returns 403 and **no web route clears it** (reset is a deliberate filesystem
action = shell trust). A pre-install boot hook forces zero-dependency drivers (file session/cache, sync
queue) + an ensured APP_KEY so a fresh upload boots with no DB. **Backups** are one portable `.zip` (DB dump
+ storage mirror + a manifest carrying a **SHA-256** of the dump); restore validates the manifest + verifies
the hash before overwriting. **Consequences:** input validated server-side; secrets never rendered back nor
logged; the lock has no re-trigger/admin-reset vector; the upgrade safety net (reversible migrations +
backup→restore) is proven by a round-trip test. Tests opt out of enforcement (`NOVFORA_INSTALL_ENFORCE=false`)
so the M0–M4 suite is untouched.

### ADR-0021 — No-SSH automatic upgrade (RH-10)
**Context:** `getting-started.md` §5 promised "deploy the new version (it migrates automatically)", but
nothing implemented it — the only `migrate` call lived in `InstallRunner` (install time). A no-SSH operator
who extracts a new release over a live install runs **new code against the old schema, with no way to
migrate**: the very next themed release adds `users.color_mode`/`density`, so until they're applied, saving
Appearance settings errors (a write to a missing column) and any future destructive migration (a dropped/
renamed/retyped read-path column) breaks pages site-wide — with no no-SSH recourse. (Additive *reads*
degrade to `null` with strict mode off, so it is the missing migrate path, not a guaranteed every-page 500,
that is the gate.) A beta gate.

**Decision:** a cron-driven, **backup-first, maintenance-safe** automatic migration, in `App\Upgrade`.
- **Detection (cheap).** `SchemaState` keeps one cache key. The **request path** is O(cache-read): it reads
  the cached flag and compares a **release fingerprint** (a sha256 of the deployed migration filenames —
  a glob, no DB) against the recorded one. A mismatch ⇒ "new code, schema behind" ⇒ gate — which closes the
  deploy→first-tick window the scheduler alone would leave open. The **scheduler tick** does the real,
  DB-heavy `migrator` check and refreshes the flag. `GET /health` gains a non-secret `schema` block
  (`pending`/`upgrading`/`stuck`/`auto`/`last`) so the owner/Cowork verify a live upgrade remotely.
- **The run** (`UpgradeRunner`, every minute, `withoutOverlapping` **+** a cache lock so it can never
  double-run): enter maintenance → **take a backup** (failure ABORTS — stay pending, surface loudly) →
  `migrate --force` in-process → refresh caches → exit maintenance → audit-log (count, duration, backup).
  A run killed mid-migration is **resumed idempotently** next tick (already-applied migrations are skipped).
- **The window.** `PreventRequestsDuringUpgrade` serves a branded **503** (Retry-After, self-refreshing) for
  every request except the health endpoints (so the upgrade is watchable) and assets — never a raw SQL error.
- **Failure policy.** On a migrate failure, best-effort roll back **this run's** batch only (never the prior
  good batch — a single migration that recorded nothing leaves the **backup** as the recovery path, since
  MySQL DDL is not transactional); stay in maintenance; **hold** (`schema.stuck`) after at most
  `max_auto_attempts` (default 2) — **no retry loop**. The hold self-clears when the operator re-uploads the
  previous release (drift resolved).
- **Operator controls.** `NOVFORA_AUTO_UPGRADE=true` by default (the promise). `false` = manual: nothing
  auto-runs and *Admin → System → Upgrade* / `php artisan novfora:upgrade` apply on demand. **Asymmetry
  (documented):** auto mode is what shields signed-in pages during the window; manual mode keeps the site
  reachable so the admin can apply, so those pages may error on new columns until they do.

**Consequences:** the documented promise is true on the baseline tier with zero new dependencies; the
≤~2-minute window on a 1-minute cron protects signed-in pages from new-column 500s; reversible migrations +
the pre-upgrade backup are the recovery net; the cached state is scalars-only so it survives a serializing
store under the RH-9 anti-object-injection hardening. Tested at the feature level driving the runner
directly (detection on/off · lock · backup→migrate ordering & backup-abort · failure→rollback+stuck · health
schema · 503-not-SQL during the window · auto-off + manual apply). See
[real-host-findings RH-10](docs/product/real-host-findings.md).

### ADR-0022 — No-SSH panel restore (RH-11)
**Context:** `novfora:restore` existed only as a CLI command; *Admin → System → Backups* could create/download
but **not restore**, so a no-SSH operator had no recovery path — the same documented-but-unimplemented class as
RH-10. The kickoff: add a safe, no-SSH restore to the panel, reusing the RH-10 machinery (maintenance gate,
audit, health surfacing).

**Decision:** a **cron-driven, file-coordinated** restore in `App\Backup`, wrapping the existing
`RestoreService` in the RH-10 choreography (`RestoreRunner`, the sibling of `UpgradeRunner`).
- **Why not synchronous-in-the-request or a DB queue job.** A restore OVERWRITES the live DB — and on the
  baseline tier the cache, session, AND queue all live in it. So a synchronous web restore would wipe the
  session/cache backing the request mid-flight (and is bounded by the request-time limit on large archives),
  and a DB-queue job would erase its own `jobs` row mid-restore. The maintenance/coordination state is
  therefore a **file** (`RestoreState` → outside `storage/app`, so it survives the DB swap), and the run is
  drained by the **single cron line** (`RestoreRunner::runPending`) in CLI context. The flock lock — not a
  cache lock, which the restore would wipe — is the real double-run guard. `runNow()` is the synchronous CLI
  path; CLI (`novfora:restore`) and panel share one `execute()`.
- **Choreography:** validate the archive (manifest + streamed dump SHA-256 — **refuse before touching
  anything**) → take a **pre-restore safety snapshot** (so the restore is itself reversible) → restore DB +
  storage from a private temp copy of the target → flush caches + `SchemaState::refresh()` → exit maintenance
  → audit-log (actor, archive, duration). The maintenance gate (`PreventRequestsDuringUpgrade`) checks the
  file-based restore state **first**, then the RH-10 cache state; `GET /health` gains a non-secret `restore`
  block; a stuck restore reads as `degraded`.
- **The RH-11 → RH-10 hand-off.** A restored **older schema** is detected by the post-restore
  `SchemaState::refresh()`; the RH-10 gate holds the site and the auto-upgrade tick migrates it forward.
  `UpgradeRunner` stands down while a restore is in progress, so the two never race the DB.
- **Panel guard:** `admin.access` + **staff-2FA** (self-guarded in the SFC, like the Upgrade panel) + a
  **typed confirmation** (the backup's exact name). The action only records the request, then redirects to the
  self-refreshing maintenance page.
- **Failure policy (single-attempt, fail-safe).** A restore is destructive, so it is never auto-retried. A
  validation failure (nothing touched) lifts the gate. A restore-step failure — or a crash mid-restore,
  detected next tick because the file lock is free yet the state still says `running` — HOLDS the site
  (`restore.stuck`); the scheduler also skips every DB-touching job during the window. No-SSH recovery: delete
  the restore-state file via the host file manager, then re-restore from the panel / the named pre-restore
  safety snapshot (or `php artisan novfora:restore` with a shell). This is the one operability state that needs
  a deliberate operator action — the fail-safe choice for a destructive op.

**Consequences:** a no-SSH operator finally has a recovery path, on the baseline tier, with zero new
dependencies; the RH-10 recovery references ("restore the pre-upgrade backup from the Backups panel") become
true. **Deferred (scope fence):** restore from an UPLOADED off-host archive (needs a guarded untrusted-zip
upload) — flagged in [real-host-findings RH-11](docs/product/real-host-findings.md). Tested at the feature
level driving the runner directly (round-trip · the restored-older-schema → auto-upgrade hand-off · validation
refusal · maintenance entered/exited · audit · /health during the window · panel authz + typed-confirm). See
[real-host-findings RH-11](docs/product/real-host-findings.md).

### ADR-0023 — Site-settings store & precedence (ACP v1)
**Context:** ACP v1 needs a settings infrastructure that lets an operator change site behaviour from the panel
instead of hand-editing `.env`/`config` on the host. The binding requirement is the owner's live use case:
after deploy, *flip "new-user first-post hold" to 0 from the Moderation page — replacing the temporary
config-file edit, which the deploy overwrites.* So a panel override must **persist across deploys**, while an
**unset** key must keep tracking the host's `env`/`config` (which a deploy legitimately changes). Secrets
(SMTP password, Turnstile secret) must be stored at rest and never echoed.

**Decision:** a `settings` table (key/value/type, reversible) behind a typed `App\Settings\Settings` service,
with a code `SettingsRegistry` (one `SettingDefinition` per key: type, secret flag, config path, default,
group, label) as the single source of truth.
- **Precedence (per key):** DB override row → the registry's `config()` path → the registry's literal default.
  Because Laravel config files already fold `env(KEY, default)`, a single `config()` read realises the
  documented "env fallback → config default" tail in the framework-idiomatic, `config:cache`-safe way.
- **Defaults are NOT seeded as rows.** Seeding would shadow the env/config fallback and defeat the override's
  whole point. "Seeded defaults" therefore means the registry's in-code defaults; an unset key tracks
  env/config until an admin explicitly sets a value, after which the DB row wins and survives the next release.
  (`NOVFORA_NEW_USER_HOLD_POSTS` is wired into `config/novfora.php` so it participates in the chain.)
- **Caching (RH-9):** the whole bag is read **once per request** and cached as **primitives only** (the raw
  string column + type + encrypted flag — never an Eloquent model, never a decrypted secret). Decryption and
  typing happen after the cache boundary, in-process; a failed/empty read (pre-install, table missing) is
  **not** cached. Writes are write-through: upsert + immediate cache+memo invalidation.
- **Apply-to-config.** On boot (post-install only), every DB-overridden, config-backed key is pushed into the
  live `config()`, so existing consumers — the mailer, the anti-spam pipeline, `app.name`, the theme — honour
  a panel override with **no change to their own code**. The display-only `siteView()` bag (wordmark, notice,
  accent, width, poster position, board-list style) is resolved **inline** by the few views that need it
  (memoised: one cache read/request) — deliberately **not** a global view composer, which fired on every
  partial and pushed the test run past its memory cap.
- **Security.** Secret settings are `Crypt::encryptString`'d under the app key, decrypted only in-process,
  **masked** in the audit log, and never pre-filled into forms (placeholder/"leave blank to keep" semantics).
  Every write is audited (who, key, old→new, secrets masked).

**Consequences:** the operator becomes self-sufficient (settings, email, moderation, appearance from the
panel) with zero new dependencies; overrides survive deploys; the env/config fallback stays authoritative
until explicitly overridden. New settings are one registry entry. Tested: precedence, typing, write-through,
encrypted round-trip + masking, `applyToConfig`, forget, plus each page's real effect (offline 503,
registration gating, config override, appearance render). **Flagged (not invented, scope fence):** a post-edit
grace/edit-time window (no engine behind it); approval/invite registration modes (Phase 2).

### ADR-0024 — Project name: NevoBB (2026-06-07)
**Context:** "NevoBB" was only ever a working codename (the name is already used by another GitHub
project); brainstorming converged on the "Nevo" prefix (from Nova/Novellum), with a dual-brand split on
the table — NevoBB (engine/developer identity) vs. NevoForums (user-facing brand).
**Decision:** **single brand — NevoBB** for the engine, repo, site, packages, and docs. No second brand:
every comparable platform (phpBB, Discourse, Flarum, NodeBB) runs one name; a split costs two sites, split
SEO, and double upkeep with no payoff until a hosted tier exists. `nevoforums.com` is registered as a
redirect only, reserved for a possible future hosted tier (multi-tenant SaaS remains out of scope per the
brief).
**Availability (verified 2026-06-07):** `nevobb.com` and `nevoforums.com` unregistered (Verisign RDAP);
Packagist vendor `nevobb` free; no existing software uses the name. **github.com/nevobb is held by an
unrelated individual** (a personal account) — GitHub org candidates: `nevobb-forum` / `getnevobb`, TBD at
repo publish. **NodeBB adjacency accepted:** the *BB suffix is generic in this category (phpBB/MyBB/NodeBB
coexist); bare "Nevo" is crowded (Nevo Technologies at nevo.com, UEI's Nevo smart-home platform), which the
compound name avoids.
**Codebase rename = a separate, planned task** (~197 files reference nevo: `config/nevo.php`,
`nevo:*` artisan commands, `NEVO_*` env keys, "The NevoBB Authors" SPDX lines, docs). Execute as one
reviewed change with a documented `NEVO_*` → `NEVOBB_*` env migration, **before any public release** so
no operator contract is broken.
**Also recorded:** the "LLM background content seeding / simulated users" concept from the naming
brainstorm is **exploratory only — not in locked scope.** If ever pursued it needs its own ADR plus
disclosure/ethics design (it sits in tension with the anti-spam pillar, ADR-0007).
**Consequences:** one identity to build equity in; register both domains now; claim org + social handles;
formal trademark search before 1.0.

*(ADRs 0003, 0004, 0008, 0009, 0010, 0013, 0014, 0016, 0017, 0018 are summarized in the table above; full
detail in their linked docs.)*

### ADR-0026 — Project name: NovFora (2026-06-10)
**Context:** ADR-0024 locked "NevoBB" as the brand on 2026-06-07. Before the in-code rename ran, the
project lead registered `novfora.com` and `novfora.net` and decided NovFora better captures the
product's identity — evocative, distinct, and free of the "BB" suffix that signals legacy forum
software to new audiences.
**Decision:** **NovFora** is the final, permanent brand name for the engine, repo, site, packages,
and docs. ADR-0024 is superseded. "Hearth" and "NevoBB" are both retired codenames. The in-code rename
commit (`refactor(rename): Hearth/NevoBB → NovFora per ADR-0026`) covered `config/`, Artisan command
signatures, `NOVFORA_*` env keys, SPDX headers, and user-facing strings — executed before any public
release so no operator contract was broken.
**Availability (verified 2026-06-10):** `novfora.com` and `novfora.net` registered by project lead.
Packagist vendor `novfora` and GitHub org `novfora` to be claimed at repo publish.
**Consequences:** one identity to build equity in; update all docs, SPDX headers, and code references
via the rename task; formal trademark search before 1.0.

### ADR-0025 — Account deletion and content-cascade policy (2026-06-10)

**Context:** P2-M1 introduced per-account reactions, poll votes, and applied tags — participation data whose
ownership is inseparable from the acting user. Multi-participant PMs (M2 Half-B) will add message threads; a
participant being deleted must have a defined cascade before PM threads can be designed. Two interests are in
tension: community-content integrity (public posts made to the forum should remain readable) and individual
privacy (an account holder who leaves should have their identity erased from the record). Before M2 Half-B is
built, this decision must be locked — P2-M1 already relies on hard-deleting reactions/votes/tags at delete
time; this ADR confirms and extends that behaviour to the full cascade.

**Decision:**

1. **Posts are pseudonymised, not deleted.** A deleted account's posts remain on the board. `posts.user_id` is
   set to `NULL`; the author attribution renders as `[Deleted]` and the avatar falls back to the default guest
   avatar. The canonical body, edit history, and search-index entry are untouched — only the attribution pointer
   is anonymised. The user's profile page (`/users/{id}`) returns 404 (or a minimal "Account removed" notice).

2. **Reactions, poll votes, and applied tags hard-delete with the account.** These are participation metadata
   with no independent community value absent the actor identity. Ghost vote counts and phantom tag attributions
   are avoided. The post `reaction_count` is recounted after the batch delete using the authoritative
   `post_reaction_counts` recount already in place from P2-M1.

3. **The cascade is owner-confirmable.** Before deletion commits, the initiating party (the user for voluntary
   deletion; the admin for forced deletion) is shown a summary of what will be permanently removed: total
   reactions given, poll votes cast, tags applied. Explicit confirmation is required before any write occurs.
   Both voluntary and admin-forced paths present the same summary and require the same confirmation step.

4. **A single service executes both paths.** `AccountDeletionService` is invoked from the user's account
   settings and from the ACP member management screen — one audited cascade sequence, no duplication.

**Migration sketch (implementation notes — not runnable code):**

*Schema seam required before the feature is built:*
- `posts.user_id` must become **nullable** (if it is not already). A `NULL` value unambiguously means "deleted
  account" — no sentinel/placeholder row is needed, and referential integrity holds without a permanent stub.
  This is a single reversible `ALTER TABLE` migration. The render layer must handle `NULL` author → `[Deleted]`
  display name + guest avatar fallback.

*Cascade writes (inside a DB transaction, after the confirmation is submitted):*
- `posts` — `UPDATE posts SET user_id = NULL WHERE user_id = ?` (pseudonymise all posts).
- `post_reactions` — `DELETE FROM post_reactions WHERE user_id = ?`; dispatch `ReactionRecount` for each
  distinct `post_id` affected (or run inline if count is small) — uses the P2-M1 authoritative recount path.
- `poll_votes` — `DELETE FROM poll_votes WHERE user_id = ?`. Poll result percentages re-derive from
  `COUNT(*) GROUP BY option_id` at render time; no explicit recount job is needed.
- `taggables` attribution — `DELETE FROM taggables WHERE tagger_user_id = ?` (or equivalent attribution
  column). Tag `usage_count` must be decremented or recounted after the batch; use the authoritative recount
  pattern already established in P2-M1 rather than an in-place decrement (avoids race conditions).
- `post_drafts` — `DELETE WHERE user_id = ?` (private to the owner; no community value).
- `notifications` — `DELETE WHERE notifiable_id = ?` (in-app notification records for the deleted user).
  Also purge any `digest_queue` rows staged for the deleted `user_id`.
- `sessions` — hard-delete all active sessions to force immediate logout before the `users` row is removed.
- `users` — hard-delete the account row last, after all cascade writes are committed.

*Confirmation flow:*
- A pre-deletion summary query (read-only) assembles the counts in one pass: reactions given, poll votes cast,
  tag applications. These counts are shown in the confirmation UI before any write.
- The action is a two-step form: (1) initiate → show summary + confirm button; (2) submit confirmation →
  execute the cascade inside a transaction. Transaction failure = nothing committed.
- For admin-forced deletion the confirmation step is on the ACP member page; the summary and confirm
  requirement are identical to the voluntary path.

*Jobs and events affected:*
- **Queued notification jobs** — any job already in the queue whose target `notifiable_id` is the deleted user
  must silently no-op at handle time (check `User::find($notifiableId)` before sending; treat null as "skip",
  not as an exception).
- **`DigestQueue`** — delete all staged digest entries for the user during the cascade (before the `users`
  row is removed).
- **`ReactionRecount`** — must be dispatched (or run inline) for every post whose reaction count changed as a
  result of the hard-delete batch.
- **`SendReactionNotification`** and similar queued listeners — the deleted user as *actor* means the
  notification target (post author) may still receive a notification for an action that happened before
  deletion. These are benign and need no special handling; the actor display name resolves to `[Deleted]` on
  render.
- **Future PM threads (M2 Half-B)** — a PM participant being deleted means their participant row is
  hard-deleted; the thread and other participants' messages remain. Pseudonymisation applies to PM messages
  authored by the deleted user (same policy as posts). The exact PM cascade is confirmed when M2 Half-B is
  designed; this ADR establishes the governing principle.

**Consequences:** M2 Half-B (multi-participant PMs) is unblocked — the cascade contract PM participant
deletion must honour is now fixed. The P2-M1 hard-deletes for reactions / poll votes / tags are confirmed by
this decision; forced-cascade integration tests (recount correctness + notification pruning under deletion)
are deferred to when PMs land per PROJECT-STATE.md. The nullable `posts.user_id` column is a new migration
seam required before the deletion feature is implemented. Pseudonymisation (not erasure) of post bodies is
consistent with GDPR Recital 26 — anonymised data falls outside the regulation's personal-data scope — and
honours community-content integrity.

**Implementation follow-up — `AccountDeletionService` (2026-06-11, P2 packet).** The full multi-table service
deferred in the M1 / M2-B notes is now built (`App\Account\AccountDeletionService`) with both confirmation
surfaces (the voluntary `⚡delete-account` settings SFC and the admin-forced controller flow). Decisions taken
where ADR-0025 left a choice open, or where the FK reality forced one:

- **One transaction; ids captured first.** Both paths share ONE private `cascade()` inside a single
  `DB::transaction` — a mid-cascade failure commits nothing. The denormalised-tally inputs (reacted post ids,
  voted option ids) are captured BEFORE the participation rows are deleted, then `ReactionService::
  recomputeForPosts` / `PollService::recomputeForOptions` (new public batch seams reusing the existing private
  recount logic) recompute `post_reaction_counts` / `poll_options.vote_count` authoritatively from the
  survivors — a deleted reactor/voter leaves no phantom tally. The users row is deleted LAST; the real
  `cascadeOnDelete` FKs (notification_preferences, digest_*, group_user, topic_reads, custom_field_values, bans,
  warnings.user_id, conversation_user, user_relationships) then drop as belt-and-braces.
- **Soft-deleted content is pseudonymised too.** posts/topics use `SoftDeletes`, so the `user_id → NULL` UPDATEs
  run `withTrashed()` — the default scope would skip a soft-deleted row, leaving it pointing at the deleted user
  (a dangling id + a privacy leak).
- **audit_log.actor_id → NULL (the FLAGged call).** The deleted user's whole audit trail is de-identified
  (`actor_id → NULL`) — GDPR-consistent erasure of the actor IDENTITY while the WHAT/action rows remain. The
  `user.deleted` event keeps an inert `deleted_user_id` + `initiated_by` (`self`|`admin`); on the voluntary path
  the actor (== self) is nulled with the rest of the trail, on the forced path the admin's actor id is retained
  as the security record of who initiated. (Alternative — retain the full actor trail for the security record —
  rejected: the trail's value is the action, not the now-gone identity.)
- **email_suppressions deleted (the FLAGged call).** The table is keyed on the address (no `user_id`), so the
  user's row(s) are deleted to free the address for re-registration. (Alternative — retain the suppression —
  rejected: it protected an address the departing user no longer owns.)
- **Admin-forced gate = one predicate.** `AccountDeletionService::canForceDelete()` is the SINGLE guard reused
  by the service entry, the controller, and the profile trigger's visibility `@if`: `bans.manage` (global) +
  `ActorRank::canActOn` + two deletion-specific guards `ActorRank` alone does not give — NOT an admin of
  equal-or-higher rank (`ActorRank` lets any admin act on any admin), and NOT self (the force path is not for
  self-deletion). Modelled on the spam-clean surface; deliberately NOT restricted to the `admins` group beyond
  the rank guards, matching the binding packet.
- **Sole-admin guard.** Neither path may remove the last administrator (`isSoleAdmin`); the voluntary SFC
  surfaces it as a blocking message, the service enforces it on both entries (structurally redundant on the
  forced path, where the acting admin survives, but kept as defence).
- **Voluntary logout avoids an Eloquent re-insert.** The SFC deletes, then clears the session via
  `session()->invalidate()` — NOT `Auth::logout()`. After the cascade the session guard still holds the
  now-`exists=false` user model; `Auth::logout()` cycles its remember token and calls `save()`, which Eloquent
  treats as an INSERT and would silently re-create the just-deleted account. A stale remember-me cookie is inert
  (the user is gone).
- **No tag recount here.** Topics are pseudonymised, not deleted, so `taggables` (no per-user column) is
  untouched; ADR-0025's "tags applied" summary count is dropped (not derivable) and no tag path runs.
- **`[Deleted]` render.** A null author renders `[Deleted]` (a `:fallback` on `<x-ui.user-name>`) + a neutral
  guest avatar (an opt-in `:guest` silhouette on `<x-ui.avatar>`, leaving the generic null default unchanged) at
  the post and PM-message author sites; `/users/{id}` already 404s for a removed user (route-model binding). The
  queued `SendReactionNotification` / `SendPmNotification` already no-op a missing notifiable
  (`$deleteWhenMissingModels` + a `User::find` null-check) — covered by a test, no new code.

---

## Dependency license register (ADR-0015)

Per ADR-0015, each dependency is recorded with its license before merge. All are Apache-2.0-compatible
(MIT/BSD); **no GPL/AGPL is bundled**.

**M0 — repo-root app (2026-06-02):**

| Package | Version | License | Notes |
|---|---|---|---|
| laravel/framework | 13.13 | MIT | core |
| livewire/livewire | 4.3 | MIT | server-driven UI |
| laravel/scout | 11.2 | MIT | search abstraction (DB driver = baseline) |
| laravel/pint | 1.29 | MIT | dev — code style |
| larastan/larastan | 3.10 | MIT | dev — static analysis |
| pestphp/pest (+ pest-plugin-laravel) | 4.7 / 4.1 | MIT | dev — tests |
| laravel/dusk | 8.6 | MIT | dev — browser tests (used from M2) |

**M1 — identity & auth (2026-06-02):**

| Package | Version | License | Notes |
|---|---|---|---|
| laravel/fortify | 1.37 | MIT | headless auth — register/login/reset/verify/2FA; our own Blade views |
| pragmarx/google2fa | 9.0 | MIT | TOTP generation/verification for 2FA; pulled by Fortify |
| bacon/bacon-qr-code | 3.1 | BSD-2-Clause | QR-code SVG for 2FA enrolment; pulled by Fortify |
| paragonie/constant_time_encoding | 3.1 | MIT | constant-time base32/hex (transitive, via google2fa) |
| laravel/passkeys | 0.2 | MIT | **dormant** — a Fortify dependency; the passkeys feature, routes, and table are **not enabled** in M1 (out of scope) |

**M2 — forum content (2026-06-03):**

| Package | Version | License | Notes |
|---|---|---|---|
| symfony/html-sanitizer | 7.4 | MIT | the allowlist sanitizer — the content security boundary (ADR-0005 / security §4) |
| league/commonmark | 2.8 | BSD-3-Clause | Markdown input mode (already present via the framework; used with html_input=escape + unsafe-links denied, then sanitized) |
| @tiptap/* (core, pm, starter-kit, placeholder, mention, image, table, suggestion) | 3.x | MIT | WYSIWYG editor (JS); **no @tiptap-pro/***. Lazy chunk **132 KB gz**, code-split out of the 1 KB main bundle |

**Canonical-render decision (ADR-0005):** kept the **hand-rolled** TipTap-JSON→HTML mapper
(`app/Content/CanonicalRenderer`) rather than adding a `tiptap-php` dependency — it *is* the security
boundary, is small and fully under our control, and is proven by the XSS battery; `symfony/html-sanitizer`
is the authoritative allowlist backstop for every render path. (Editor-side `@tiptap/*` JS packages, all MIT,
are recorded with the editor commit.)

**M4 — notifications · search · SEO · theme (2026-06-03):** **No new dependencies.** Notifications use
Laravel's `notifications` table with a custom merge-aware `Notifier` (db + queued mail, ADR-0014); search uses
`laravel/scout` (already present, M0) on the `database` driver with a `SearchService` that **degrades to a
direct DB query** when the configured engine is absent (ADR-0010, tier-graceful); SEO is hand-rolled JSON-LD +
a cached sitemap (system-architecture §6); the **theme override layer** (`App\Theme\ThemeManager`, ADR-0009)
is a custom, semver'd contract over Blade's view finder (active theme → parent → core), documented in
[THEME-API.md](docs/THEME-API.md) (**THEME API v1.0**). Signatures reuse the M2 canonical pipeline + sanitizer.
Avatars/covers use the `public` disk (needs `storage:link`, an installer step). M4 implementation note: an
`@mention` notification is parsed from the canonical doc; held posts notify at approval, not at write.

**M5 — operability & the runnable MVP (2026-06-03):** **No new dependencies.** The installer, backups,
restore, health endpoint, demo seed, and scheduler are built on the framework: `symfony/process` (DB dump/
restore via `mysqldump`/`mysql`/`pg_dump`/`pg_restore`) and PHP's `ZipArchive` were already present (the M0
`novfora:backup` skeleton used them); the wizard is a single-file Livewire component; the perf budgets are a
Pest `Performance` suite + shell asset checks in CI. The Dusk editor journey was **executed for real** in a
new `docker/dusk/` Chrome image + a CI job (php:8.3 + system Chromium/ChromeDriver) — `laravel/dusk` (M0
dev-dep) and `@tiptap/*` (M2) are unchanged. **M5 implementation notes:** the install lock is a filesystem
marker, not a DB row (ADR-0020); `wire:model.blur` on the create-topic title (a value typed after a
validation-error morph reliably syncs on resubmit — found by running Dusk); the demo seed pins mail to the
array transport so it never hits SMTP during install.

**SPDX policy:** NovFora-authored source carries an `SPDX-License-Identifier: Apache-2.0` header. Laravel's
scaffolded stubs are left as-is and gain a header when meaningfully edited — retrofitting every stub adds
noise without value.

*(Spike 0 deps are recorded in [spike-0-memo.md](docs/product/spike-0-memo.md): `@tiptap/*` 3.24 MIT (core
only, never Pro), `symfony/html-sanitizer` 7.4 MIT, `@playwright/test` 1.60 MIT.)*

---

## Phase 2 / P2-M1 decisions (2026-06-09)

Engagement & content-depth milestone. Recorded per the kickoff's "record in DECISIONS.md" list.

**Edit-history diff — source & extraction (amendment #3).** The diff viewer is **format-aware** so it shows
real edits, not artefacts of the search projection:
- **markdown** posts diff the readable canonical source (`body_canonical['source']`).
- **tiptap_json** posts diff a **normalised, formatting-preserving text extraction** of the document —
  emphatically **NOT `body_text`**, which is the tags-stripped *search* projection: diffing it would hide a
  bold-/italic-/link-/image-only edit. The extraction (`App\Content\RevisionDiffService::extract`) walks the
  canonical doc to a line-per-block text that keeps markdown-like markers (`**bold**`, `[text](href)`,
  `![alt](src)`, `# heading`, list markers, code fences), so those edits surface in the diff.
- **Diff library: none.** A small dependency-free **LCS line diff** lives in the same service (the line counts
  in a post are bounded, so O(n·m) is fine). This avoids promoting `sebastian/diff` from `require-dev` (it is
  only a PHPUnit transitive) into runtime `require`, and keeps the clean-room/supply-chain surface minimal.
  The viewer escapes every diff line via Blade; add/del lines use the existing `success`/`danger` tokens.

**Trust-gate reasoning for the new permission keys (ADR-0006/0007 §2.3).** `react.create` (ungated; abuse via
a per-trust rate limiter), `poll.vote` / `tag.apply` (ungated participation), `poll.create` (**soft** TL gate —
withheld from the member preset, granted from TL1 via `$trusted`, TL0 `no` deny-by-default and admin-liftable;
blast radius is one topic), `tag.create` (**hard NEVER** at TL0 — a new tag enters the durable site-wide
namespace, the same true-spam-vector class as `post.links`/`post.images`, so an admin ALLOW cannot lift it),
`prefix.manage` (admin-only, global, like `groups.manage`), `post.history.view` (staff via the moderator
preset; the author always sees their own).

**Query budget (amendment #6).** The thread page holds the **≤30** ceiling with reactions **and** a poll
present (RH-9 per-(topic,version) count caches + batched per-viewer lookups + zero poll queries for poll-less
topics) — no ceiling change, no ADR needed. Prefix/tag listings are eager-loaded (no N+1; a warmed board with
15 prefixed/tagged topics stays well under budget).

**oEmbed — provider allowlist, the dedicated embed policy & SSRF (amendment #2 + security §3).** A post stores
the URL ONLY; a client never supplies embed HTML.
- **Provider allowlist** (`config('novfora.oembed.providers')`): `youtube` and `vimeo`. Each is a host-anchored
  regex capturing the id → a constructed iframe `src` on an **allowlisted embed host**
  (`www.youtube-nocookie.com`, `player.vimeo.com`). The provider's own returned HTML is never used.
- **Dedicated embed policy** (`App\Content\Oembed\EmbedPolicy`, SEPARATE from the post `ContentSanitizer`,
  which keeps forbidding iframes): an allowlisted match → ONE `<iframe>` with a fixed
  `sandbox="allow-scripts allow-same-origin allow-popups allow-presentation"` (no allow-top-navigation/-forms/
  -modals) + a minimal `allow`, `loading=lazy`, `referrerpolicy=strict-origin-when-cross-origin`; the src is
  escaped and pinned to the allowlisted host. A non-allowlisted (or failed) URL → a **NovFora link-card facade**
  (a safe `http(s)` link, never a provider iframe). The CSP `frame-src` lists the SAME embed hosts (defence in
  depth — keep in sync with the provider allowlist).
- **Render path**: the canonical `embed` node renders to a sanitizer-surviving placeholder span (class carries
  `sha256(url)` token); `EmbedRenderer::inject` swaps it for the trusted HTML AFTER sanitization, into
  `body_html_cache`. The post sanitizer never sees an iframe.
- **SsrfGuard** (the only server-fetch path — a best-effort provider title): https-only; resolves every A/AAAA
  and blocks if ANY address is private/loopback/link-local/reserved/CGNAT/IPv4-mapped-private/IPv6-ULA;
  re-validates EVERY redirect hop; pins host→a validated IP (CURLOPT_RESOLVE) against DNS rebinding; caps
  redirects, timeout and response size; fails CLOSED (→ facade) on any error. Resolution cached in
  `oembed_cache` (sha256(url) → trusted HTML, 7-day TTL). New dependency: none.

## P2-M2 Half-A — deliverability light-up & rich notifications (2026-06-09)

Light-up + wire-in of the dormant Spike-P2 pipeline (no rebuild). Constraints inherited from
[`spike-p2-memo.md`](docs/product/spike-p2-memo.md) §4 — idempotency lives in the committed
`UNIQUE(user,cadence,period)` row, the send is at-least-once, suppression+cadence are re-checked at send,
volume caps stay conservative. New dependencies: none. Reversible migration only (`bounce_reviews`).

- **`Notifier`→`DigestQueue` wiring + the `off` cadence semantics.** `Notifier::send()`'s MAIL channel now
  routes by the recipient's digest cadence: **immediate** (the default, absent row) → the UNCHANGED live
  queued send; **daily/weekly** → staged into the cron digest via `DigestQueue::enqueue()` (no immediate
  send); **off** → **no notification mail at all** — neither a digest nor an immediate send. The kickoff's
  "enqueue returns null for immediate/off → live path" describes the common (immediate) case; we resolve the
  `off` value as *full* mail silence, because the 4-value cadence picker (off/immediate/daily/weekly) is only
  coherent if `off` is distinct from `immediate`, and a digest-unsubscriber must not be re-flooded with
  immediate mail. The in-app (database) channel is unaffected by cadence; its notification id seeds the digest
  dedupe (merged same-thread notifications carry one digest line). Idempotency is unchanged — the assembler's
  committed UNIQUE row, never a lock.
- **One shared `SuppressionGate`.** `Notifier`'s private `suppressed()` is removed; it delegates to the shared
  `SuppressionGate::suppressed()`, so there is a single send-time suppression gate across the immediate and
  digest paths (memo §4 follow-up), re-checked at send.
- **Reaction notifications: AUTO-DISCOVERED + QUEUED.** Laravel event-discovery is active in this app, so a
  plain `handle(Event)` listener in `app/Listeners` is registered automatically — an *additional* explicit
  `Event::listen()` double-registers (a subtle double-notify we hit and removed). `SendReactionNotification`
  is therefore registered only by discovery, and is `ShouldQueue` so the notification work (the P2-M1
  `Reacted` seam → a `reaction` notification to the post author) is deferred to the DB queue and stays OFF the
  hot react/toggle action path — the react query budget keeps its **≤15** ceiling (the budget test fakes the
  queue, mirroring the baseline defer; no ceiling change). New event vocab `reaction`/`pm.received`/`follow`
  is seated across `NotificationController::EVENTS`, the mail/in-app/digest renderers and the prefs UI; only
  `reaction` has a live emitter — `pm.received` (M2 Half-B) and `follow` (M3) get theirs in those milestones
  (no fake emitters). Absent preference rows still default on (no seeder needed).
- **Unsubscribe GET-confirm / POST-apply split** (memo §8). A GET on the signed unsubscribe link only renders
  a confirm page and applies nothing (resists email-scanner prefetch); the opt-out (cadence → `off`) is
  applied only by a POST — the RFC 8058 one-click `List-Unsubscribe-Post`, or the confirm form (which posts
  to the same signed URL; HMAC preserved, CSRF-exempt).
- **SES + Mailgun webhook parsers** (memo §5). `ProviderWebhookParser` gains `ses` and `mailgun` arms,
  clean-room from the documented JSON (no SDK). SES: `Bounce`(Permanent→suppress / Transient→never) +
  `Complaint`, one event per recipient, and unwraps the common SNS `{"Type":"Notification","Message":"…"}`
  envelope. Mailgun: `event-data` `failed`(severity=`permanent`→suppress) + `complained`, flat-shape
  fallback. The parser stays **total + conservative**: garbage/unknown/missing-recipient → no event, never
  throws/500s, and **ambiguity prefers NOT suppressing** (a deliverable address is never wrongly silenced).
  Trust remains the controller's HMAC over the raw body — never the payload or a provider's own signature, so
  the endpoint is still only a *writer* to the suppression list (no SSRF sink).
- **Non-VERP bounce manual-review queue** (memo §2b / §8). A polled IMAP mailbox **without VERP** can't
  cryptographically authenticate a sender-supplied recipient, so it must not auto-suppress (suppression-as-
  DoS). `BounceParser::reviewCandidate()` is **additive** — `parse()` (and its "no VERP → suppress nothing"
  guarantee) is untouched — and surfaces a PERMANENT bounce / complaint **unverified** into the new reversible
  `bounce_reviews` table via the idempotent `BounceReviewQueue`. It is populated **only when VERP is disabled**
  (`reviewCandidate()` returns null while VERP is enabled, so a forged/absent-VERP bounce can't flood the queue
  — the same DoS class the VERP-only-identity rule closed). Transient `4.x.x` self-heals and is never queued.
  A new ACP card (Admin → System → Email) lets staff suppress by hand (the authentication) or dismiss.
- **Activation.** `.env.example` ships `NOVFORA_DELIVERABILITY=true` + `NOVFORA_DIGEST=true`; graceful absence is
  unchanged (no provider/webhook/VERP/IMAP → VERP/manual floor, `NullBounceMailbox`, never an error). The
  operator SPF/DKIM/DMARC + on-domain-`From` checklist (memo §5) is surfaced on the ACP Email page.

## P2-M2 Half-B — multi-participant PMs / conversations (2026-06-11)

Private messages — the product's first **co-owned PII** and a new mass-spam surface. The gating and the
deletion cascade are the load-bearing parts (Opus `xhigh`). New dependencies: none. Reversible migrations only.

- **PM gating runs entirely through the existing permission engine; the TL0 mass-PM NEVER is pinned.**
  `pm.send` was already catalogued (global scope) and seeded **NEVER on `tl0` / ALLOW from `tl1`** by
  `TrustGateSeeder` (config `antispam.trust_gates`). A dedicated regression test (`PmPermissionTest`) proves the
  NEVER is **absolute**: neither a per-user admin ALLOW (at global/forum/thread) **nor a group ALLOW** lifts it
  (security §1.2 step 5 short-circuits before precedence). `ConversationService` re-checks `pm.send` on **every**
  send, so a demotion to TL0 stops a user mid-conversation, not only at the inbox door.
- **Participant-only access is a Policy, not an ACL-scope entry.** PMs live OUTSIDE the forum scope tree
  (global → category → forum → thread), so there is no meaningful scope to resolve participation against.
  `ConversationPolicy` (auto-discovered) gates `view`/`reply`/`invite` on participation (+ the `can_invite`
  pivot flag); the `Gate::before` hook only routes **`Scope`-typed** args to the resolver, so a `Conversation`
  arg falls through to the policy. The service re-asserts participation as defence-in-depth (Livewire actions
  carry no route middleware).
- **The IGNORE check is enforced at the service layer at BOTH points.** A recipient who ignores the sender is
  **silently excluded** at conversation start (block semantics — the sender is never told who ignores them; if
  that empties the recipient list the send fails with a generic "none reachable"), and **cannot be added** via
  invite. A reverse-lookup index `user_relationships(related_user_id, type)` serves the "who ignores X" query.
- **Single content path; PMs skip the post display layer.** Message bodies render ONLY through
  `ContentRenderer` (+ `ContentSanitizer`) and pass `ContentModerator::review()` — identical sanitization to
  posts, no second path; a **REJECT** verdict aborts the write inside the transaction (no orphan conversation).
  PMs deliberately **omit the post DISPLAY enhancements** (word-filter replacement + oEmbed iframe injection):
  the sanitized HTML is stored/shown directly, keeping the PM surface minimal (no server-side fetch from PM
  content). `messages.approved_state` mirrors posts and records a **HOLD** verdict as `pending`, but delivery is
  **not** gated on it this milestone (there is no PM moderation queue yet — that is the M4 seam); the column
  exists so M4 can build the queue without a migration.
- **Schema — anonymisable authors vs. cascade FKs (ADR-0025).** `messages.user_id` and
  `conversations.created_by` follow the **posts.user_id pattern**: raw nullable, **no FK**, pseudonymised in app
  code on deletion (a raw users delete would dangle them, never NULL). `conversation_user.*`,
  `messages.conversation_id`, and **both** `user_relationships` endpoints are real `cascadeOnDelete` FKs.
  `posts.user_id` was **already** nullable, so no ALTER migration was added. `user_relationships.type` is a
  **`string(20)` + model constants**, not a DB `ENUM` — matching `posts.approved_state`/`reactions.type` for
  MySQL **and** PostgreSQL portability and clean reversibility.
- **`user_relationships` built once; only the IGNORE half is wired.** The FOLLOW half is the M3 seam — the
  table ships now (cross-milestone, avoids a later migration) but nothing wires follow into feeds/notification
  routing here.
- **Deletion cascade = the ADR-0025 PM contract (`PmAccountCascade`).** Authored messages pseudonymised
  (user_id NULL, body intact → thread stays coherent); participant rows hard-deleted; a conversation purged only
  once **no** participant remains; a started conversation keeps its thread with `created_by` anonymised;
  relationship edges hard-deleted on both endpoints — all in one transaction, run **before** the users row. The
  **full multi-table `AccountDeletionService`** (posts pseudonymise, reaction/tag recounts, notifications/
  sessions purge) and the deletion **confirmation UI** remain the broader account-deletion feature, outside this
  milestone's scope fence; this lands the binding PM portion + forced-cascade tests.
- **`pm.received` emitter: AUTO-DISCOVERED + QUEUED.** `SendPmNotification` (on the `MessageSent` event) mirrors
  `SendReactionNotification` — registered by discovery only, `ShouldQueue` so fan-out stays off the send path,
  `$deleteWhenMissingModels`. It notifies every OTHER active participant; the conversation id is passed as
  `thread_id` so repeat unread PMs **merge** into one notification. M2 Half-A had already seeded the
  `pm.received` vocabulary, renderers and prefs — this is its first live emitter. Forced-absence: the in-app DB
  notification always lands even when mail is down (the Notifier swallows transport errors).
