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
| 0027 | **Model routing = `ultracode` default, Fable@max apex** — every turn starts at Fable max-effort and downgrades (Opus `xhigh`/`high` → Sonnet → Haiku) as work proves pattern-replication, not correctness-load-bearing; supersedes Opus-`xhigh`-as-apex | Accepted | [§ADR-0027](#adr-0027--model-routing-ultracode-default--fablemax-apex-2026-06-12) |
| 0028 | **P2-M5 scope = social pack in the public beta** — follow + reputation/points + badges pulled from Should-tier HELD into M5 Core; staff notes / reputation leaderboard / TL-auto-promotion deferred to fast-follow; overrides plan §5 descope for these three | Accepted | [§ADR-0028](#adr-0028--p2-m5-scope-social-pack-in-the-public-beta-2026-06-12) |
| 0029 | **DB-backed style themes (ACP visual theme editor)** — named accent + sanitised custom CSS, single-active, cached + CSP-nonce'd `<style>` injection; a first slice of ADR-0009's visual configurator, distinct from the filesystem child-theme layer | Accepted | [§ADR-0029](#adr-0029--db-backed-style-themes-acp-visual-theme-editor-2026-06-12) |
| 0030 | **Members-directory visibility** — public `/members` listing gated by one setting (everyone→members→staff→disabled); a single `visibleTo()` authority shared by the route gate, the SFC self-guard, and the nav; 404 (no disclosure) for a non-visible viewer | Accepted | [§ADR-0030](#adr-0030--members-directory-visibility-2026-06-12) |
| 0031 | **Module / plugin foundation (Phase 3 B1)** — local-only packages under `modules/`, a validated `module.json` manifest, a semver'd MODULE API (`ModuleApi::VERSION`), lifecycle (install/enable/disable/upgrade/remove) with pre-enable compat + dependency checks, event/filter/slot seams, manifest-declared permission keys that ADD to the existing engine (never redefine core), and an ACP surface; slot + filter HTML is always re-sanitised | **Accepted — owner-authorized overnight build; flagged for review** | [§ADR-0031](#adr-0031--module--plugin-foundation-phase-3-b1-2026-06-13) |
| 0032 | **Visual theming + layout configurator (Phase 3 B2)** — a semver'd theme-API contract (`ThemeApi`: AA-safe CSS token list + named regions), a layout-widget system (`WidgetRegistry` + built-in widgets, module-extensible) placed into regions via an admins-only ACP configurator; widget settings constrained to declared fields, admin HTML sanitised, output via `<x-region>` | **Accepted — owner-authorized overnight build; flagged for review** | [§ADR-0032](#adr-0032--visual-theming--layout-configurator-phase-3-b2-2026-06-13) |
| 0033 | **REST API + outbound webhooks (Phase 3 B3)** — a versioned `/api/v1` read/write API with hashed personal-token auth, authorized AS the user through the EXISTING permission engine, rate-limited + paginated; outbound webhooks with per-endpoint HMAC signing (the inbound verifier's scheme), cron-driven delivery with retry/backoff (baseline-safe), an SSRF-guarded endpoint URL, and an admins-only ACP | **Accepted — owner-authorized overnight build; flagged for review** | [§ADR-0033](#adr-0033--rest-api--outbound-webhooks-phase-3-b3-2026-06-13) |
| 0034 | **Importers (Phase 3 B4)** — a clean-room, driver-based legacy importer (phpBB built + tested; MyBB/SMF scaffolded behind the same `SourceDriver`); idempotent + resumable via an `import_maps` ledger, BBCode→markdown, 301 redirect maps served by a route fallback, and a count-reconciliation verify pass; reads the legacy DB schema (DATA only), never the reference program | **Accepted — owner-authorized overnight build; flagged for review** | [§ADR-0034](#adr-0034--importers-phase-3-b4-2026-06-13) |
| 0035 | **Admin analytics (Phase 3 B5)** — privacy-conscious AGGREGATE daily metrics (no PII / per-user tracking) in `daily_metrics`, computed by a daily idempotent `novfora:analytics:rollup` cron (baseline-safe; totals as-of-day for correct backfill), with an admins-only dashboard (live totals + recent-days table) | **Accepted — owner-authorized overnight build; flagged for review** | [§ADR-0035](#adr-0035--admin-analytics-phase-3-b5-2026-06-13) |

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

### ADR-0027 — Model routing: `ultracode` default + Fable@max apex (2026-06-12)
**Context:** the model/effort routing in `CLAUDE.md` had **Opus 4.8 `xhigh`** as the apex for the
correctness-load-bearing core (permission masks, concurrency/idempotency, untrusted-input boundaries,
adversarial-review synthesis, spike GO/NO-GO, plugin/theme API design). **Decision:** **`ultracode`** is the
default mode — every turn **starts at Fable @ max effort** and **downgrades as it deems fit** the moment work is
clearly pattern-replication, not correctness-load-bearing. Rungs, top→bottom: **Fable@max** (apex) → **Opus 4.8
`xhigh`/`high`** → **Sonnet 4.6** → **Haiku 4.5**. The dividing line (correctness load-bearing vs. pattern
replication) is unchanged; only the apex rung and the start-high-then-downgrade default are new.
**Consequences:** the load-bearing core routes to Fable@max; Opus `xhigh`/`high` becomes the heavy rung below
the apex; Sonnet (scaffold/CRUD/sweeps) and Haiku (trivial) unchanged. Recorded in `CLAUDE.md §Model routing` +
the `PROJECT-STATE.md §Model & effort` mirror. Operating/workflow decision — not a stack or architecture change.

### ADR-0028 — P2-M5 scope: social pack in the public beta (2026-06-12)
**Context:** the §5 implementation-plan descope held the Should-tier social items (follow, reputation/points,
badges, staff notes, 2nd theme) as the lever to protect the schedule to beta. Approaching the Phase-2 closer
(P2-M5 → Public Beta), the owner reviewed the lever. **Decision:** pull the **social pack — follow +
reputation/points + badges — into M5 Core**, shipping it in the public beta; **defer** staff notes, a reputation
leaderboard / top-members surface, and trust-level auto-promotion-by-reputation to **fast-follows** after the
beta. The 2nd example theme stays an optional M5 stretch. **Consequences:** M5 grows from a polish+regression
closer into a meatier milestone with apex-tier work — the idempotent reputation/badge award, an **extended
ADR-0025 deletion cascade** (revoke rep sourced from a deleted user's reactions + recompute affected third-party
authors), and `follow.create` anti-spam; two new `nevo:` recompute crons join the Phase-5 rename surface (#8).
Build source: [`docs/product/p2-m5-beta-social-code-kickoff.md`](docs/product/p2-m5-beta-social-code-kickoff.md).
A scoping override of plan §5 for these three items — recorded, not silent; no locked stack/architecture
decision changes.

**P2-M5 implementation notes (2026-06-12, build).** Decisions taken where the kickoff left a choice open,
plus the mechanism records it mandates:

- **Reputation idempotency = `UNIQUE(source_type, source_id)`.** One polymorphic source awards at most
  once; `award()` is an `insertOrIgnore` against it and adjusts the denorm ONLY on a real insert, via an
  atomic SQL increment (never read-modify-write). `revoke()` decrements by the **stored** points (immune
  to later config-weight changes), gated on this caller having actually deleted the row — two concurrent
  revokes can never double-decrement. `recomputeFor()` overwrites the denorm with the authoritative ledger
  SUM (the self-heal); the hourly `nevo:reputation:recompute` cron sweeps it board-wide. Single-choice
  reactions reuse one row across type changes, so the type-change path is `syncSourceAward()` — re-point
  the UNIQUE slot (revoke stored → award new), a pure no-op when already aligned (the double-fire case).
- **`users.reputation_points` went signed.** The M0 column was `unsignedInteger`; negative reaction
  weights (e.g. `disagree` = −1) make the ledger SUM signable. Reversible migration; the `down()` clamps
  negatives to 0 before restoring unsigned so a rollback can't die mid-ALTER on strict MySQL.
- **The extended ADR-0025 cascade (the subtle one).** A deleted user's reactions awarded rep to OTHER
  authors. The cascade now captures the affected third-party recipients BEFORE the reaction rows drop
  (alongside the existing reacted-post-id capture), prunes the ledger rows sourced from those reactions,
  prunes the user's own received rep wholesale, then `recomputeFor()`s the affected authors from the
  surviving ledger — absolute SUMs inside the same transaction, mirroring the `post_reaction_counts`
  recompute; never ±deltas through a half-deleted graph. A queued award committing concurrently with the
  cascade can leave a transient residue — healed by the hourly recompute (same class of accepted race as
  the existing reaction recount note). `user_relationships` (both directions) and `user_badges` rows are
  deleted explicitly in the same transaction (their FKs cascade too — belt-and-braces).
- **Ledger-vs-source edges (accepted + documented):** a SOFT-deleted post keeps its banked rep (reversible
  moderation shouldn't destroy rep history; restore needs no re-award). A HARD-deleted post would
  FK-cascade its reactions away without events, stranding their ledger rows — no current flow hard-deletes
  posts (everything soft-deletes), so this is theoretical; revisit if a purge flow ever ships. On an
  UPGRADED board, pre-beta reactions grant **no retroactive reputation** — the ledger is the truth and
  awards flow only from events going forward (deliberate: recompute reconciles denorm↔ledger, it never
  invents history). Badge post-count criteria DO count historical posts (live COUNT), so the badge sweep
  awards those on upgrade.
- **`follow.create` is a soft gate via the `poll.create` pattern.** Withheld from the member preset
  (a member-preset ALLOW would lift the TL0 `no` under the most-permissive merge), granted from TL1 via
  `$trusted`, staff get it in the moderator preset, admin-liftable. `follow.delete` IS in the member
  preset — undoing your own follow is always allowed, even after demotion. Self-follow is a hard refuse in
  `FollowService` no ACL can lift. Mass-follow (a notification-spam vector) is bounded by the per-TL
  `FollowRateLimiter`; the followee's ignore graph is honoured at notification-delivery time (the edge
  still forms — ignore hides the person, it does not forbid the follow).
- **Following-feed cache key = hash of the sorted followed-id set + activity version.** Self-invalidating
  (a follow/unfollow changes the key immediately — no version counter to maintain) and shared between
  viewers with identical follow sets; primitives only, VisibleForumIds filter + rehydration strictly after
  the cache boundary (RH-9). The following window is its OWN bounded query (not a filter of the global
  window), so it does not inherit the M3 global-window starvation limitation. **Empty follow set → the
  global feed plus a "follow people to personalise this" hint** (the kickoff's proposed default, adopted).
- **Badge criteria are a closed set** (`join` | `post_count` | `reputation` + threshold), matched — never
  evaluated (no expression engine on admin input; deliberate M5 security fence). Awards are
  `insertOrIgnore` on `UNIQUE(user_id, badge_id)` and **permanent** — a lapsed criterion never revokes;
  the daily `nevo:badges:recompute` sweep only ever adds (catch-up for missed events). `post_count`
  criteria COUNT live **approved** posts directly — deliberately NOT `users.post_count`, which counts ALL
  non-deleted posts incl. held/pending and would award badges for unapproved spam. **Update 2026-06-12
  (post-beta polish):** the M0 `users.post_count` "unmaintained seam" is now **CLOSED** — maintained live
  (atomic ±1 on create / soft-delete / restore) + a one-off backfill; see ADR-0029/0030's polish batch. The
  badge sweep still counts approved posts directly for the bar above. Badge slugs are the stable identity
  (suffix-deduped on create, never changed on update).
- **`nevo:` cron names are deliberate** (`nevo:reputation:recompute`, `nevo:badges:recompute`) — they join
  the Phase-5 rename surface (#8) per the kickoff/this ADR; do NOT pre-rename them to the novfora: scheme.
- **Budgets:** react action ≤15 (steady-state, now pinned by a dedicated test — the rep award is a queued
  jobs-row insert off the hot path); forum index ≤20 holds with the feed tabs; **new documented ceiling:
  member profile ≤20** (follow panel: target + edge check + two COUNTs; badge chips: one query).
- **Optional creation awards ship OFF** (`NOVFORA_REP_POST_CREATED` / `NOVFORA_REP_TOPIC_CREATED`,
  default 0); `shouldQueue()` keeps even the jobs-row insert off the write path until an owner opts in.
- **2nd example theme: SKIPPED** (the one Should item carried to a fast-follow, per the kickoff's
  explicit carve-out) — the override layer remains covered by `ThemeOverrideTest` + the fixture theme.

**P2-M5 post-build adversarial review (2026-06-12; 62 finder/verifier agents, 6 dimensions,
verify-then-refute).** Fixed: **HIGH** orphan-ledger TOCTOU — an in-flight reaction-award job racing the
unreact/deletion-cascade source delete could land a permanent phantom ledger row; `award()` now takes a
LOCKING current read of the live source row inside its transaction (gone → abort; present → the delete is
ordered after the award and its revoke/prune sees the row), and the cascade captures the affected-author
set AFTER the reaction delete with `lockForUpdate` (current read, not the txn-start snapshot). **HIGH**
feed leak — a topic MOVED into a restricted forum escaped the row-level filter via its frozen
`scope_forum_id`; the feed now re-checks every rehydrated subject's LIVE forum against the same visible
set (zero extra queries). **MEDIUMs:** follow/unfollow cycling flooded the victim (one unread follow
notification per follower now — re-follow notifies again only after it is read); the badge sweep counted
moderation-held/rejected posts (now `approved_state = 'approved'`, matching the event bar); the hourly
reputation recompute rewrote every users row (now drift-only writes); ACP badge form join/threshold
validation deadlock + the 255-column/500-rule mismatch + a delete-confirm copy inversion. **LOWs:** badge
slug empty-name guard + create-race catch; profile chips render colour via the palette-validated
`GroupColor::cssVar`; a daily `novfora-cache-prune` task (DB store only — version-keyed entries are never
re-read and the database driver only evicts on read). **Accepted + documented:** recompute-vs-concurrent-
award snapshot races (healed by the hourly sweep — the accepted-race class already noted above); the
lost-revoke-job orphan (requires an exhausted-retries job; same failure class as any lost notification);
prolific-user cascade transaction length (mirrors the existing capture pattern); the following feed's
O(follow-set) render cost (human-bounded; churn-minted cache rows now pruned daily). **Fast-follows
flagged:** `isSoleAdmin` check-then-act under two concurrent self-deletions (pre-existing, pre-dates M5);
`ActivityVersion::bump` read-then-write lost bumps (pre-existing M3; 60s TTL backstop). Refuted findings
discarded after two-verifier review.

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

### ADR-0029 — DB-backed style themes (ACP visual theme editor) (2026-06-12)
**Context:** ADR-0009 anticipated a visual theming configurator alongside the Blade-override child-theme
layer. Operators want to recolour/restyle the site from the panel without authoring and uploading a
filesystem child theme. **Decision:** a DB-backed **style theme** — a named, AA-safe accent colour plus an
optional block of custom CSS — created, edited, and activated from *Admin → Settings → Themes*; **exactly
one active** (single-active invariant, enforced in a transaction). The active theme's CSS is compiled
(accent → light/dark CSS variables, then the custom CSS), cached forever and invalidated on every write, and
injected **once per request** into a **CSP-nonce'd `<style>`** in the head, **after** the Appearance accent
so an active theme wins on equal specificity. This is a **first slice of ADR-0009's "visual configurator"** —
CSS-only, no view overrides, no filesystem write — and is **distinct from** the filesystem child-theme layer
(which overrides Blade views via `ThemeManager` and still appears on the Appearance page). **Security:**
custom CSS is sanitised on **both store and render** — any `</style` close-tag (the only HTML RAWTEXT
breakout vector) and HTML comment markers are stripped, so it cannot escape the `<style>` element; the accent
is normalised to a validated `#rrggbb`; the body is bounded (20 000 chars). The editor is admin-gated
(`admin.access` + staff-2FA) in `mount()` **and** every action (a `livewire/update` action carries no route
middleware). **Consequences:** zero new dependencies; cosmetic-only — it feeds no permission resolution; the
residual CSS-level mischief a full admin could author (external `url()`, attribute-selector exfiltration) is
bounded by the site CSP and is no greater than the existing filesystem child-theme authority — an accepted
admin-trust residual. New reversible table `site_themes`. Tests cover create/activate, the single-active
invariant, the sanitiser breakout, an invalid accent → null, and deactivate, plus the admin SFC's resolution
+ self-guard.

### ADR-0030 — Members-directory visibility (2026-06-12)
**Context:** a public member listing is a standard community feature, but some communities (and privacy
regimes) want it restricted or off. **Decision:** a public `/members` directory — search, sort, and filter
by group / trust level — gated by one setting `members.directory_visibility` with nested tiers **everyone →
members → staff → disabled**. A **single authority**, `App\Community\MembersDirectory::visibleTo()`, is
consulted by the **route gate**, the Livewire component's **self-guard** (in `mount()` **and** the
`members()` query, since a `livewire/update` action carries no route middleware), **and** the header nav
link, so the three can never drift. A non-visible viewer gets a **404 — no disclosure** (deliberately not a
403). Only `status='active'` members are listed, reading only already-denormalised/public columns
(`post_count`, `reputation_points`, `last_active_at`) + groups for the name colour; search matches
username/display-name (never email). Admin control lives at *Admin → Members → Directory* (an admin-gated
SFC, on the ADR-0023 settings store — no schema change). **Privacy (documented):** the default `everyone`
lets guests **enumerate** all active members (no field beyond the already-public profile page, but bulk
enumeration is a new capability), and there is **no per-user opt-out** — admins can restrict the tier, and a
"hide me from the directory" toggle is a flagged fast-follow. **Consequences:** zero new dependencies; one
new settings key. Tests cover all four visibility modes via the route, active/banned filtering, and the admin
SFC's self-guard.

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

## P2-M3 — activity feed & community-feel pack (2026-06-11)

Core items only; the Should-tier (follow-based personalisation, reputation, badges, staff notes) stays HELD.
The two load-bearing seams are the per-viewer permission filter and the feed cache boundary (Opus `xhigh`); the
rest is scaffolding. New dependencies: none. Reversible migrations only (one new table + a one-line addendum).

- **`VisibleForumIds` — the query-level generalisation of the per-row `forum.view` check.** `ForumController`
  filters forums view-side per node via `$viewer->canDo('forum.view', $forum->permissionScope())`; the feed
  needs the SAME decision as a `WHERE scope_forum_id IN (…)`. `VisibleForumIds::for(User): ?array` loads every
  forum once and reuses `PermissionResolver` (its request memo + 30-min ACL cache make the per-node checks ~free
  on a warm request). **Sentinel:** returns **`null` when the viewer can see EVERY forum** (the common case) so
  the consumer omits the filter entirely instead of building a forum-wide `IN` list; **`[]`** means "sees no
  forum" and the consumer MUST short-circuit to an empty result (never run `IN ()`). Per-request static memo
  (keyed by viewer id; guest = 0) — never cached across requests, since permissions change. Built as the M4
  search-facet seam too, but **not** wired to search here.
- **Feed cache boundary — global window, primitives only, rehydrate + permission-filter AFTER.** The cache key
  is **viewer-independent and version-keyed** (`novfora.activities.feed.v{ActivityVersion}`, mirroring
  `AclVersion`; bumped on every `Activity::created`, 60 s belt-and-braces TTL). It holds the latest **100**
  activities as **scalar rows only** — never a model, related object, or the permission-filter result. After the
  boundary: `VisibleForumIds` filters per viewer, the page is sliced to **50**, then actors + subjects are
  batch-rehydrated (`User`/`Topic`/`Post`, `withTrashed`) — no per-row lazy loads. A cache-HIT test proves the
  second load re-queries **zero** `activities` rows. The forum-index budget rises **15 → 20** (amendment #6) for
  the feed's permission-filter + rehydration; the cache keeps it from being an N+1.
  - **Known limitation (cache-then-filter window).** Because the window is global-then-filtered, a heavily
    restricted viewer whose only visible forum is low-traffic can see an **empty feed** if every one of the
    latest 100 activities is from forums they cannot see. Acceptable for M3 (most activity is in forums most
    viewers can see). The fix — paginate past the window, or cache per visible-scope set — is an M4-era
    optimisation.
- **Verbs logged post-commit; held content and PMs are excluded.** `topic.created` / `post.created` /
  `react.given` are recorded by **auto-discovered listeners** (handle-typed, like `SendReactionNotification`).
  `Reacted` already fires post-commit; new `TopicCreated` / `PostCreated` events are dispatched from
  `PostService` after the write commits **and only for `approved` content** — a held (pending-moderation) topic
  or reply is author-+-mods-only and must never leak into the public feed. The opening post emits `topic.created`
  (never also `post.created`). **PMs log nothing** — they are private, carry no `scope_forum_id`, and must not
  appear in any feed.
- **`activities.actor_id` has no FK; `scope_forum_id` is `nullOnDelete`.** `actor_id` mirrors `posts.user_id` so
  the ADR-0025 cascade pseudonymises it (a one-line addendum in step (b), same transaction, before the users row
  drops → the actor renders `[Deleted]`, verb/subject intact). **Edge case:** a **hard** forum delete nulls
  `scope_forum_id` (`nullOnDelete`), so those activities then read as unscoped (visible to all). Acceptable for
  M3; a future `ForumObserver` can delete-cascade them. The feed renders a **tombstone** (no link) for any
  null/soft-deleted subject, and `[Deleted]` + the guest avatar for a null actor.
- **Community-feel pack.** `users.last_active_at` is stamped by `ThrottledLastActive` (web group) as a **raw DB
  update, ≤ 1 write / user / 5 min** (no model hydration → no events); `User::isOnline()` uses a wider **15-min**
  window so a user never flickers between throttled writes. `topics.view_count` increment is throttled via
  `Cache::add` to **once per viewer (or guest session) per topic per hour** (no F5 write-storm), replacing the
  prior unconditional increment. `forums.topic_count` / `post_count` were **already** maintained by
  `Topic::booted` / `Post::syncAggregates` and already displayed on the index — M3 only adds tests.

## P2-M4 — moderation depth, search facets & consolidated preferences (2026-06-12)

Four Core items; staff notes stay HELD. The load-bearing seams (Opus `xhigh`) are the merge/split transaction +
authoritative recount and the bulk-select rank guard; the rest is scaffolding wired onto the M3 `VisibleForumIds`
seam. New dependencies: none. One additive, reversible migration (two nullable `users` columns).

- **Merge/split bypass `Post::syncAggregates`, then recompute authoritatively (`TopicCounters`).** Bulk-moving
  posts via `$post->save()` would fire the per-post `syncAggregates` observer N times — an N+1 write storm AND a
  double-count (each per-row recompute sees a half-moved set). So both services move posts with a **single raw
  `DB::table('posts')->update()`** (observer-free), then re-derive the affected topics' + forums' denormalised
  counters **once**, from direct SQL aggregates, in the same transaction. Every counter is a `COUNT`/`MAX` over
  the live set — never an incremental ±delta — so a recompute is drift-free and **OVERWRITES** any observer delta
  that fired during the structural change (e.g. the source topic's soft-delete `-1`). **Counter definitions:**
  `topic_count` = non-trashed topics in the forum; `post_count` = non-trashed posts whose (non-trashed) topic is
  in the forum (posts under a soft-deleted topic don't count). The whole mutation is **one `DB::transaction`** —
  a rollback test injects a throwing `TopicCounters` double (hence `TopicCounters` is non-`final`) and proves a
  mid-merge/-split failure commits nothing.
- **Merge appends, keeping the target's opening post.** The source's positions (`1..n`) collide with the target's,
  so the same raw UPDATE **offsets** the moved posts past the target's current max position (`position + offset`,
  `offset` an int — safe to inline): the source posts fold in **after** the target's, relative order preserved, no
  clash, and the **target keeps its OP** as `first_post_id`. `last_post_*` follow chronology (`created_at`), as
  `syncAggregates` does.
- **`moved_to_topic_id` 301 redirect over a `withTrashed` route binding.** Merge **soft-deletes** the source
  (never hard — the redirect must survive) and stamps `moved_to_topic_id` + `status='merged'`. The `topics.show`
  route resolves **`->withTrashed()`** so the soft-deleted shell still binds; `TopicController::show` 301s to the
  target when `moved_to_topic_id` is set, and 404s any **other** trashed topic (recycle-bin semantics preserved).
  `moved_to_topic_id` stays a **bare nullable column, no FK** — it is a redirect pointer, not a relational
  invariant, and an FK would block the source's eventual hard purge.
- **Bulk-select rank guard: silent-skip + audit, deliberately non-transactional.** `BulkModerationService`
  eager-loads authors (`whereIn` + `with('author')`) and, for every selected item, checks the forum gate
  (`post.delete.any` / `topic.moderate`) AND `ActorRank::canActOn($actor, $item->author)`. An item the actor
  cannot act on (higher-ranked author) is **silently skipped** — never actioned, never an error — and BOTH the
  `applied` and `skipped` id sets are returned and **audited** (one entry per bulk action, with both arrays).
  Partial success is the design — the **opposite** of merge/split's all-or-nothing — so there is intentionally NO
  outer transaction. A null author (pseudonymised account) has no rank to out-rank → eligible. The gate is
  enforced in the SERVICE, so the ids arriving from the client (the Alpine `bulkSelect` store, passed to a
  `$wire` method) are never trusted — only the server verdict acts.
- **No post-level "hide" status → bulk hide/unhide skipped.** A post's only moderation axes are `approved_state`
  (approved/pending/rejected) + soft-delete; there is no `is_hidden`/`visibility` column. Per the kickoff's
  "check before implementing", **bulk hide/unhide is NOT built**; bulk post actions are delete + split-off only.
- **Search facets: DB-driver query is the tested baseline; Meilisearch gets a filter-string translation.** The
  Scout `database` engine LIKE-matches over every `toSearchableArray()` key as a real column, so adding
  `forum_id` (which lives on topics, not posts) or numeric facet columns there would break keyword matching on
  the baseline. So `toSearchableArray()` stays **`body_text`-only on the DB tier**, and the facet fields
  (`user_id`/`topic_id`/`forum_id`/`created_at`) are added **only when `scout.driver === 'meilisearch'`** (where
  they are filtered, not LIKE-matched). The faceted `SearchService::search(SearchQuery)` runs as a direct,
  controllable Eloquent query joined to the topic for forum/tag/type/visibility — fully correct on the baseline
  with **no external engine** (the baseline IS the DB). `meiliFilter()` translates the same `SearchQuery` into
  Meili native filter clauses for the enhanced tier (unit-tested at the string level). The original keyword-only
  `posts()` path (typeahead + Scout-first degrade) is unchanged.
- **`VisibleForumIds` threads through EVERY search path — reused, not rebuilt.** `search()` resolves
  `VisibleForumIds::for($viewer)` and intersects it with the optional forum facet: `null` (sees all) → no forum
  constraint; `[]` (sees none) → empty result immediately (never `IN ()`); the chosen forum not in the visible
  set → empty. A restricted viewer therefore cannot retrieve a post from an inaccessible forum via the forum
  facet or any other combination. The M3 class is used as-is; no second resolver.
- **Consolidated preferences = `posts_per_page` + `thread_sort` (the only meaningful, wireable prefs).**
  Signatures aren't rendered in the topic view, so a show-signatures toggle would control nothing (dropped);
  appearance/notifications stay in their own tabs (no reshuffle). The two prefs were **hardcoded** in
  `TopicController` (`paginate(15)`, position-asc), so they are genuinely behaviour-changing and end-to-end
  testable. Stored in two **nullable** `users` columns (null → site default 15 / oldest; reversible migration,
  no backfill); written ONLY by the own-account `⚡user-preferences` SFC, **validated against `User`
  vocabulary**, and kept OUT of `#[Fillable]` (no mass-assignment). `TopicController` honours both for the viewer
  (a guest resolves to the defaults). Budget: the moderator topic view (merge modal + bulk bar) holds **≤ 35**;
  the faceted search page holds **≤ 25**.

**Post-build adversarial review (P2-M4 · 26 agents · 6 dimensions · per-finding verify-then-refute).** 20 raw
findings → **9 confirmed real** (1 MEDIUM, 8 LOW), all fixed or consciously accepted; 11 refuted (incl. a claimed
rank-guard bypass and a Meili-staleness defect — both shown unreachable). Fixes applied:
- **MEDIUM — bulk move destination gate.** `moveTopics` resolved its destination scope as `Scope::forum($id)`
  over the **client-writable, un-Locked** `moveTarget` prop; a forged id pointing at a **category** passes (mods
  hold `topic.moderate` at global, inherited everywhere) and would re-parent topics under a non-postable
  container. Fixed: load `Forum::where('type','forum')->find($id)`, derive the gate from the real node, **skip
  all** when it is not a postable forum.
- **LOW — merge redirect authz + chain.** The 301 fired before any visibility check and followed only one hop.
  Fixed: resolve `moved_to_topic_id` **transitively to the terminus** (a chain of merges collapses to one 301,
  never an N-hop chain) and **404 unless the viewer can see the target's forum** (no target-id existence leak).
- **LOW — `VisibleForumIds` empty universe.** Zero forum rows (e.g. all soft-deleted) collapsed into the
  **sees-all** sentinel (`count([]) === count([])`), dropping the filter and over-returning feed/search rows
  keyed on a since-removed forum. Fixed: an empty universe resolves to **sees-none (`[]`)** — a one-line internal
  guard, interface unchanged (not an M3 re-implementation).
- **LOW — facet-index driver set + honesty.** `toSearchableArray` now gates facet fields on
  `['meilisearch','typesense']` (matching `MeilisearchProbe::configured()`), and the `meiliFilter` docstring no
  longer claims a tag/type pre-resolution that does not exist (the faceted page stays on the DB engine; the Meili
  translation is unit-tested but unwired).
- **LOW (accepted) — audit actor under non-web callers.** `Audit::log` stamps `actor_id` from `auth()->id()`
  (null in queue/CLI); merge/split already record the actor in the `by` field and all real callers are
  authenticated Livewire components — a pre-existing helper limitation, documented, not re-plumbed here.
- **LOW (no change) — cross-forum merge.** Intended (kickoff step e recomputes BOTH forums; a dedicated test
  asserts it). The quick-merge modal lists same-forum candidates as a UI default only — clarified in the SFC.
Each behavioural fix carries a regression test (category-destination skip; redirect 404-not-301 for a blind
viewer; A→B→C terminus collapse; empty-universe sees-none).

**Bulk-select client wiring (Dusk-discovered; reusable for future interactive UI).** The Dusk journey caught
four client-side defects invisible to the service-level Pest suite: (1) Alpine stores are read as a PROPERTY
`$store.bulkSelect`, never a function call `$store('bulkSelect')` (the latter throws "$store is not a
function"); (2) Alpine only initialises directives inside an `x-data` tree, so a toggle/checkbox in plain Blade
(outside a Livewire component, which Livewire scopes for you) needs an `x-data="{}"` ancestor or it is silently
ignored — wrapping the page container fixes it (nested Livewire components keep their own scopes); (3) `bottom-0`
is NOT in the prebuilt CSS, so a `fixed bottom-0` bar must be pinned with an inline `style` (assets-fresh: no
Node rebuild here); (4) a fixed bottom bar overlaps lower content — reserve bottom space in select mode. The
moderator action redirects with `navigate:false` (full reload) so the Alpine store resets and the session flash
renders. The Dusk journey asserts the rank-guard OUTCOME (eligible deleted, higher-ranked survives), not a flash
string, with generous waits for the slow local docker env (≈9 s page loads — the same env flakiness recorded for
the M2B/installer journeys; validate in clean CI).

---

## Fast-follow backlog notes (2026-06-13, owner-authorized overnight build — flagged for review)

> These are the M5-deferred fast-follows (PROJECT-STATE §3 / ADR-0028) and the two pre-existing concurrency
> hardenings flagged in the P2-M5 review, built unattended as one pass. Each is real code + tests, gated green
> and pushed per item. Non-obvious calls are recorded here; no locked stack/architecture decision changes.

### A1 — Staff notes
Private staff-only notes ABOUT a member (`staff_notes` table; `App\Models\StaffNote`; `App\Moderation\StaffNotes`
authority; `<livewire:moderation.staff-notes>` SFC on the profile). Non-obvious calls:
- **Gated on the EXISTING `bans.manage` (global), not a new permission key.** `bans.manage` is held by
  moderators + admins (RoleSeeder), i.e. staff — so staff notes reuse the engine with no new ACL seeding, no
  new mask semantics, and no second permission system (the project rule). The authority adds one clause the
  raw permission can't express: **viewer ≠ subject**, so a note never appears on the subject's own profile even
  when the subject is themselves staff ("never visible to the subject").
- **Add = any staff; edit/delete = author OR admin.** `StaffNotes::canManage` is the single predicate; a note
  whose author has been de-identified (author_id NULL) is manageable only by an admin.
- **`staff_notes.author_id` carries no FK and is pseudonymised by the ADR-0025 cascade** (NULLed like
  `warnings.issued_by`), so a note AUTHORED by a since-deleted staffer survives and renders "[Deleted]"; notes
  ABOUT a deleted member cascade away via the `user_id` FK. (One line added to `AccountDeletionService::cascade`.)
- **SFC self-guards in mount() AND every action** (Livewire actions carry no route middleware) — defence in
  depth behind the profile `@if`. Body bounded at 5000 chars; every write audited
  (`staff_note.created|updated|deleted`).

### A2 — Reputation leaderboard / top-members
Public "Top members" board (`/members/top`, `<livewire:leaderboard>`) reachable from a tab on the members
directory. Non-obvious calls:
- **Same visibility gate as the directory** — `MembersDirectory::visibleTo()` in the route AND the SFC
  (mount + rows()); a non-visible viewer gets 404 (no disclosure), exactly like `/members`. No new setting.
- **All-time reads the denormalised columns; windowed views aggregate the SOURCE OF TRUTH.** All-time orders
  by `users.reputation_points` / `users.post_count` (cheap). The 30-day / 7-day windows can't use a lifetime
  denorm, so they aggregate the authoritative tables: reputation from `SUM(reputation_events.points)` in the
  window (`HAVING > 0`), posts from `COUNT(posts)` filtered to `approved_state='approved'`, not soft-deleted,
  and a non-NULL (non-pseudonymised) author. `DB::table` bypasses the SoftDeletes scope, so `deleted_at` is
  filtered explicitly. This means a windowed board reflects real recent activity and isn't skewed by lifetime
  totals.
- **Deterministic ties** — `ORDER BY metric DESC, users.id ASC`; only ACTIVE members, only positive metrics.
  `selectRaw`/`orderBy` identifiers come from a closed `{post_count, reputation_points}` set, never user input.
- **Bounded at 25 rows** (a board, not a paginated directory). Reuses the directory's denormalised columns and
  the existing `<x-ui.tabs>` / `<x-ui.avatar>` / `<x-ui.user-name>` primitives — no new query on the hot path.

### A3 — Trust-level auto-promotion by reputation (APEX — trust/permission-adjacent)
Added a `min_reputation` criterion to the trust auto-promotion rules, wired into the existing
`novfora:trust:recompute` (no command change — `recompute → evaluate → earnedLevel` already drives it).
Non-obvious calls:
- **Reputation is a PROMOTION-ONLY gate (the load-bearing decision).** A reputation bar can block climbing to
  a level ABOVE the member's current standing, but it must NEVER pull a member below a level they already
  hold — otherwise the periodic recompute would *spuriously demote* every existing tl2/tl3 member whose
  reputation happens to sit under the new bar. `earnedLevel()` therefore exempts any rung at or below the
  current level from the reputation check (`$level <= $current || reputation >= min_reputation`). Structural
  demotion (losing the posts/tenure/reads for a level you sit at) is unchanged — the rep gate never masks it.
- **Thresholds live in `groups.auto_promotion` JSON, not config** — tl2 = 10, tl3 = 50; tl1 stays
  reputation-free so a brand-new member (zero rep) can still earn TL1 by engagement alone. Fresh installs get
  the values from `GroupSeeder` (`updateOrCreate`); **upgrades get them from a dedicated migration** that
  backfills the key onto existing rows (RH-10: auto-upgrade runs migrations only, never seeders). The
  migration is idempotent and never clobbers an operator-tuned value.
- **`reputation_points` is read off the already-loaded User model** (no extra query). Tests pin: promote
  at/over the threshold, held below it, **no spurious demote** for a rep dip (the floor), structural demotion
  still fires, idempotent recompute, and the upgrade-migration backfill + reversibility.

### A4 — Second example theme (`themes/aurora`)
A shipped filesystem child theme exercising the `ThemeManager` view-override API — distinct from the DB
style editor (ADR-0029). Non-obvious calls:
- **Two minimal core override seams added** so a filesystem theme can ship a SITE-WIDE palette without
  overriding the monolithic `layouts/app.blade.php`: `@include('partials.theme-head', ['nonce' => $nonce])`
  in `<head>` (emitted LAST so a theme accent wins on equal specificity, like the DB style theme) and
  `@include('partials.footer-tagline')` in the footer. Both default partials are inert (the footer renders
  the same text as before — no test asserted it), so the change is invisible until a theme overrides them.
- **The palette is AA-derived, not hand-picked** — Aurora's `theme-head` override feeds its accent
  (`#0e7490`, a deep teal) through `App\Support\AccentPalette`, the same WCAG-AA machinery the Appearance
  accent and the style editor use, so the light/dark accent inks are guaranteed AA. CSP-nonce-aware.
- **Ships inactive** — no active theme by default, so the default appearance is unchanged; an admin selects
  Aurora via `NOVFORA_THEME` / Appearance. Tests mirror `ThemeOverrideTest`: boot + direct view render,
  asserting the override resolves ahead of core and the core defaults render when inactive.

### A5 — isSoleAdmin TOCTOU hardening (APEX, concurrency)
Closed the check-then-act window in the last-admin guard (flagged in the P2-M5 review). Non-obvious calls:
- **The authority is a LOCKED re-read INSIDE the deletion transaction**, run as the first act before any
  mutation: `assertNotSoleAdminLocked()` does `SELECT … FROM group_user JOIN groups WHERE slug='admins' FOR
  UPDATE`, re-derives admin-ness from the locked pivot rows (DB truth, not a stale model), and throws if the
  target is the lone admin — rolling the transaction back. Two concurrent admin self-deletions both pass the
  fast pre-filter (each still counts two admins), but the FOR UPDATE serialises them: the first commits, the
  second then reads one admin and aborts. The public non-locking `isSoleAdmin()` stays as a cheap pre-filter /
  UI signal — explicitly NOT the authority.
- **Race test without threads**: `AccountDeletionService` is `final`, so the test reproduces the staleness
  with a stale in-memory model — the user is loaded as a non-admin, then made the sole admin directly in the
  DB, so the pre-filter (which reads `isAdmin()` off the cached groups) returns false and the deletion gets
  *past* it, yet the in-transaction locked re-read sees the live lone admin and aborts. SQLite has no row
  locks, but the in-transaction live re-read is still correct there; the lock matters only under real MySQL/PG.

### A6 — ActivityVersion / AclVersion lost-bump hardening (APEX, concurrency)
Made the cache version-counter bump atomic (flagged in the P2-M5 review). Non-obvious calls:
- **`Cache::add` (seed-once, SETNX-style) + `Cache::increment` (atomic)** replaces the read-modify-write
  (`current() + 1` then `Cache::forever`) whose two concurrent callers could both read N and both write N+1 —
  losing a bump and leaving a stale, version-keyed feed / resolved-permission entry served to other readers.
  Applied identically to BOTH `App\Community\ActivityVersion` and its structural twin `App\Permissions\
  AclVersion`. `current()` and the graceful-degradation contract (a dead cache never errors, never returns a
  wrong answer) are unchanged.
- **Concurrency test without threads**: the array cache driver is atomic within the process, so the suite pins
  the *primitive* (a Cache mock asserts `increment` is called, not a `get`/`forever` pair an interleave could
  split) plus cold-start, an exact no-lost-update count over 100 bumps, and the throw-path fallback.

---

## Phase 3 — Extensibility (owner-authorized overnight build — flagged for review)

> Phase 3 builds the Stage A extensibility plan (ADR-0008/0009/0013) into running code. Each subsystem ships a
> PROPOSED-then-Accepted ADR, the implementation, tests (apex-level on the security/permission/concurrency
> paths), gates green, and a commit. Full design set: `docs/architecture/phase3-extensibility/`.

### ADR-0031 — Module / plugin foundation (Phase 3 B1) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context:** every incumbent's worst trait is a bad extension architecture (MyBB `eval()` templates, SMF core
patches) — add-ons and themes that break on upgrade, customisation that needs core edits. ADR-0008 designed
the answer in Stage A; B1 builds it. This is the project's **highest-stakes security boundary**, so the build
is conservative and fail-closed.

**Decision (the public contract surface):** a module is a **local** package under `modules/<vendor>/<name>/`
(no remote fetch, no marketplace, no eval). The pieces:
- **Manifest** `module.json`, validated by `App\Modules\ManifestValidator` (the untrusted-input boundary):
  slug = path-safe `vendor/name`; namespace non-core + PSR-4; provider inside the module namespace;
  version/api_version/dependency constraints parse (`SemverConstraint`); permission entries well-formed. Fail
  closed — nothing coerced.
- **Semver'd MODULE API** = `App\Modules\ModuleApi::VERSION` (`1.0.0`). A module declares the `api_version`
  constraint it targets; compatibility is checked BEFORE install/enable ("know before you enable").
- **Lifecycle** = `App\Modules\ModuleManager` (the single audited writer): install → enable (compat + deps +
  permission registration + migrations) → disable (non-destructive: KEEP data) → upgrade → remove (roll back
  migrations + drop owned permission keys & their grants). `App\Modules\ModuleLoader` boots enabled modules
  each request (runtime PSR-4 + `register()` the provider); a broken/missing module is skipped, never fatal.
- **Seams:** Laravel **domain events** (the ones core already fires); a **filter-hook** pipeline
  (`Hook::applyFilters`/`addFilter`, priority-ordered, no-op until a module opts in); **UI slots**
  (`<x-slot-outlet name="…" />` + `SlotRegistry`); routes/Livewire; manifest **permission keys**; reversible
  **migrations**. Versioning: adding events/filters/slots = minor; changing/removing a payload/name/signature
  = major.

**Security posture (apex, non-negotiable):**
- **No permission escalation.** Manifest permission keys only ADD to the catalog; grants stay separate
  `acl_entries` an admin creates. A module may NEVER redefine a core key (refused at enable) nor claim another
  module's key. Removal deletes the module's keys AND any grants referencing them (no dangling ACL) and bumps
  `AclVersion`. There is no second permission system — module permissions resolve through the existing
  `PermissionResolver`.
- **No unsanitised HTML.** Slot output and `post.html` filter output are RE-sanitised through the same
  `ContentSanitizer` allowlist as user post HTML — a full-trust module still can't smuggle `<script>`/`<style>`
  onto a page. The `post.html` re-sanitise pass is skipped entirely unless a module registered a filter (the
  unextended hot path is unchanged).
- **No traversal / shadowing.** The validated slug bounds the directory; the namespace can't be a core root;
  the provider must live in the module's own namespace.
- **Honest trust model:** modules run in-process with full PHP trust (no PHP sandbox is feasible); the
  mitigations are local-only install, manifest validation, ACP visibility, and audited lifecycle.

**Non-obvious build calls:** disable is **non-destructive** (keep schema/data; only `remove` purges) — a safer
refinement of ADR-0008's "disable rolls back migrations". Module migrations run via
`Artisan::call('migrate', ['--path' => …, '--realpath' => true])` and roll back the same way on remove. The
runtime autoloader (`spl_autoload_register` over the enabled modules' namespaces) avoids editing
`composer.json` / `dump-autoload` per module. The ACP page is **admins-only** (`admin.access` + staff-2FA) —
installing a module loads in-process code, the highest-privilege act. A first-party example plugin
(`modules/novfora/hello`) exercises every seam and is the lifecycle's living integration test.

**Consequences:** the module API is a frozen-within-a-major public contract from this commit; B2–B5 build on
these seams. Zero new runtime dependencies. **Flagged for review:** the full-trust execution model (documented,
unavoidable for PHP); module migration rollback uses `--path` batch semantics (fine for the typical one-batch
module; revisit if a module ships many migration batches). New reversible table `modules`.

**Post-build adversarial review (2026-06-13).** A skeptic pass over the seven attack vectors (manifest
validation, autoloader shadowing, permission escalation, slot/filter HTML, ACP authz, manifest-swap,
migration injection). **Fixed — HIGH path traversal:** the lifecycle `$slug` (attacker-influenceable via a
`livewire/update`) reached `dirFor()`/`srcPath()`/`migrationsPath()` UNVALIDATED — only the manifest's internal
slug was checked, and its dir cross-check used only the last two path segments, so `install('a/../../tmp/evil')`
could read/migrate/load code from outside `modules/`. Closed by asserting the slug at the single chokepoint
(`ManifestValidator::assertSlug` in `ModuleManager::dirFor`, which every path helper routes through) — pinned
by a traversal-refusal test. **Fixed — MEDIUM monotonic version:** `upgrade()` now refuses a manifest version
older than the recorded one (a swapped manifest can't roll the version backwards to fool a downstream
`requires`). **Confirmed BLOCKED:** autoloader shadowing (appended, not prepended; reserved roots; provider
scoped to the module namespace), permission escalation (catalog-only writes, never `acl_entries`; complete
core-key + cross-module collision checks), unsanitised HTML (slot + `post.html` both re-sanitised before
`{!! !!}`), ACP authz (`ensureAdmin` in mount + listing + every action), and migration injection (in-process
`Artisan::call`, no shell). 30 module tests green after the fix.

### ADR-0032 — Visual theming + layout configurator (Phase 3 B2) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context:** B2 extends the shipped theme system (A4 filesystem child themes + ADR-0029 DB style themes) with
the two pieces ADR-0009 anticipated but hadn't built: a **theme-API contract surface** and a **region/layout
configurator**.

**Decision:**
- **Theme-API contract** = `App\Theme\ThemeApi` — a semver'd VERSION plus two stable surfaces: the **token
  contract** (the CSS custom properties a theme/widget may rely on/override — semantic aliases + the AA-derived
  AccentPalette set, AA-safe in both colour modes) and the **named regions**. Versioning mirrors the module
  API: add a token/region = minor; rename/remove = major.
- **Layout configurator** = a **widget** system on B1's extension stance. `App\Theme\WidgetRegistry` holds
  built-in widgets (an admin **HTML/text block** and a **board-statistics** card) and is module-extensible
  (a module registers widgets the same way it registers slots). `App\Theme\LayoutManager` is the single
  audited writer of `layout_widgets` placements (region + widget + position + settings + enabled) and the
  renderer the `<x-region name="…">` outlet calls. Two regions ship (`forum_top`, `forum_bottom`, on the
  forum index). An admins-only (`admin.access` + staff-2FA) ACP page adds/reorders/toggles/edits/removes
  widgets.

**Security / non-obvious calls:**
- **Widget settings are constrained to the widget's DECLARED fields on write** (`updateSettings` drops unknown
  keys) — a placement can never carry arbitrary settings.
- **The one untrusted-input path (the HTML-block widget's admin HTML) is sanitised** through the same
  post-HTML allowlist as user content; built-in widgets escape every dynamic value (`e()`), so `<x-region>`'s
  `{!! !!}` only ever emits trusted, code-authored output. Module-contributed widgets follow the same
  full-trust-but-document stance as B1 slots.
- **Region keys use `_` not `.`** so they bind as flat Livewire property keys (a `.` would be read as nested
  path and break the add-widget select). The stats widget caches its three COUNTs for a minute (off the
  forum-index hot path). Placements whose widget is no longer registered (a module was disabled) render
  nothing, never erroring.

**Consequences:** admins get point-and-click content regions with zero new dependencies; the theme-API token
list is now a documented, versioned contract themes can target; the widget seam gives modules a second UI
extension point beyond slots. New reversible table `layout_widgets`. Tests pin registry/render, the settings
constraint, sanitisation of admin HTML, reorder, the on-page region, the token contract, and ACP authz.

### ADR-0033 — REST API + outbound webhooks (Phase 3 B3) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context:** Phase 3 promised a versioned public REST API and outbound webhooks (ADR-0008 §2.5). Both are
untrusted-input boundaries (token auth; an admin-supplied egress URL), so the build is conservative.

**Decision — REST API (`/api/v1`):**
- **Hashed personal tokens** (`api_tokens`), no Sanctum dependency: the one-time plaintext (`nvf_…`) is shown
  once and stored only as a sha256 hash; `ApiTokenService::resolve` looks it up by hash, rejecting an expired
  token or an inactive owner. An account-settings SFC issues/revokes the user's OWN tokens.
- **`AuthenticateApiToken` sets the resolved user as the request user**, so every endpoint authorizes through
  the EXISTING permission engine (`canDo` / PostService) — the API can never exceed the user's web rights. A
  bad/expired/inactive token is a clean JSON 401.
- Endpoints: `/me`, `/forums` (filtered by `forum.view`), `/forums/{forum}/topics`, `/topics/{topic}` (paginated
  posts), `POST /topics/{topic}/posts` (`post.create`, via PostService). Responses explicitly shaped; collections
  paginated; `throttle:api` = 60/min keyed by user-or-IP (throttle runs ahead of token auth so floods are
  IP-bounded before the lookup).

**Decision — outbound webhooks:**
- `webhook_endpoints` (URL + per-endpoint signing secret, **encrypted at rest** + subscribed events) and
  `webhook_deliveries` (the queue). `WebhookEventSubscriber` bridges the core domain events (post/topic created,
  followed, reputation awarded, message sent — **IDs only, never bodies/PII**) to the `WebhookDispatcher`, which
  only INSERTS pending deliveries on the action's path (never an HTTP call) and swallows any error, so a webhook
  can never break the triggering action.
- `WebhookDeliveryRunner` is the **cron egress** (`webhooks:deliver` every minute, overlap-guarded, skipped
  during a restore): it signs each due delivery and POSTs with a short timeout; 2xx → delivered, else an
  exponential-backoff retry, failing after `max_attempts`. **Delivery thus degrades gracefully on the baseline
  (cron) tier** — no persistent worker required.
- **HMAC signing reuses the inbound verifier's scheme** — `HMAC-SHA256("{timestamp}.{body}", secret)` with
  `X-NovFora-Signature` + `X-NovFora-Timestamp` headers — so a receiver verifies identically (and can reject
  replays). **SSRF guard** (apex): `WebhookManager::assertSafeUrl` allows only http(s) and refuses loopback /
  private / link-local / reserved hosts (literal-IP `FILTER_FLAG_NO_PRIV_RANGE|NO_RES_RANGE` + obvious internal
  suffixes); a `novfora.webhooks.allow_private` config opens it for local dev only. DNS-rebinding is documented
  out of scope. The ACP page is admins-only (`admin.access` + 2FA), audited.

**Consequences:** integrators get a token-scoped API that can't exceed the user's rights and no-code event
delivery that works on a shared host; zero new runtime dependencies. New reversible tables `api_tokens`,
`webhook_endpoints`, `webhook_deliveries`. **Flagged for review:** the SSRF guard is literal-host/IP-based
(no DNS resolution → a hostname that resolves to a private IP isn't caught; admin-trust + documented); the API
surface is deliberately small (read core + reply) and grows per the same versioned contract. 23 tests across
the API (auth/401, engine-denied read+write, pagination, own-token revoke) and webhooks (SSRF refusal,
subscribe filter, verifiable HMAC, retry/backoff, ACP authz).

### ADR-0034 — Importers (Phase 3 B4) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context:** ADR-0013 specified resumable, verifying, SEO-preserving importers as Phase 3's answer to the
incumbents' worst migration failures. B4 builds the architecture + one importer fully.

**Decision:**
- **Clean-room, driver-based.** A `SourceDriver` (interface) reads a legacy forum's DB **read-only** and maps
  rows to a NovFora-shaped, source-agnostic vocabulary; the `ImportRunner` is identical across drivers. A
  driver encodes only the reference forum's **public DB schema** to copy DATA — never its code or templates
  (the strict clean-room rule applies even to SMF, whose BSD licence would permit code reuse).
- **phpBB built + tested**; **MyBB and SMF scaffolded** behind the same contract (schema mapped, marked
  unverified-against-a-live-board). Build at least one fully — done.
- **Idempotent + resumable.** Every created entity is recorded in `import_maps` keyed `(source, kind,
  source_id)` UNIQUE; a re-run skips what exists and resumes from the last id (keyset cursor) — a
  multi-million-row import survives interruption and fits cron windows.
- **Three stages:** preflight (counts + plan, read-only; aborts on an unreachable source), import (batched),
  verify (count reconciliation per kind).
- **Imports go through the Eloquent models, NOT the post/topic services**, so a bulk import fires NO domain
  events — no webhook storm, no activity-feed flood, no reputation awards.
- **SEO:** legacy URL patterns (phpBB `viewtopic.php?t=` / `viewforum.php?f=`) become 301 `redirects`, served
  by a **route FALLBACK** (`LegacyRedirectController`) so the table is consulted only for an otherwise-
  unmatched URL — never the hot path.
- **Content:** `BbcodeConverter` (clean-room) maps BBCode → canonical markdown (strips phpBB's per-post
  `bbcode_uid`); the post then renders + sanitises through the normal pipeline. Bots (phpBB `user_type=2`)
  are excluded; the forum hierarchy and author/forum mappings are preserved.

**Non-obvious calls / flagged:** **Passwords** — the legacy hash is stored via the `hashed` cast, which
PRESERVES a valid bcrypt (`$2y$`) hash (Laravel verifies it + auto-rehashes to argon2id on first login) and
re-hashes anything else; a legacy phpass/SHA hash that can't be verified simply fails the check, so that user
resets (no forced reset for modern hashes). Usernames/emails are **deduped** (a colliding/empty value gets a
source-id suffix or an `@imported.invalid` placeholder). The MyBB/SMF drivers force a reset (their hash schemes
aren't Laravel-verifiable). **Verify is count-reconciliation**, not per-attachment (attachment import is a
documented follow-up). New reversible tables `import_maps`, `redirects`. 3 phpBB tests (against a fake legacy
sqlite DB): BBCode conversion, the full import (bots/hierarchy/content/redirect served by the fallback), and
idempotent re-run + resume.

### ADR-0035 — Admin analytics (Phase 3 B5) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context:** Phase 3 promised admin analytics with a privacy-conscious posture and baseline (cron) computation.

**Decision:**
- **Privacy-conscious by construction.** `daily_metrics` holds only AGGREGATE counts per day — there is NO
  per-user tracking, no IP logging, no PII. The metric set is a fixed, closed schema
  (`AnalyticsService::METRICS`), never derived from input.
- **Baseline-safe computation.** `AnalyticsService::rollup($date)` computes the day's figures and upserts them;
  `novfora:analytics:rollup` (daily cron, overlap-guarded, restore-skipped) finalises yesterday + refreshes
  today. Idempotent via `UNIQUE(metric_date, metric_key)`, so the cron, a manual run, and `--backfill` are all
  safe. Totals are computed AS-OF the end of the day, so a backfilled timeseries is historically correct, not
  just a snapshot of "now".
- **Admins-only dashboard** (`admin.access` + staff-2FA) — live headline totals (cheap counts) plus a
  recent-days table from the rollup; a "Refresh today" action re-rolls on demand.

**Non-obvious call (and bug fixed in build):** `daily_metrics.metric_date` is kept as a plain `Y-m-d` STRING
(no `date` cast). The Eloquent `date` cast reformatted it to a datetime, which broke both exact-date lookups
(`where('metric_date', toDateString())`) AND the `updateOrCreate` idempotency match — the string form stores +
compares identically across drivers.

**Consequences:** operators get growth/engagement figures with zero PII and zero new dependencies; the metric
set extends by adding a key to the closed list + a count in `rollup`. New reversible table `daily_metrics`. 3
tests: the rollup values + idempotency, the cron command, and dashboard authz + aggregate display.

---

### Phase 3 — status (2026-06-13)
**All five subsystems built, tested, and committed** on `claude/phase-3-extensibility` (pending owner push →
PR → merge): B1 modules (ADR-0031), B2 theming/layout (ADR-0032), B3 REST API + webhooks (ADR-0033), B4
importers (ADR-0034, phpBB built + MyBB/SMF scaffolded), B5 analytics (ADR-0035). Design set:
`docs/architecture/phase3-extensibility/`. Each ADR is **Accepted — owner-authorized overnight build; flagged
for review** and should get a human review pass before the 1.0 line.

---

## Phase 3 — Hardening pass (2026-06-13, owner-authorized)

> A focused pass to PROVE and HARDEN Phase 3 before more is built on it: close every "flagged for review"
> follow-up from ADR-0031…0035, run an adversarial review over the extensibility surface, expand coverage, and
> dogfood the semver'd contract. No new phase. Each item is its own gated, committed unit. Gates (PHP 8.3-line
> baseline, run on PHP 8.5 here): `php artisan migrate` · `pest` (single-process) · `pint` · `phpstan`.

### H1 — Webhook SSRF / DNS-rebinding hardening (APEX, closes the ADR-0033 flag)

**Flag closed:** ADR-0033 noted the webhook SSRF guard was "literal-host/IP-based (no DNS resolution → a
hostname that resolves to a private IP isn't caught); DNS-rebinding out of scope." That gap is now closed.

**Decision:** introduce `App\Webhooks\WebhookUrlGuard` and route delivery through it, with the dangerous range
logic shared via a new `App\Support\Ssrf` kernel (one source of truth across BOTH egress surfaces — webhooks
and the pre-existing oEmbed fetcher):
- `App\Support\Ssrf\IpClassifier::isBlocked($ip)` — the SSRF deny-list (private / loopback / link-local /
  reserved / CGNAT 100.64/10 / cloud-metadata 169.254.169.254 / IPv6 ULA fc00::/7 / link-local fe80::/10 /
  IPv4-mapped / unspecified / 6to4 2002::/16 / NAT64 64:ff9b::/96). Lifted verbatim from the proven oEmbed guard.
- `App\Support\Ssrf\UrlSafety` — shared redirect/resolve helpers (`locationIsUnsafe` CRLF/empty check,
  `absolutize`, `resolvePins` CURLOPT_RESOLVE pin builder, `systemResolve` A+AAAA).
- `App\Content\Oembed\SsrfGuard` now DELEGATES `isBlockedIp` + the helpers to the shared kernel (behaviour
  identical — its permanent SSRF battery, incl. the IPv6-transition bypass cases and the `locationIsUnsafe`
  reflection test, stays green), so the two guards can never drift.

**Two-layer model.** (1) Create/update time (`assertConfigUrl`): a cheap http(s) + literal-IP + internal-
hostname check, NO DNS — deliberately, so a public hostname (e.g. a `.test` host in CI, or any host whose A
records change) is accepted and the authoritative check is deferred. (2) **Delivery time** (`deliver`, the
authoritative boundary, in `WebhookDeliveryRunner`): resolve every record, refuse if ANY is blocked, pin the
connection to a validated IP (closing the resolve-vs-connect rebinding gap), and re-validate every redirect
hop. An SSRF block raises `App\Support\Ssrf\SsrfException`, caught by the runner → a scheduled retry (uniform
with any other delivery failure), and nothing is sent. `novfora.webhooks.allow_private` still opens it for
local dev only.

**Non-obvious calls.** Config-time stays DNS-free on purpose (resolving at save time would both break on
non-resolving test hosts and give a false sense of safety, since DNS can change before delivery — which is
exactly rebinding). The resolver is injectable so the rebinding simulation + metadata-endpoint attempt are
deterministic without real DNS. Following redirects (re-validating each hop) is kept rather than refused, to
satisfy the "re-validate every hop" requirement; the payload is IDs-only so a redirected delivery leaks nothing
sensitive even before the re-validation refuses an internal hop.

**Tests (`tests/Feature/Webhooks/WebhookSsrfTest.php`, PERMANENT):** config-time refusals + public acceptance;
delivery-time rebinding (public-at-save → private-at-delivery → refused, nothing sent); cloud-metadata attempt;
mixed-records (any-blocked) refusal; fail-closed on no-resolve; redirect-to-internal re-validation; missing/
unsafe Location; happy-path delivery; the `allow_private` dev escape; and an end-to-end runner test (a rebound
host → scheduled retry, `last_error` records the block, nothing sent). The existing `WebhookTest` delivery
cases now bind a deterministic public-IP resolver (delivery is SSRF-guarded). oEmbed suite unchanged + green.

### H2a — Importer driver verification + hierarchy/title fidelity (closes part of the ADR-0034 flag)

**Flag (partial close):** ADR-0034 shipped phpBB "built + tested" but MyBB + SMF as "scaffolds — schema mapped,
unverified against a live board". H2a promotes both to VERIFIED against representative fixtures, and fixes two
fidelity bugs the fixtures exposed.

**Decision:**
- **Representative fixtures + full-import tests for MyBB and SMF** (`tests/Feature/Import/MybbImportTest.php`,
  `SmfImportTest.php`), mirroring the phpBB battery: a fake legacy DB (a second sqlite connection with the
  reference `mybb_*` / `smf_*` schema), then asserting preflight counts, user import, forum hierarchy,
  category-vs-forum typing, BBCode→markdown→HTML content, 301 redirect maps served by the fallback, and
  idempotent re-run + resume. A driver is marked "verified" only because it passes this suite.
- **Order-independent forum import (fidelity fix).** `ImportRunner::importForums` previously relied on the
  driver yielding parents before children (true for phpBB's nested-set `left_id`, but NOT for MyBB `disporder`
  / SMF `board_order`, which are display order). It now does a topological multi-pass: import any forum whose
  parent is a root or already mapped, repeat until no progress, then create any remaining (missing/cyclic
  parent) as roots so none is dropped. The MyBB + SMF fixtures deliberately store the child board BEFORE its
  parent to pin this.
- **SMF title-from-first-message (fidelity fix).** SMF keeps no title on the topic row — it lives on the first
  message (`id_first_msg`). `SmfDriver::topics()` now LEFT JOINs `smf_messages` to carry the real subject +
  creation time instead of a synthetic placeholder; the SMF test asserts the imported topic title.

Clean-room throughout (only public table/column names are encoded; no reference-forum code/templates — including
SMF's, whose BSD licence would permit it). Hash posture unchanged (MyBB salted-double-md5 / SMF SHA-1 aren't
Laravel-verifiable → those users reset on first login; the tests assert the legacy hash is not retained as-is).

### H2b — Attachment import + content/checksum verification (closes the ADR-0034 attachment flag)

**Flag closed:** ADR-0034 noted "Verify is count-reconciliation, not per-attachment (attachment import is a
documented follow-up)." Now done.

**Decision:**
- **Attachment import.** A new ADDITIVE optional capability `App\Import\Contracts\ProvidesAttachments` (kept
  out of `SourceDriver` so it is semver-safe — a driver without attachments simply doesn't implement it and the
  runner skips the stage). All three drivers implement it (phpBB `phpbb_attachments`, MyBB `mybb_attachments`,
  SMF `smf_attachments` with its `{id_attach}_{file_hash}` physical name), resolving each legacy file from a
  configured base dir. `ImportRunner::importAttachments` reads the bytes, stores them on the app `local` disk,
  records a sha-256 `checksum` (the SAME column the native `AttachmentService` writes — the migration always
  intended it "for importer verification"), links them to the imported post, and is idempotent/resumable via an
  `import_maps` row of kind `attachment`.
- **Content + checksum verification (replaces count-only).** `ImportRunner::verify` now additionally returns a
  `content` block (a sample of imported post bodies compared to the source-derived canonical) and, when the
  driver provides attachments, an `attachments` block that RE-HASHES every imported file against its recorded
  checksum. "Complete" now means the data arrived intact, not just that row counts line up.

**Fidelity bug fixed (found by the gate).** `importPosts` stored `body_canonical` as a `json_encode(...)`
STRING, but the `Post` model casts that column to `array` (the lossless source, like native posts) — so the
array cast DOUBLE-encoded it, and an imported post read its canonical back as a string. It now stores the array
(matching `PostService`), so an imported post is correctly editable/diffable. The new content-verify assertion
pins this.

**Tests:** each driver's import test gained an attachment case (a real temp file → driver reads it → stored on a
faked disk → checksum matches the source sha-256 → `verify()['attachments']['checksum_ok']` + `['content']['ok']`
true). 10 importer tests green. The attachment base dir is injected, so the tests are self-contained.
