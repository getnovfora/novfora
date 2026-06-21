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

### H3 — Plugin trust guardrails (APEX, closes the ADR-0031 full-trust flag)

**Flag addressed:** ADR-0031 flagged "the full-trust execution model (documented, unavoidable for PHP)". A real
PHP sandbox remains **out of scope** (unchanged — no half-sandbox was built, per the run's instruction). Instead
H3 adds the explicit, audited guardrails AROUND the full-trust fact so an operator enables code knowingly and a
bad module can't take the site down.

**Decision (four guardrails, new migration adds `consented_at`/`package_hash`/`failed_at`/`last_error`):**
- **Full-trust consent gate.** `ModuleManager::enable($slug, $acknowledgeTrust=false)` refuses (with a clear
  message) until an admin explicitly acknowledges that the module runs with full server trust. Consent is
  recorded once (`consented_at`) and carries across a later disable/enable. The ACP enable button now opens a
  consent panel that names the module's **declared capabilities** (the manifest `provides`) before confirming.
- **Package integrity check.** Install/enable/upgrade record a `package_hash` — sha-256 over a path-sorted
  serialisation of `module.json` + every PHP file under `src/` + `database/migrations/`. `integrityStatus()`
  re-hashes on demand and the ACP shows `verified` / `modified` (`modified` = the on-disk files changed since
  the admin blessed them — tamper / accidental-edit detection). *(A full asymmetric PACKAGE SIGNATURE — detached
  sig + a configured trusted key — is a documented future enhancement; for a local-install model with no remote
  fetch/marketplace, tamper-detection-vs-blessed-state is the high-value piece and signing is lower-value until
  there is a distribution channel. Flagged, not built — no half-measure.)*
- **Disable-on-fatal quarantine.** `ModuleLoader` wraps each module's provider registration in try/catch; a
  Throwable → `ModuleManager::quarantine()` disables the module + records `failed_at`/`last_error` + audits
  `module.quarantined`, so a crashing module is skipped next boot instead of white-screening the site. (A hard
  parse error in a module file is uncatchable here — that is what the kill switch is for.)
- **Kill switch.** A file-based safe-mode marker (`novfora.modules.safe_mode_marker`, default
  `storage_path('modules-safe-mode')`); while it exists `ModuleLoader` loads NO modules. File-based (not a DB
  flag) so it works before the DB is reachable and survives a module that breaks boot — an operator can drop it
  over FTP/cPanel. An admins-only ACP toggle writes/removes it.

**Non-obvious calls.** The consent gate sits AFTER the compat/dependency checks (fail fast on a module that
can't be enabled anyway) but BEFORE permissions/migrations run (no side effects without consent). The adversarial
lifecycle tests pass `acknowledgeTrust: true` so they still exercise their target guard (dependency / core-key
collision). Quarantine swallows its own errors so the safety net can never itself fatal a request.

**Tests (`tests/Feature/Modules/ModuleTrustTest.php`):** consent refused-then-granted + recorded-once; integrity
verified→modified on a tampered file (isolated temp module — parallel-safe); a faulty fixture provider that
throws is quarantined (disabled + `last_error` + audit); the kill switch loads nothing even with a module
enabled (marker isolated to a temp path). 34 Modules tests green.

### H4 — Remaining flagged Phase-3 follow-ups (closed or scope-fenced)

**Closed — module migration rollback batch-semantics (ADR-0031 flag).** ADR-0031 flagged that module migration
rollback used `migrate:rollback --path` "(fine for the typical one-batch module; revisit if a module ships many
migration batches)". `ModuleManager::rollbackMigrations` now uses **`migrate:reset --path`**, which reverses ALL
of a module's migrations regardless of how many batches they ran across (initial enable + later upgrades), so
`remove()` never strands a table from an earlier batch. Pinned by a new `ModuleLifecycleTest` case that runs a
migration on enable (batch 1) + a second on upgrade (batch 2) and asserts BOTH are dropped on remove.

**Scope-fenced (intentional future ENHANCEMENTS, not gaps — left as documented follow-ups):**
- ADR-0034 / importers.md §5: richer BBCode coverage (tables, nested quotes), oEmbed re-resolution, and
  verifying MyBB/SMF against a LIVE board (they are verified here against representative fixtures incl.
  attachments + idempotency/resume — a live board may surface schema-version quirks; phpBB is the high-confidence
  path). These extend fidelity but are not correctness gaps in the shipped surface.
- ADR-0035 / analytics.md §5: per-forum/per-category breakdowns + dashboard charting/export. Additive metric-set
  growth per the documented contract; the aggregate-only privacy posture is unchanged.
- ADR-0033: the REST API surface is deliberately small (read core + reply) and grows under the same versioned
  contract — by design, not a gap.
- ADR-0031: a full asymmetric PACKAGE SIGNATURE and a real PHP SANDBOX remain out of scope (see H3) — the
  integrity hash + consent + disable-on-fatal + kill switch are the honest mitigations for a full-trust,
  local-install model. No half-measures were built.

## Phase 3 — Adversarial review (P1, 2026-06-13, APEX)

> A fresh skeptic pass (verify-then-refute) over the whole extensibility surface, on top of the overnight
> build's per-subsystem review. Each vector below is recorded with its verdict; the one MEDIUM is fixed.

**Plugin lifecycle & path handling — REFUTED (already hardened).** `dirFor`/`srcPath`/`migrationsPath`/
`manifestFor`/`packageHash` all route the slug through `ManifestValidator::assertSlug` (the chokepoint the
overnight build added after the HIGH traversal fix), pinned by the traversal-refusal test. `discover()` skips
invalid manifests and the manifest-slug-vs-directory cross-check bounds it. A symlink planted in `modules/` is
an admin/full-trust act and the slug cross-check still applies. No new issue.

**Manifest parsing — REFUTED.** `ManifestValidator` is fail-closed: bounded JSON depth (64), strict slug /
namespace (reserved-root refusal) / provider-in-namespace / semver / permission-key regex + scope-kind, bounded
strings, dup-key refusal. A pathologically large permissions array is admin-trust (local file), bounded only by
good sense — noted, not a vuln. Fuzzed in P2.

**Hook / event / filter payloads — MEDIUM, FIXED.** The sanitisation contract held (slot output + `post.html`
filter output are both re-sanitised — verified at `ContentRenderer:49-51` and `SlotRegistry::render`), and a
filter is value-transform-only (cannot widen a permission decision). **But** a filter callback or slot renderer
that THREW was not isolated — a single faulty (full-trust) module could 500 every post render / break an outlet.
**Fix:** `HookRegistry::applyFilters` and `SlotRegistry::render` now catch a throwing callback, `report()` it,
and skip it (the running value / the other renderers survive) — the per-call complement to H3's load-time
disable-on-fatal. Pinned by two new `HookSlotTest` cases.

**REST authz (bypass hunt) — REFUTED.** Every `/api/v1` endpoint resolves the token→user and authorizes via
`$user->canDo(...)` on the resource's `permissionScope()` through the SAME `PermissionResolver` as the web UI;
writes go through the SAME `PostService` (trust gating / sanitisation / approval) — there is NO second code path
to bypass. Posts are filtered to `approved`. A global NEVER on `forum.view` / `post.create` denies the read
filter, the per-forum topics read, AND the write (tests). Route-model binding excludes soft-deleted rows.

**API token handling + rate limits — REFUTED.** Tokens are sha256-hashed (indexed lookup, no plaintext stored/
logged), reject expired + non-active-owner; the plaintext is shown once. `throttle:api` (60/min) runs AHEAD of
token auth (route middleware order) so floods are IP-bounded before the lookup — keying by IP pre-auth is the
deliberate trade (per-user keying would need auth-before-throttle, exposing the lookup to floods).

**Webhook HMAC + the new SSRF guard — REFUTED (H1).** HMAC-SHA256 over `{ts}.{body}`; the per-endpoint secret
is encrypted at rest; payloads are IDs-only. The H1 guard resolves+classifies+pins+re-validates each redirect
hop at delivery (the authoritative boundary), shared deny-list with oEmbed. `allow_private` is documented
dev-only.

**Importer parsing of untrusted dumps — REFUTED.** Drivers read the legacy DB via the parameterised query
builder (no SQLi from dump data); legacy content flows through `BbcodeConverter` then the post pipeline's
`ContentSanitizer` (a `<script>` in a legacy body is stripped); usernames/emails are deduped + length-bounded
and rendered escaped. The `--prefix` / connection are operator-supplied (CLI), not untrusted-dump input. The
manifest + BBCode parsers are fuzzed in P2.

**Net:** 1 MEDIUM (filter/slot exception isolation) found + fixed; all other vectors verified-safe. No HIGH.

### P2 — Coverage expansion + fuzz/property tests (2026-06-13)

The real user flows now have automated feature coverage end-to-end: plugin install→enable→exercise→disable→
remove + upgrade (`ModuleLifecycleTest`, incl. the H3 consent path + the H4 multi-batch rollback); theme + layout
(`AuroraThemeTest`, `LayoutAdminTest`, `LayoutWidgetTest`, and D2's first-party theme); API token create / ROTATE
/ revoke (`ApiTokenManagementTest` — rotation added here); webhook register + signed delivery (`WebhookTest` +
`WebhookSsrfTest`); and an import run end-to-end for all three drivers incl. attachments (`Phpbb/Mybb/SmfImportTest`).

**Fuzz/property tests for the untrusted-input PARSERS (new):**
- `ManifestFuzzTest` — a fixed adversarial corpus + a seeded random pile of objects (≈400 cases) assert
  `ManifestValidator` is TOTAL + fail-closed: every input yields a `ModuleManifest` (with a path-safe slug) or a
  `ModuleException`, and NEVER any other Throwable. (The fuzz harness itself caught a bug — in the test, not the
  validator — proving the loop reaches the parser.)
- `BbcodeFuzzTest` — ≈600 seeded random BBCode token streams assert `BbcodeConverter` never throws, leaks no
  KNOWN bracket tag, and does not catastrophically backtrack on a 2000-deep nest.

**Dusk:** intentionally NOT added. The flows above are server-rendered Livewire components fully exercised by
`Livewire::test` (the consent panel, layout configurator, token settings) — there is no new browser-only/JS
behaviour a real browser would catch that the feature tests don't, and the sandbox has no browser driver. Dusk
stays reserved for genuinely JS-driven flows.

## Phase 3 — Dogfood (D1/D2/D3, 2026-06-13)

> The real payoff: build first-party extensions PURELY through the public contract (zero core edits) to surface
> contract gaps before third parties hit them. Every gap found was closed ADDITIVELY (semver-aware), bumping the
> Module API minor to **1.1.0**.

### D1 — First-party plugins (`modules/novfora/qa`, `modules/novfora/kudos`)

Two plugins, each exercising EVERY module seam through the contract (a domain-event listener, a `post.html`
filter, a UI slot, a plugin-owned migration, a plugin setting, a permission, routes) + Kudos also a
module-registered layout **widget**:
- **novfora/qa** — mark one reply as a topic's accepted answer (permission `novfora.qa.accept`, table
  `qa_accepted_answers`, the `topic.post.aside` badge slot, an `[answer]` content callout filter gated by the
  `qa.callout_enabled` setting, CSRF-guarded confirm+accept routes).
- **novfora/kudos** — give kudos to a post (permission `novfora.kudos.give`, table `kudos`, a footer-slot total,
  a `KudosWidget` layout widget, a `[kudos]` glyph filter using the `kudos.glyph` setting).

**CONTRACT GAPS found by dogfooding, and how each was closed (all additive → Module API 1.1.0):**
1. **No per-post UI extension point.** The only slots placed in core templates were `footer.widgets` + the forum
   regions — a plugin had nowhere to render per-post UI (an accepted-answer badge). *Closed:* added the
   `topic.post.aside` slot outlet to the topic view, passing the post + topic as context (the `<x-slot-outlet>`
   component already supported `:context`; the value is sanitised). A NEW slot name = minor.
2. **No way for a plugin to register settings.** `SettingsRegistry` was a closed hardcoded list, so the declared
   `provides: ["settings"]` capability had no registration path — `Settings::get/set` returned null/threw for an
   unregistered key. *Closed:* `SettingsRegistry::register(SettingDefinition)` (+ `flushRuntime()` for test
   isolation); module keys fill gaps only — a plugin can NEVER override a core key (e.g. `mail.password`).
3. **`widgets` missing from the manifest capability vocabulary.** Modules can register layout widgets (ADR-0032)
   but `provides: ["widgets"]` was rejected by `ManifestValidator`. *Closed:* added `widgets` to `KNOWN_PROVIDES`.

**Also fixed (robustness, not a gap):** module routes now build URLs with path-based `url()` rather than
`route()`-by-name, so a runtime-registered module route resolves even before the name lookup is rebuilt / under
route:cache. (Recorded as a known consideration: runtime-registered routes are not in a cached route file —
fine on the baseline tier; an enhanced-tier operator using `route:cache` should be aware. Not a correctness gap
in the shipped flow.)

Tests: `QaPluginTest`, `KudosPluginTest` drive each plugin install→enable→exercise-every-seam through the
contract; permission gating is enforced by the core engine (403 without the grant).

### D2 — First-party theme (`themes/nebula`)

A polished filesystem child theme built purely on the theme API (ThemeManager view overrides), zero core edits:
- **Token overrides** — overrides the documented `ThemeApi::tokens()` contract via the `theme-head` seam: a
  distinct AA-safe violet accent (derived by `AccentPalette`, so light/dark inks meet WCAG AA) plus the semantic
  aliases `--novfora-accent` / `--novfora-radius`.
- **Branding** — a `footer-tagline` view override.
- **Coexistence** — the test proves that with Nebula active, a configured layout REGION (`<x-region>` widget)
  and a module SLOT both still render (and slots are still sanitised) — a theme is presentation-only.

**No new contract gaps:** the theme API (token contract + view-override seams + region/slot coexistence) was
already sufficient for a polished child theme. `ThemeApi::VERSION` stays **1.0.0** (the new per-post slot is a
SlotRegistry/Module-API addition, not a theme region). Tests: `NebulaThemeTest` (activation, token-contract
override, branding, layout/slot coexistence, no-op when inactive). 32 Theme tests green.

---

## Phase 3 — ADR human review pass + beta release build (2026-06-13)

### Human review pass (closes the "flagged for review" note on ADR-0031…0035)
Before building the live-deploy bundle, ADR-0031…0035 were read against the locked decisions (CLAUDE.md) and
the hard rules. **Outcome: consistent — no concern to flag, no decision relitigated.** Confirmed:
- **No second permission system / no escalation.** Module permission keys only ADD to the catalog and resolve
  through the existing `PermissionResolver`; a module can never redefine a core key or write `acl_entries`
  (ADR-0031). The REST API authorizes every call through the same engine and `PostService` — it can't exceed the
  token owner's web rights (ADR-0033, re-verified in the P1 bypass hunt).
- **No unsanitised HTML.** Slot / `post.html` filter / widget output is re-sanitised through the same
  `ContentSanitizer` allowlist as user content; a throwing full-trust callback is now isolated (P1 MEDIUM fix).
- **Untrusted-input boundaries fail closed.** Manifest parsing, webhook egress (H1 DNS-rebinding guard), and
  importer dump parsing are all validated/parameterised/fail-closed (P1 verified-safe; P2 fuzzed).
- **Strict clean-room** holds for the importers, including SMF (data-only schema mapping, never the program).
- **Honestly-documented residuals** (full-PHP-trust plugin model — no feasible PHP sandbox; deliberately small
  REST surface) are accepted, not bugs. The full-trust model is now gated by H3's consent + integrity + kill
  switch. The ADR `Status` lines are left as the historical record; this note is the recorded human pass.

### Beta release build (`novfora-release.zip`)
Built the portable, tier-adaptive in-place upgrade bundle from `main` HEAD (Phase 3 + hardening, gate green:
**Pest 1116 passed / 1 skipped**, pint + phpstan-L5 clean). The bundle carries Phase 3 (`/api/v1`, module/theme
registries, phpBB/MyBB/SMF importers, analytics rollup, the H1 webhook SSRF guard) and **60 migrations (10
Phase-3/Stage-A)** — so `SchemaState::codeFingerprint()` advances and a live `v1.0.0-beta.1` host auto-detects
`schema.pending = true` (RH-10). Verified by a truly-cold HTTP boot (no artisan first): `GET /` → **302 /install**,
`/install` → **200**; `bootstrap/cache/packages.php` ships (RH-1) and no env-specific cache/secret/install marker
does. Artifact is gitignored, not committed.

**`scripts/build-release.sh` fixes (this commit set):** (1) a `SKIP_NPM=1` path so the bundle can build in the
node-less `forum-app` container after assets are built on the host (Docker php:8.3 + host Node); (2) the
invariant-#4 `php artisan optimize:clear` now runs **before** `package:discover`, because in Laravel 13
`optimize:clear`'s `clear-compiled` step also deletes `bootstrap/cache/packages.php` — so discovery must be the
LAST cache writer or the RH-1 manifest wouldn't ship.

---

## Mega-build — scoped, Phase-4-independent waves (2026-06-13, owner-authorized overnight build)

> The owner authorized a long unattended build of the "mega-build" feature set, but **Option 2 only**: run
> the waves that do **not** depend on Phase 4 (Clubs, PWA/push, SSO/OAuth2/SAML social login, paid-membership
> scaffold), because Phase 4 was confirmed **never built** (not in `main`, not on any branch — see ROADMAP §4).
> Branch `claude/mega-build` off `main` (Phase 3 + hardening). Each unit is its own gated, DCO-signed,
> conventional commit. Gates: `php artisan migrate` · `pest` · `pint` · `phpstan` (PHP-8.3 baseline, run in the
> `forum-dev` container). Every ADR here is **Accepted — owner-authorized overnight build; flagged for review**.
>
> **EXPLICITLY DEFERRED pending Phase 4 (NOT built, NOT stubbed under another name):** Theme-Studio 1.4
> per-forum/club assignment hook · SAML (5.3) · Meilisearch (6.2) · Reverb (6.3) · monetization (Wave 7).
> These stay out until Phase 4 lands so this work cannot collide with the real Phase-4 design.
>
> **⚠ CONCURRENT-SESSION ANOMALY (observed 2026-06-14, owner: please reconcile).** Partway through the run, ~49
> files appeared MODIFIED in the working tree that this build never touched — `if (! Schema::hasTable())`
> idempotency guards across ~48 migrations, an `UpgradeCommand` restore-path hint fix, and a new untracked
> `docs/product/rh4-subdirectory-install-spike.md` (RH-4 subdirectory install — a design-first item). This is a
> coherent OTHER workstream (a concurrent session on the same working tree, per the standing "watch for
> concurrent sessions" caution). The mega-build commits on `claude/mega-build` are CLEAN — every commit staged
> explicit paths, so none of these foreign changes are included — and the gate was green WITH them present. I
> left them untouched (reverting could destroy that session's uncommitted work; committing would mix it in).
> Owner: commit/stash that work from its own session; `git diff` shows exactly what it is.

### ADR-0036 — `permissions:sync`: additive re-provisioning of role presets on upgrade (Wave 0.1) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.** (APEX — `acl_entries` / preset
expansion / wired into the upgrade-concurrency path.)

**Context — the live "Badges 403" class.** On the no-SSH baseline upgrade (RH-10), `UpgradeRunner` applies
migrations but does **not** run seeders. So when a release ADDS a permission key to a preset (e.g.
`badge.manage` joined the `administrator` preset), an already-installed site keeps its pre-release
`role_permissions` + `acl_entries`, and the admin gets a 403 on the new screen (the Badges ACP checks
`badge.manage`). Nothing re-derived presets at upgrade time.

**Decision.** New `App\Permissions\PermissionSync` service + `novfora:permissions:sync` command (with
`--dry-run`), wired into `UpgradeRunner::execute()` after a successful `migrate` and before the cache refresh.
It re-derives the built-in presets (reusing `RoleSeeder::presets()`/`groupAssignments()` and
`PermissionCatalogSeeder::catalog()` as the single source of truth) onto existing roles + system groups.

**Semantics — ADDITIVE ONLY (the load-bearing call).** The service only ever INSERTS what is missing:
- catalog → upsert the reference `permissions` table (code-owned docs; refreshing labels is safe);
- preset → `role_permissions`: add a preset key only if ABSENT from the role; never modify/delete an existing key;
- expansion → `acl_entries`: write a system group's GLOBAL-scope entry only when MISSING; never overwrite a value.

**Why additive, not `RoleExpander::reexpand()` (deliberate deviation from the brief's "via RoleExpander").**
`reexpand()` is a blunt `updateOrCreate` that would overwrite an admin-customised global value on a system
group — **re-granting a permission an admin deliberately revoked is a security regression.** Additive
provisioning fixes the 403, heals partial states (a `role_permission` present but its `acl_entry` lost), is a
**true no-op** on a healthy install (no writes → no `AclVersion` bump → no cache churn), and preserves every
admin customisation (a NEVER/NO on a system group, per-forum overrides, custom roles). **Known consequence:** a
baseline entry an admin *deleted* is re-provisioned; the documented way to deny permanently is to set the
entry's value to **NEVER** (which add-only preserves), not delete it. Flagged for review.

**Idempotency / concurrency / safety.** A single code path serves both `sync()` and `preview()` (so `--dry-run`
can never disagree with a real run). `sync()` is wrapped in a DB transaction. Cache invalidation rides the
existing `AclEntry::saved → AclVersion::bump` event (a sync that writes N entries bumps the version; a no-op
bumps nothing). In the upgrade pipeline the call holds the upgrade lock already, and is **best-effort**: a sync
throw is caught, `report()`ed, audited as `upgrade.permissions_sync_failed`, and does **not** fail an
otherwise-good schema upgrade (the migrations already applied) — the success audit records `permissions_synced`.

**Tests (8 unit + 2 upgrade-wiring, apex-level).** Repro+fix of the Badges-403 propagation (incl. the resolver
verdict flipping once the `AclVersion` bump invalidates the cached DENY); true-no-op-with-no-version-bump;
**never-clobber a customised NEVER**; partial-state heal; catalog re-insert; `--dry-run` writes nothing yet
reports the exact plan; the command + idempotent re-run; and in the real upgrade path — a preset key dropped
since seed is restored during an upgrade, and an upgrade still SUCCEEDS when `permissions:sync` throws.

**Operator command (clears a live 403 without a redeploy):** `php artisan novfora:permissions:sync`
(preview with `--dry-run`).

### ADR-0037 — Theme Studio (Wave 1) (2026-06-13)
**Status: Accepted — owner-authorized overnight build; flagged for review.** Extends the DB style themes
(ADR-0029) + the theme-API token contract (ADR-0032). Built unit-by-unit; this entry grows per sub-unit.
**1.4 (per-forum/club assignment) is DEFERRED pending Phase 4** (Clubs) — site-wide assignment only.

**1.1 — Visual token editor (full token set, AA-checked, draft + live preview).**
- **Override the REAL core tokens, not the `--novfora-*` aliases.** Investigation showed `--novfora-bg`
  etc. are *one-way* aliases (`--novfora-bg: var(--surface)`) — Tailwind utilities read `--surface` /
  `--ink` / `--line` / `--radius-md` directly, so overriding the alias is cosmetically inert. The editor
  therefore overrides the real tokens. `ThemeApi::editableTokens()` is the new versioned registry mapping
  each editable token → its real CSS var + built-in default + type; `ThemeApi::VERSION` → **1.1.0** (a token
  addition = MINOR, per the contract's own rule) and `tokens()` now also lists the real core vars.
- **Light-palette override; dark stays tuned.** A theme supplies ONE value per token. Overrides are emitted
  as a plain `:root{…}` block AFTER app.css — so they win in light mode, while the existing
  higher-specificity dark rules (`@media (prefers-color-scheme: dark)`, `:root[data-theme='dark']`) keep the
  hand-tuned dark palette. (Per-token dark customisation is a deferred enhancement.) This mirrors exactly how
  the accent already behaves and needs **no asset rebuild** — everything is runtime-injected into `<head>`.
- **Storage + injection-safe validation.** New nullable `tokens` JSON column on `site_themes`
  (reversible migration). `StyleThemeManager::cleanTokens()` keeps only contract keys and accepts a value
  only if it is a strict `#rrggbb` hex (colour) or `<number><px|rem|em>` length — so a token value can never
  carry a `;`/`}`/`:` that would break out of the emitted declaration. (Admins are trusted — they can already
  write custom CSS — this is cheap defence-in-depth.) `buildCss()` emits `tokenCss()` between the accent block
  and the custom-CSS block.
- **Live AA preview.** `AccentPalette` gains a public `contrastRatio()` (WCAG 2.1) + `passesAA()`; the editor
  computes ink-on-surface / muted-on-surface / ink-on-card ratios server-side (via a component
  `tokenPreview()` method — kept out of the Blade so the compiler doesn't choke on arrow-fn logic) and shows
  a live ✓/✗ badge plus an inline-styled preview card that updates on every keystroke (`wire:model.live`).
- **Tests (8):** the v1.1 contract; the contrast maths (black-on-white = 21, identical = 1) + `passesAA`;
  valid tokens persist / invalid + blank + unknown keys drop; the column clears when nothing is valid; the
  active-theme CSS carries the real-token overrides; `tokenCss` ignores non-contract keys; the editor saves
  through Livewire dropping invalid values; non-admin → 403.

**1.2 — Per-theme custom header / footer HTML (the "wrapper") + custom CSS.**
- **Sanitised at WRITE time through the post allowlist.** New nullable `header_html` / `footer_html` TEXT
  columns on `site_themes` (reversible). `StyleThemeManager::cleanHtml()` runs each through the SAME
  `ContentSanitizer` allowlist as user posts — `<script>` / `<style>` and any non-allowlisted element/attribute
  (e.g. `onclick`) are dropped — so what is stored is already safe and the layout renders it raw (`{!! !!}`).
  Custom CSS already existed (1.0). The "wrapper" is the header band + footer block surrounding the page; a
  true split open/close wrapper is intentionally NOT offered (it can't be sanitised as balanced fragments).
- **Cached chrome, invalidated on write.** `StyleThemeManager::chrome()` returns the active theme's
  `{header, footer}` HTML, cached forever under a second key and forgotten in `invalidate()` (so an edit shows
  at once) — same discipline as the compiled-CSS cache. The layout fetches it once per request and renders a
  header band below the site header and a footer block above the credit line.
- **Tests (5):** script/style/`onclick` stripped at save; null when nothing survives; `chrome()` reflects the
  active theme and clears when none is active / on edit; and a route-integration test that the active theme's
  header & footer HTML render on the forum index (and vanish when deactivated).

**1.3 — Layout configurator everywhere + a fuller widget set.**
- **8 new regions** added to `LayoutManager::REGIONS` (a MINOR theme-API change → `ThemeApi::VERSION`
  **1.2.0**): `board_top/bottom`, `topic_top/bottom`, `profile_top`, `forum_sidebar`, `site_header`,
  `site_footer`. `<x-region>` outlets were added to the board, topic and profile views; site header/footer
  regions render on every page from the layout; the forum-index sidebar uses a conditional 2-column grid that
  **only** appears when filled (the single-column default is byte-identical when empty). Region keys are stored
  in `layout_widgets.region`, so they are stable identifiers — never renamed.
- **Four new first-party widgets** registered in `ThemeServiceProvider`: `recent_topics` (clamped 1–20, links
  to topics), `online_users` (members with `last_active_at` inside a window — BASELINE-SAFE, no WebSocket
  presence; cached a minute), `search` (a GET form to the existing search page), `featured` (admin
  title + HTML body, sanitised through the post allowlist). All escape every dynamic value; the two
  HTML-bearing widgets reuse `ContentSanitizer`.
- **Tests (8):** the expanded region set + `isRegion`; all six widgets registered; each new widget's render
  (escaped recent-topic titles + links; online-window inclusion/exclusion; search form → search route;
  featured sanitisation + empty-hides); and a route-integration test placing a widget in `board_top` and
  seeing it on the board page.

**1.5 — Theme assets (logo / favicon / background bound to the active theme).**
- New nullable `logo_path` / `favicon_path` / `background_path` columns on `site_themes` (reversible).
  `StyleThemeManager::storeAsset()` stores an upload on the **public** disk (web-accessible, the same disk
  avatars use) under `theme-assets/`, replacing + deleting any previous file; `clearAsset()` removes one;
  `delete()` now cleans up all bound files so a deleted theme orphans nothing.
- The active theme's logo + favicon URLs come from a cached `assets()` (same defensive/cached discipline as
  `css()`/`chrome()`); the background is emitted as a `body{background-image:url(...)}` rule inside the
  compiled CSS (the URL is `addcslashes`-escaped into the `url()`). The layout renders the favicon
  `<link rel="icon">` in `<head>`, the logo in the header brand (alt = wordmark, falls back to the wordmark
  text), and the background via the injected CSS.
- The editor gains logo/favicon/background file inputs (Livewire `WithFileUploads`, `image` + size-validated)
  with a current-image preview + Remove button per asset.
- **Tests (8):** store/replace/clear on the public disk; `assets()` URLs + empty state; the background CSS
  rule; asset cleanup on theme delete; a Livewire upload through the editor; and a route-integration test
  that the active theme's favicon + logo render on the page. (Uploads are faked GD-free — `create()` not
  `image()` — since the gate container has no GD.)

### ADR-0038 — Sandboxed template editing (Theme Studio 1.6, APEX, ISOLATED) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; FLAGGED FOR DEDICATED HUMAN SECURITY REVIEW.** Its own
ADR + threat model (`docs/architecture/sandbox-template-threat-model.md`) per the build fence. Committed on its
own. **Truly-safe sandbox was FINISHED this run** (the fence's fallback — ship 1.2 + a PROPOSED ADR — was not
needed); the adversarial battery is green.

**Decision — Option A: a bespoke, restricted template language, NOT Blade/Twig/eval.** A lexer → parser →
evaluator under `app/Theme/Sandbox/`: literal author HTML, `{{ expression }}` output, `{% if/elseif/else %}` /
`{% for … in … %}`, dotted variable paths, the boolean/comparison operators, and a fixed set of **pure**
helpers. A versioned contract (`TemplateContract::VERSION = 1.0.0`) lists the OVERRIDABLE templates (key,
label, exposed variables, shipped default) — `home_welcome` (forum index) + `topic_footer` are wired via a
`<x-sandbox-template name="…">` component; the registry holds many. Admin overrides live in `site_templates`
(reversible); a template renders only once enabled. The in-admin editor (`admin.settings.templates`) has live
validation, a default to **diff against**, and **revert**, gated `admin.access` + staff-2FA.

**The safety model (the load-bearing call):**
- **Data-only context** — `TemplateService` builds the render context from models into PLAIN scalars/arrays;
  `SandboxRenderer::resolvePath()` does ARRAY-KEY access only. No object property/method is reachable even if
  one leaks in. **This is what makes it safe** — there is no expressible path to PHP, a model, or the container.
- **Whitelist-only calls** — `{{ name(args) }}` resolves `name` against `SandboxRenderer::helpers()`; unknown →
  error. No `eval`, no `app()`, no closures.
- **Allowlist tokenizer** — `SandboxExpression` accepts only a tiny char set; `$ ; :: -> [] {}` arithmetic,
  backticks, etc. are hard parse errors.
- **Auto-escaped output** — every `{{ }}` value is `e()`-escaped; no raw construct; data carrying `{{ }}` is
  NOT re-parsed (no double-render).
- **Bounded** — source/nodes/template-depth/expression-depth/iterations/output caps → no hang, OOM, or
  parse-time stack overflow (the expression-depth cap was added during the build's own adversarial pass).
- **Fail-safe** — errors throw `SandboxException`; `render()` catches → `''`; a broken template breaks nothing.
- **Save-lint (defence-in-depth)** — rejects literal `<script>/<style>/<iframe>/handlers/javascript:` + requires
  the source to parse, on top of the output escaping.

**Documented residual (Blade parity):** `e()` is correct for text + QUOTED attributes; a dynamic value placed in
an UNQUOTED attribute / CSS context with user-derived data could inject, exactly as Blade's `{{ }}`. The editor
copy + threat model state "use quoted attributes/text"; a context-aware/structural sanitiser is a future
hardening (needed before exposing the engine to non-admin authors).

**Tests (51, apex adversarial):** the escape battery — PHP operators/sigils/`::`/`->`/`[]`/braces/backticks
rejected; every non-whitelisted function (`system`/`eval`/`app`/…) refused; object property/method never
reached; data-value template syntax not re-evaluated; `{{ }}` HTML-escaped (no stored XSS); iteration/output/
source/expression-depth caps; malformed/unbalanced tags rejected; save-lint blocks scripts/handlers/js-urls; a
broken stored template degrades to ''. Plus the functional language (paths, if/elseif/else, for+loop.index,
helpers), service (save/revert/remove/render), the page integration, and the editor authz + customise/save/
revert.

**Adversarial review (verify-then-refute):** an independent sub-agent reviewed the engine and verified the
core guarantees (no PHP exec, no model/method reach, no `e()` bypass, all DoS/parse limits) safe — and **found
one HIGH**: the save-lint scanned the RAW source, so a forbidden token split across a tag
(`<scr{{ x }}ipt>`) bypassed it and rendered live `<script>` (stored XSS under the default permissive CSP).
**Fixed in this build** — the lint now scans the literal SKELETON (source with all tags stripped), and the 4
PoCs are must-block cases in the battery (now 55 tests). Recommendation recorded: enable strict nonce CSP
before delegating template authoring beyond full admins.

### ADR-0039 — Member tools (Wave 2) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.** Built unit-by-unit; this entry
grows per sub-unit.

**2.1 — Bookmarks / saved topics + posts.** A polymorphic `bookmarks` table (reversible) — a PRIVATE edge from
a user to a Topic or Post, one row per target (unique). Saving is **ungated participation** (no ACL key, like a
draft) — the only gate is "signed in". `BookmarkService` is the single writer: `toggle()` (returns the new
state, race-safe via the unique index + catch), `isBookmarked()`, a BATCHED `bookmarkedIds()` for a whole post
page (no per-post query — the same N+1 discipline as reactions), and `paginate()` for the "Saved" view. A
generic `<livewire:forum.bookmark-button kind=… :target-id=…>` (the view never names a class — a short kind
maps to a model server-side) renders on each post + the topic header; `TopicController::show` pre-computes the
viewer's saved set for the page. The `/saved` view (`saved.index`, auth-only) lists newest-first and
**re-checks current visibility** (`forum.view`) so a bookmark in a now-forbidden forum, or a deleted target,
drops out. Nav links added (desktop dropdown + mobile). Tests (6): toggle on/off, unique-edge idempotency,
batched lookup, the Livewire toggle, the `/saved` list + auth gate, and the deleted-target drop-out.

**2.2 — Ignore / block users.** Built on the EXISTING `user_relationships` `TYPE_IGNORE` edge (the PM-block half
was already wired into `ConversationService` in P2-M2b — reused, not rebuilt). New `App\Community\IgnoreService`
mirrors `FollowService` (insertOrIgnore, self-ignore hard refuse, SILENT — no event) with `ignore`/`unignore`/
`ignores`/`ignoredIds`/`ignoredUsers`. A `<livewire:community.ignore-button>` sits on the profile next to
follow; a `/settings/ignore-list` (new settings tab) lists + unignores. **Posts:** `TopicController` passes the
viewer's ignore set; the post loop COLLAPSES an ignored member's post behind a "you ignore this member — show"
reveal — **but never a staff member's** (the guard is `$role === null`, i.e. not Admin/Moderator, so staff
actions are never hidden). **PMs:** unchanged — `IgnoreService::ignore()` writes the same edge
`ConversationService` already reads, proven end-to-end (an ignored sender's PM is refused). Tests (7):
ignore/unignore, self-ignore refuse, the IgnoreService→PM-block integration, the profile button, post collapse
for a member, **never** for staff, and the settings list + unignore.

**2.3 — Content warnings / spoiler blocks (editor + renderer).** The server-side render path **already existed**
(`CanonicalRenderer::spoiler` → `<details><summary>…</summary>…</details>`; `details`/`summary` on the
`ContentSanitizer` allowlist) — added tests pinning it (summary escaped, body sanitised, text projection
intact: no XSS through a content warning). The missing half was the WYSIWYG **editor**: a TipTap `SpoilerNode`
(content-bearing block, `parseHTML` `details`, editor-display `renderHTML`) + a `/spoiler` slash command + a
toolbar button (⚠), all emitting the canonical `{type:'spoiler', attrs:{summary}, content:[…]}` the renderer
consumes. Assets rebuilt (`npm run build`, host node) so the bundle carries the node — a clean build confirms
the JS compiles. **Caveat (flagged):** there is no browser/Dusk harness in the gate env, so the editor UI
itself was NOT browser-tested — the owner should smoke-test inserting a spoiler after pulling. The renderer
guarantee (the security-relevant part) IS tested.

**2.4 — Post scheduling (publish-at, cron-tolerant).** A scheduled REPLY is HELD in a new `scheduled_posts`
table (reversible) — NOT created in the topic — until its time; the publish cron then creates the REAL post
through `PostService::reply()`, so every side-effect (counters, last-post pointers, notifications, search) is
exactly a normal reply's (no duplicated pipeline). `PostScheduler` is the writer: `scheduleReply()`
(future-only), `cancel()`, `pendingFor()`, and `publishDue()`. **Cron-tolerant idempotency:** `publishOne()`
runs each item in a transaction that LOCKS the row and proceeds only if still unpublished — so an overlapping
or coarse tick can never double-publish; a transient failure throws → the tx rolls back → the next tick
retries; a permanent one (topic gone/locked, lost permission, content rejected) is marked done with a null
`post_id` (skipped, never retried). `novfora:posts:publish-scheduled` runs every minute (`withoutOverlapping(5)`,
restore-skipped). The reply composer gains a "schedule for" datetime that routes to `scheduleReply` + redirects
to a `/scheduled` management view (list + cancel). Tests (10): schedule-without-post, future-only, due publish
into the topic, **no double-publish**, not-yet-due untouched, **skip-not-retry on a locked topic**, cancel, the
command, composer scheduling, and the management list+cancel.

**Wave 2 — COMPLETE** (2.1 bookmarks, 2.2 ignore/block, 2.3 spoilers, 2.4 scheduling), all gated + committed.

### ADR-0040 — Discovery (Wave 3) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.** Built unit-by-unit; grows per
sub-unit.

**3.1 — Trending / best-of.** `App\Discovery\TrendingService` ranks topics by an engagement SCORE built from
the EXISTING all-time aggregates (`reply_count*4 + view_count + summed reaction tally` via a correlated
subquery) — no new denormalisation. `trending()` windows on `last_posted_at` (recently active); `bestOf()` is
all-time. **Permission-safe**: every query is gated through `VisibleForumIds::for($viewer)` (null = sees all →
no clause; [] = none → empty; else `whereIn('forum_id', …)`), querying the LIVE topics table so `forum_id` is
always current (no stale-scope re-check needed). Public `/trending` page (`trending.index`) + nav link, with a
reusable `discovery.partials.topic-line`. Tests (4): engagement ranking, trending-window-vs-all-time-best-of,
**exclusion of a forum the viewer can't see** (a guest NEVER), and the page render.

**3.2 — RSS/Atom feeds (per forum / topic / user).** `App\Discovery\FeedBuilder` assembles Atom 1.0 with strict
`ENT_XML1` escaping (dependency-free, like the sitemap). `FeedController` serves `feeds.forum` / `feeds.topic`
/ `feeds.user`, each **public but guest-visibility-gated** — a private forum's (or topic's) feed 404s for
everyone (readers don't authenticate, so feeds expose only what a guest may see), and the user feed filters to
guest-visible forums via `VisibleForumIds`. Cached 15 min per id (sitemap discipline). Auto-discovery
`<link rel="alternate" type="application/atom+xml">` added to the forum, topic and profile heads. Tests (5):
forum/topic/user feeds (content-type + content), the private-forum 404, and the auto-discovery links.

**3.3 — Lightweight recommendations.** `App\Discovery\RecommendationService::related()` — baseline-safe, no ML:
topics that SHARE A TAG with the source (newest-active first), topped up from the SAME FORUM when short.
Permission-safe via `VisibleForumIds`. Rendered as a "Related topics" section on the topic page
(`TopicController` passes `$related`). Tests (4): share-a-tag (excludes source), same-forum top-up,
**never recommends an unseen forum's topic**, and the page section render.

**3.4 — Sitemap depth + SEO polish.** The sitemap now also lists the `/trending` + `/tags` landing pages and
every in-use tag page (usage_count > 0), alongside the existing forums + topics (all still guest-gated).
SEO polish: canonical + Open Graph added to the board page (`forums.show`) and a canonical to the forum index
(topic pages already had the full set). Tests (3): sitemap includes the landing + tag pages, excludes an
unused tag, and the board page emits canonical + og:title.

**Wave 3 — COMPLETE** (trending/best-of, RSS/Atom feeds, recommendations, sitemap/SEO), all gated + committed.

### ADR-0041 — XenForo importer (Wave 4) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.** Mirrors the phpBB driver's bar.

**Decision.** `App\Import\Drivers\XenForoDriver implements SourceDriver, ProvidesAttachments` — a CLEAN-ROOM
driver that encodes only XenForo's PUBLIC table schema (`xf_user`, `xf_node`, `xf_thread`, `xf_post`,
`xf_attachment` + `xf_attachment_data`) to copy DATA, reading the legacy DB READ-ONLY; no XenForo code/templates
are touched. The existing `ImportRunner` provides idempotency/resume (the `import_maps` keyset cursor + per-row
guard), 301 redirects, and content/checksum verification unchanged — the driver only supplies normalised rows.
Registered as `'xenforo'` in `ImportCommand` (which also gains an `--attachments=` option, additive, wired to
all four drivers).

**XenForo specifics handled:** the unified `xf_node` tree (`node_type_id` Category/Forum/LinkForum →
category/forum/link; the runner's topological sort handles parent-before-child); filters to `user_state=valid`
/ `discussion_state=visible` / `message_state=visible` (counts() applies the SAME filters so verify reconciles);
XenForo password hashes aren't Laravel-verifiable → `password_hash=''` → runner assigns a random password +
the user resets (like MyBB/SMF); BBCode bodies via the shared `BbcodeConverter`; attachments join
`xf_attachment`→`xf_attachment_data`, mime derived from the filename, path = the XF2
`internal_data/attachments/<data_id/1000>/<data_id>-<file_hash>.data` layout.

**NOT validated against a live XenForo install** (flagged): the on-disk attachment path layout and the slugged
legacy-URL shapes (`/threads/<slug>.<id>/`) vary by version/config — the DATA mapping is fixture-verified, but
the operator must point `--attachments` at the real internal data dir, and only bare-id/index.php URL redirects
are emitted (slugged-URL redirects need a per-topic slug lookup, a future enhancement).

**Tests (3, mirroring PhpbbImportTest):** full fidelity from a fake in-memory XF schema (valid-users-only, node
hierarchy category→forum, BBCode→md→html, 301 redirects served), idempotency + resume (re-run no-op, then new
rows imported), and attachment import with sha-256 checksum + post-content reconciliation.

**Wave 4 — COMPLETE.**

### ADR-0042 — Saved searches + search operators (Wave 6.1) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.** The fully-buildable Wave-6 slice
(Meilisearch 6.2 + Reverb 6.3 are DEFERRED pending Phase 4 / enhanced-tier validation).

**Search operators.** `App\Search\SearchQueryParser::parse()` pulls inline operators out of the raw `q` string
— `author:<username>`, `in:<forum-slug>`, `tag:<tag-slug>` (repeatable), `after:`/`before:<date>`,
`type:topic`, plus `"quoted phrases"` — and resolves them to the SAME facet fields the form already uses
(authorId/forumId/tagIds/dateFrom/dateTo/type), leaving the residual keyword as the term. Wired into
`SearchQuery::fromRequest()` where **operators take precedence** over the equivalent GET facets. A missing
author/forum resolves to id 0 → empty result (consistent with the form's author facet), never silently
dropped. Driver-neutral: `SearchService` already translates the facets to DB (and, on the enhanced tier,
Meili) filters; visibility (`VisibleForumIds`) is unchanged.

**Saved searches.** New `saved_searches` table (reversible) + `SavedSearch` model + `SavedSearchService`
(own-only by construction: every read/write scoped to `user_id`; `MAX_PER_USER = 50`). A "Save this search"
control on the results page (auth-only) captures the full GET query string (operators + facets) so the search
**replays verbatim**; `/saved-searches` lists + re-runs + deletes; nav link added. `SavedSearchController` is
auth-gated; delete is own-only (a member can't remove another's). Tests (7): operator parse + unknown→empty +
end-to-end author filter; save/list, **own-only delete**, store-from-page, and the page control.

### ADR-0043 — i18n framework + RTL scaffolding (Wave 8.1) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** Stand up Laravel's native localisation as the translation framework rather than a package:
`lang/<code>/*.php` PHP arrays (`__('search.save_this')`, `trans_choice`), the `app.locale`/`fallback_locale`
config already present, and a single allowlist in `config('novfora.locales')`. `en` is authoritative and
fully authored for the surfaces externalised so far; six more locales (es/fr/de/pt_BR + RTL ar/he) are
registered as **scaffolding** — the switcher, middleware and RTL path are exercised end-to-end, but their
`lang/<code>/` files are unwritten, so every string falls back to `en` until a translator fills them.

**Untrusted-input boundary.** The locale is reader-supplied (a `?locale` POST, a session value). All of it is
funnelled through `App\Support\Locales` (the allowlist guard) — `SetLocale` middleware checks `isSupported()`
before `App::setLocale()`, and `LocaleController` validates with `Rule::in(Locales::codes())` before touching
the session/profile. There is **no path** that hands an unvalidated code to the framework. Resolution
precedence: signed-in member's stored `users.locale` → session (switcher) → configured default.

**RTL.** Direction is data, not a second translation: each allowlist entry carries `dir`, and `<html dir>`
is rendered from `Locales::direction(app()->getLocale())`. That is the only RTL switch the layout needs;
CSS is expected to use logical properties so the existing utilities mirror automatically.

**Scope.** This wave ships the framework + the switcher + RTL plumbing and externalises the Wave-6.1 search /
saved-search surface + shared chrome as the proven pattern. Externalising the remaining ~100 Blade views is
**mechanical follow-up** (string-by-string `__()` extraction, no design left to make) and is tracked as such,
not built here. Tests (9): allowlist guard + direction, resolution precedence (user/session/fallback),
RTL `dir="rtl"` vs `dir="ltr"`, switcher persistence, out-of-list rejection, externalised-string lookup.

### ADR-0044 — WCAG 2.1 AA automated audit + fixes (Wave 8.2) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** Enforce accessibility in two layers. (1) A **deterministic parser-level auditor**
(`App\Accessibility\AccessibilityAuditor`, DOMDocument) that flags the machine-checkable WCAG 2.1 AA
failures — missing `img` alt, unlabelled form controls, links/buttons with no accessible name, missing
`html lang` / `title` / `h1` / `main` / skip link, positive `tabindex`, broken `for`/`aria-*` id references.
It backs both a Pest **page gate** (`WcagAuditTest` renders the high-traffic surfaces and asserts zero
findings — so an a11y regression fails CI) and an ad-hoc command `novfora:a11y:audit <url|file>`. (2) A
**manual checklist** (`docs/architecture/accessibility.md`) for what static HTML cannot prove — contrast,
keyboard operability, focus order/visibility, reduced-motion, live regions, screen-reader journeys, RTL
visual mirroring.

**Why a bespoke engine, not axe-core.** axe-core needs a real browser (headless Chrome) — unavailable on the
cron-only baseline tier and absent from the default `pest` gate. A DOMDocument auditor runs in the standard
PHP test process with no extra service, consistent with the progressive-enhancement rule. It is explicitly a
**floor, not a conformance guarantee**: zero findings means no machine-detectable violation on the audited
pages, not full AA — hence the mandatory manual pass.

**Fixes shipped.** Three real bugs the gate surfaced: the header colour-mode toggle had only an Alpine
`:aria-label` binding (no name in server HTML) → static `aria-label` added; the Wave-6.1 "Save this search"
field had only a `placeholder` → `aria-label` added; the create-topic tag input's visible "Tags" label was
unassociated → wired with `for`/`id`.

**Tests.** Engine unit suite (14) proves it catches each violation class AND does not false-positive on
conformant markup; page gate (14 surfaces) asserts zero findings end-to-end.

### ADR-0045 — Load-test harness (Wave 8.3) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review. SCAFFOLDED — NOT VALIDATED.**

**Decision.** Ship a load-test **harness**, not a benchmark: (1) a big-board fixture seeder
(`php artisan novfora:loadtest:seed`, additive/resumable, writing through the real `PostService` so counters
/ last-post pointers / search projection are correct → true query shapes under test); (2) two interchangeable
read-path drivers — `load-tests/k6/browse.js` and `load-tests/artillery/browse.yml` — hitting the guest
surfaces (board, forum, topic, search), parameterised by `BASE_URL`/scale; (3) a procedure with tier
interpretation (`docs/architecture/load-testing.md`).

**Explicitly NOT claimed.** No at-scale numbers were measured or are asserted. The thresholds in the scripts
(`p95<800ms`, `err<1%`) are tunable placeholders, not validated SLOs. Producing real numbers requires running
the harness on representative hardware — out of scope for an unattended build and meaningless as synthetic
figures. Nothing in the harness runs automatically or in the default gate; the seeder carries the production
confirmation guard and creates obvious `Load Test` content for a throwaway DB.

**Why two drivers.** k6 and Artillery cover the two ecosystems teams already use; both are read-only +
guest-only (safe against staging) and exit non-zero on a breached threshold, so either can gate CI once real
targets are set.

**Tested.** The seeder has a feature test at small scale (creates the requested counts via the real write
path, maintains `reply_count`, additive/idempotent on re-run). The driver scripts are static assets — no
k6/artillery binary exists in the gate, so they are not executed there (validating them is a manual run).

### ADR-0046 — Security review sweep (Wave 8.4) (2026-06-14)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** Run an adversarial **verify-then-refute** sweep over the new attack surface of this build
(untrusted-input parsing, permission/visibility, own-only authz, locale handling, HTML parsing, the new
commands/routes/middleware). Two independent reviewers ran in parallel over non-overlapping surfaces plus a
first-party apex pass on the permission core. Each candidate was chased to the failing line with a concrete
exploit and refuted by default unless that exploit held. Record: `docs/architecture/security-review-wave8.md`.

**One MEDIUM, fixed.** `SearchQueryParser` resolved each inline operator with its own DB lookup inside the
token loop, uncapped, on the public unthrottled `/search` — a crafted multi-operator `?q` amplified into
~1000+ synchronous lookups per request (unauthenticated resource exhaustion). Fixed by resolving operators
**once after the loop** (≤1 author + ≤1 forum lookup, one batched tag `whereIn` capped at MAX_TAGS=16, ≤2 date
parses), a 512-char `?q` length cap, and `throttle:120,1` on `/search`+`/suggest` (defence-in-depth). Bounded
to a constant regardless of token count; missing→empty (id 0) semantics preserved. Regression tests added.

**Everything else refuted (verified safe).** No SQL injection (term escaped, facets bound); **no forum-visibility
bypass** — `SearchService::effectiveForumIds` intersects any forum facet with `VisibleForumIds`, so a forged
`in:`/`?forum=` yields an empty result, not a leak/oracle; no IDOR (saved searches scoped to `user_id`); no
mass assignment (`user_id` server-set, `locale` not fillable + `Rule::in` validated); no XSS (Blade `{{ }}`
throughout; `icon.blade.php` emits only trusted map values); no open redirect; CSRF intact; **no XXE/XPath
injection** in the a11y auditor (`loadHTML` HTML parser expands no custom/external entities; XPath values sit
in a quoted literal with `"` stripped); SSRF surface is operator-CLI-only; load-test creds random + prod
guard; `SetLocale` middleware order correct (lazy auth, gates run first). Also tidied a misleading field name
(`Finding::criterion` → `level`; rendered label was already correct).
### ADR-0047 — Clubs (sub-communities): data model + the two-axis privacy architecture (Phase 4 · M1.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** Phase 4 · M1 introduces **clubs** — named sub-communities that own a discussion space and a
roster, with public / members-only / invite-only variants. The #1 fence is **club privacy**: a private-hidden
club and its content must never leak through any surface. This ADR fixes the data model and the privacy model;
M1.2 (club scope through the engine), M1.4 (discussion via the existing forum stack), and M1.5 (per-surface
no-leak enforcement) implement it.

**Decision — schema.** Two tables, both reversible. `clubs` (name, unique slug, tagline, description, privacy,
is_listed, color, avatar/banner, `created_by`→users nullOnDelete, `forum_id` plain pointer set in M1.4,
member_count, settings json, tenant_id seam, softDeletes). `club_user` (club_id, user_id, **role** ∈
owner|moderator|member, **status** ∈ active|pending|invited|banned, invited_by, joined_at; unique(club_id,
user_id)). `club_user.role/status` is the **source of truth** for club rank; M1.2 PROJECTS active roles into
club-scoped `acl_entries`. The discussion space reuses the existing `forums`/`topics`/`posts` stack via a
nullable `forums.club_id` (M1.4) — **no parallel forum system**.

**Decision — two orthogonal privacy axes (not one enum).**
- `privacy` ∈ public|closed|private drives **content** visibility + the join policy: public → world-readable,
  open join; closed → members-only content, join by request→approve; private → members-only content, invite-only.
- `is_listed` (bool) drives **existence/metadata** visibility to non-members. A `private`+unlisted club is the
  **"private-hidden"** fence case — its name/existence never appears to non-members. (A public club is forced
  listed.) The model exposes the single-source-of-truth gates `isContentVisibleTo()` / `isListingVisibleTo()`
  (= the axis OR active-member OR global-staff) and the `scopeListableTo()` query scope.

**Decision — THE APEX CALL: club content-hiding is a query-level gate, NOT pure ACL inheritance.** The board is
**public-by-default**: the `guests` system group holds `forum.view = ALLOW` at **global** scope and the `member`
preset inherits it, so **every** logged-in user resolves `forum.view = true` for any forum via global
inheritance. The three-state engine **cannot** express "members of this private club see it, other logged-in
users don't": `NO` is neutral (inherits, never stops), and `NEVER` is **absolute** and checked across **all**
holders before the scope walk — so a `members`-group `NEVER` at club scope would also hard-deny real club
members (who are themselves in `members`), and no per-user ALLOW can lift it. Therefore:
  1. **Content hiding** for closed/private clubs is enforced at the **query/visibility layer** — a single
     authoritative `Club` visibility gate that **every** exposure surface consults (search, activity feed,
     RSS/Atom, sitemap, profiles, REST API, notifications, the club's own forum/topic controllers). This is the
     same *kind* of helper as the existing `VisibleForumIds`, not a second permission system.
  2. **Capabilities** (post/reply/moderate/manage) flow **through the engine** at **club scope** (M1.2) — a club
     moderator's `topic.moderate` resolves true only within the club; `ActorRank` still prevents a club owner
     from out-ranking global staff.
  3. **Anonymous defence-in-depth**: closed/private clubs seed `forum.view = NEVER` for the **guests** group at
     club scope (no real member is ever a guest), which hard-blocks every anonymous surface (sitemap, RSS,
     guest search) through the `forum.view` checks they already perform — zero new code on those paths.
  This corrects a tempting-but-wrong simplification ("set forum.view=NEVER for members → auto-hidden"): it would
  break for real members. The reasoning is pinned by the M1.5 per-surface no-leak tests.

**Decision — creation gate.** `App\Clubs\ClubCreation::canCreate()` is the single call site; M1.1 baseline =
verified member at **trust level ≥ 2** (plus global staff always). M1.6 swaps the body for a setting-driven
policy (any / TL-threshold / admin-approved) without touching any surface. The founder is seated as the first
**owner** in `ClubService::create()` (transactional); `created_by` nullOnDelete means deleting the founder's
account never destroys the club — the sole-owner-leaves / account-deletion case is handled by M1.3 ownership
transfer.

**Tested (M1.1).** 16 feature tests: creation-policy gate (TL2 yes / TL1 no / guest redirect / staff always);
create flow seats owner + member_count + unique slug + public-forced-listed; directory listing visibility
(public/listed shown, hidden absent for non-members, present for members + staff); hidden-club home 404s for
guest & logged-in non-member, 200 for member & staff; owner/staff manage, stranger 403, owner soft-delete. Gate
green: full suite 1318 passed / 1 skipped / 0 failed, migrate+seed clean, pint clean, phpstan (level 5) 0 errors.

### ADR-0048 — Club-scoped permissions through the existing engine (Phase 4 · M1.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** Clubs resolve their roles through the SAME `PermissionResolver`/`acl_entries` — **no second
permission system**. A new **`club` scope type** joins global/category/forum/thread: `Scope::parse()` accepts
`club:ID`, and `ScopeChain::for(club)` returns `[global, club:ID]` (club membership inherits the global board
defaults, e.g. a member's `post.create`). A club's discussion forums inject the club node into THEIR chain in
M1.4 (via `forums.club_id`), so a club moderator's scoped power reaches every topic in the club and nowhere
else. `acl_entries.scope_type` is already an open varchar — **no migration**.

**`club.manage` (scope_kind=club)** is the one new permission key. Global **administrators** hold it at global
scope (added to the administrator preset → inherited into every club), so they manage any club; **club owners**
hold it per-club. `permissions:sync` re-provisions it additively on upgrade (catalog + admins group) — proven
by test. A global **moderator** is deliberately NOT a club manager (they moderate content; they do not own
clubs) — `Club::isManageableBy()` now resolves through `canDo('club.manage', club-scope)`, so management is
engine-driven, not an ad-hoc role check.

**`ClubRoleProjector`** mirrors the roster (`club_user.role/status`, the source of truth) into per-user
club-scope `acl_entries`: **owner** → `club.manage` + `topic.moderate`/`post.edit.any`/`post.delete.any`/
`post.history.view`; **moderator** → the moderation set; **member** → none (a plain member relies on the global
`member` preset for posting and on the M1.5 visibility gate for access — minimising rows). Projection is
idempotent (clear-then-write), runs on create/role-change/leave, and bumps `AclVersion` so cached verdicts
drop. Club moderation reuses the existing **forum-scope** keys at club scope — no bespoke keys.

**Rank safety.** Club roles never touch global rank: `ActorRank` (M1.3) still guards actor-vs-target, so a club
owner can never act on global staff who happen to be in the club. The club grants are **scope-isolated** —
proven by a test that an owner's club-scoped `topic.moderate` does NOT apply in an unrelated forum.

**Tested.** 10 feature tests (club-scope truth-table): parse + chain shape; owner `club.manage` at own club but
not another; owner club-scoped moderation isolated from other forums; moderator moderate-yes/manage-no; member
none; global admin manages any club; clear revokes; role-change re-projects; `permissions:sync` re-provisions
`club.manage` idempotently. Gate green: full suite **1328 passed / 1 skipped / 0 failed**, pint clean, phpstan
(level 5) 0 errors.

### ADR-0049 — Club membership flows + the rank ceiling (Phase 4 · M1.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** `ClubMembershipService` is the single authority for join / request→approve / invite / leave /
role-change / removal / ownership-transfer. Every mutation keeps `club_user` (the source of truth) consistent,
re-projects club-scope `acl_entries` via `ClubRoleProjector`, and recomputes `clubs.member_count` from the
table (a COUNT, so no lost-update). Join policy follows privacy: public→open, closed→request, private→invite.

**Invitations** are a `club_invitations` table: a 48-char random **token is the secret** carried in the
accept link (so no session needed to address it), optional **email binding**, a hard **expiry** (14 days), and
**single-use** enforced under a `lockForUpdate` re-check inside the accept transaction. The accept route is
`auth+verified+throttle:30,1`; GET only renders a confirm page (resists prefetch), POST accepts.

**Invariants (defence-in-depth, enforced in the service on top of the UI gates):**
- **Sole-owner guard** — a club always keeps ≥ 1 active owner; leave / demote / remove of the last owner is
  refused (transfer first).
- **Global-staff rank ceiling** — `assertRankCeiling()` reuses `ActorRank` verbatim: a club action may land on a
  global **staff** target ONLY if the actor out-ranks them, so a club owner (a regular member globally) can
  **never** remove/demote a global admin/moderator who is in the club. Non-staff targets follow the club role
  hierarchy (owner > moderator > member) and pass — that's the design: club rank is club-local and never
  escalates global rank.
- Roster management (approve/reject/role/remove/invite) is **owner+admin only** (`isManageableBy` →
  `club.manage`); join/leave are self-actions. Both the Livewire SFC and the service re-assert.

**UI.** A `clubs.join-button` SFC (join/request/leave) on the club home; a `clubs.roster` SFC (members + pending
requests + role select + remove + invite-link minting) on `/clubs/{slug}/members` (gated to content-visible
viewers, 404 otherwise); an invite confirm page. Nested route binding scopes the invitation to its club via the
new `Club::invitations()` relation.

**Tested.** 22 tests (19 in the M1.3 suite): join/public, request/closed, private-needs-invite, approve/reject,
non-manager refusal, invite mint/accept/single-use/expired/email-bound, leave + grant-clear, sole-owner guard
(leave/demote/remove), promote→moderator grants club-scope `topic.moderate`, ownership transfer then
ex-owner-leave, the rank ceiling (owner cannot touch an admin in the club; can remove an equal-rank member),
the join-button SFC, roster 404 for a private-club outsider, and the invite-accept route. Gate green: full
suite **1347 passed / 1 skipped / 0 failed**, pint clean, phpstan (level 5) 0 errors.

### ADR-0050 — Club discussion space on the existing forum stack (Phase 4 · M1.4) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** A club owns its discussion through the **existing** `forums`/`topics`/`posts` stack — no parallel
system. A nullable `forums.club_id` (FK, nullOnDelete) tags a forum (and the topics/posts under it) as a club's
space; `ClubService::create()` auto-creates one top-level (`parent_id=null`) forum per club tagged with its
`club_id` and stores `clubs.forum_id`. `ScopeChain::forumChain()` injects `Scope::club(club_id)` right after
global for such a forum, so a club moderator's club-scoped `topic.moderate` (M1.2) resolves for **every topic in
the club** — proven by a thread-scope test — and a private club's guests-group `forum.view=NEVER` (M1.5)
hard-denies anonymous reads through that node.

**Visibility wiring (the part the public-by-default board needs):**
- Club forums are **excluded from the main board index** (`ForumController::index` adds `whereNull('club_id')`).
- `ForumController::show` and `TopicController::show` add a **club content gate** after the existing `forum.view`
  check: `Forum::clubContentVisibleTo($viewer)` (404 = no disclosure) — because `forum.view` alone is granted to
  everyone, the club gate is the real read-enforcement (the engine cannot soft-deny a logged-in non-member;
  ADR-0047). Members + global staff read; others 404.
- **Participation** (start topic / reply) requires `Forum::clubParticipationAllowed($viewer)` = active member or
  staff — wired into the create-topic and reply SFCs and the `canPost` flag. So a **public** club is readable by
  all but postable only by members (join is open — join first). Closed/private clubs gate both.

**Tested.** 9 tests: auto-forum tagged + `forum_id` set; club forum absent from the board index; club scope
injected into the chain; club moderator moderates a club topic while a plain member cannot; private club
forum/topic 404 for guest+non-member, 200 for member+staff; non-member blocked from starting/replying in a club
forum; member can. Gate green: full suite **1356 passed / 1 skipped / 0 failed**, pint clean, phpstan (level 5)
0 errors. The remaining indirect surfaces (search/feeds/sitemap/activity/profiles/API/notifications/attachments)
+ the guests-NEVER + the exhaustive no-leak matrix are M1.5.

### ADR-0051 — Club privacy: the no-leak sweep across every surface (Phase 4 · M1.5) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** The #1 fence: a private-hidden club and its content must NEVER leak through search, activity feed,
RSS/Atom, sitemap, member profiles, notifications, the REST API, or any other surface. Because the board is
public-by-default (global guests `forum.view=ALLOW`), `forum.view` alone cannot hide a club from a logged-in
non-member (ADR-0047), so this milestone wires THREE single-source-of-truth gates across every exposure path.

**The three gates.**
1. **Anonymous** — closed/private clubs seed `forum.view = NEVER` for the **guests** group at club scope
   (`ClubRoleProjector::projectPrivacy`, applied on create + every privacy change). Since the club forum's chain
   includes the club node (M1.4), every guest-resolving surface (sitemap, RSS forum/topic/user feeds, guest
   search) is hard-denied through the `forum.view` checks it already performs — **zero new code on those paths**.
2. **Bulk (logged-in)** — `VisibleForumIds::for($viewer)` now also excludes club forums whose content the viewer
   may not see (public-club OR active-member OR staff), via two upfront queries (no per-forum N+1). This
   auto-protects every consumer: the activity feed, faceted `SearchService`, `TrendingService`,
   `RecommendationService`, and the user RSS feed.
3. **Per-row (logged-in)** — surfaces that resolve the actual viewer per item call `Forum::clubContentVisibleTo`
   in addition to `forum.view`: REST API (forums/topics/topic/createPost → 404 / participation), tag show + tag
   **index** (a club-exclusive tag is omitted), what's-new, attachments, bookmarks, search typeahead. Webhooks
   skip **all** club-forum `topic.created`/`post.created` events. `PostService::dispatchPostNotifications` only
   notifies recipients who can still see the club's content.

**Adversarial review found + fixed TWO leaks the first pass missed** (independent reviewer, verify-then-refute):
- **Reaction-notification emit** (`SendReactionNotification`): emitted a `reaction` notification carrying the
  club topic title to the post author with no club gate — a leak if the author later lost club access. Fixed
  with the same `clubContentVisibleTo($author)` guard `PostService` uses.
- **Stored notification render** (`NotificationController::index`): rendered stored `reply`/`mention`/`reaction`
  notifications (whose JSON snapshots the club topic title) without re-checking current access. Fixed by
  re-gating each topic notification against the recipient's CURRENT club visibility at render time — the same
  pattern `BookmarkController::present()` already uses for saved references. Both fixes have dedicated tests.

**Documented residuals (not user-facing leaks; recorded for the human pass).**
- Scout/Meilisearch **indexing** stores private-club post bodies in the operator's own external index; all
  app-layer search paths re-filter via `VisibleForumIds`, so this is operator-infra (the operator already has DB
  access), not a cross-user leak. A `club is public` guard on `Post::shouldBeSearchable` would harden the
  enhanced tier but adds hot-path queries — deferred.
- `tags.usage_count` includes private-club usage (count-only, no titles/bodies). Leaderboard `users.post_count`
  / reputation are coarse global aggregates that include club activity but reveal no club identity or content.
- Bulk-moderation move-target lists are reachable only by global staff under the default seed (club moderators
  hold `topic.moderate` at club scope only), so they are safe as shipped; flagged as defense-in-depth.

**Tested.** 14 no-leak tests, one per surface (each contrasting a non-member/guest who must NOT see with a
member who must), plus the two adversarial-review regression tests. Gate green: full suite full suite 1370 passed / 1 skipped / 0 failed,
pint clean, phpstan (level 5) 0 errors. The full per-surface verdict + residuals: this ADR + the M1.5 test
suite are the record.

### ADR-0052 — Configurable club-creation policy (Phase 4 · M1.6) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** `App\Clubs\ClubCreation` (the single creation gate from M1.1) becomes setting-driven via two new
`SettingsRegistry` definitions read on the hot path: `clubs.creation_policy` ∈ **any** | **trust** | **staff**
(default `trust`) and `clubs.creation_min_trust_level` (default 2). Semantics: *any* = any verified member;
*trust* = a verified member at trust level ≥ the threshold; *staff* = administrators & moderators only. Global
**staff may always create**, and an **unverified** member never can, regardless of policy. An ACP page (Admin →
Settings → Clubs, `admin.settings.clubs`) edits it via a self-guarding Livewire SFC (the standard `ensureAdmin`
+ 2FA pattern).

**Assumption (recorded).** The brief's third option, "admin-approved", is realised as **staff-only creation**
(the `staff` policy) rather than a request→approval queue — a full club-creation approval workflow (pending
clubs, an approval inbox) is deferred as a fast-follow. This keeps M1.6 a pure settings unit; flagged for the
human pass.

**Tested.** 8 tests: the `any` policy (TL0 yes, unverified no); the `trust` policy at the default threshold 2
(TL1 no / TL2 yes) and a custom threshold 3 (TL2 no / TL3 yes); the `staff` policy (TL4 no, moderator + admin
yes); staff-always; the ACP page saves; a non-admin is 403. Gate green: full suite full suite 1378 passed / 1 skipped / 0 failed, pint clean,
phpstan (level 5) 0 errors.

### ADR-0053 — OAuth social login via Socialite (Phase 4 · M2.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Dependencies (Apache-2.0-compat confirmed).** `laravel/socialite ^5.27` (**MIT** — permissive, Apache-2.0
compatible) for Google + GitHub (core drivers) and `socialiteproviders/discord ^4.2` (**MIT**) for Discord,
wired via `App\Providers\SocialiteServiceProvider` (a `SocialiteWasCalled` listener). Both are pure-PHP, no new
system services — Baseline-safe.

**Decision.** A social sign-in path **alongside** the unchanged Fortify password login. Every provider is **OFF
by default**; an admin enables it and supplies a client id + **encrypted** client secret on the new Admin →
Settings → Social login page (`oauth.{provider}.enabled|client_id|client_secret`, the secret stored via the
encrypted-settings mechanism, never echoed). `App\Auth\Social\SocialProviders` configures the Socialite driver
**per request** from those settings + our callback URL — **no env / config/services.php** entries needed, and a
disabled/unconfigured provider 404s before any redirect. A `social_accounts` table links one local user to one
identity per provider (`unique(provider, provider_user_id)` + `unique(user_id, provider)`).

**APEX — the auth-boundary rules** (`App\Auth\Social\SocialLogin`):
- A **known** `(provider, provider_user_id)` always resolves to the SAME account — never a duplicate.
- A **new** identity creates a fresh, provider-verified account (members + TL0, random unknown password).
- **EMAIL COLLISION → REFUSE.** If the provider email already belongs to a local account, sign-in is **refused
  with no merge**; the user is told to sign in with their password and link the provider from settings (M2.2).
  Account control is proven by the password session, NEVER asserted by a matching email.
- Socialite runs **stateful** (a `state` nonce in the session, validated on callback) as the CSRF defence; an
  `InvalidStateException` (expired session / forged callback) **fails closed** to the login page. SSO does not
  bypass the staff-2FA gate.

**⚠ SCAFFOLDED — not validated against a live provider.** No real OAuth apps / credentials exist in this
environment, so the end-to-end round-trip with Google/GitHub/Discord is **unverified**; the flow is proven only
against **mocked** Socialite responses. Validate with real client credentials + the published redirect URI
before relying on it. (PKCE + an outbound-request review are M2.3.)

**Tested.** 8 feature tests (mocked Socialite): new-user-via-provider (creates + verifies + links + groups),
returning identity (same user, no duplicate), **email collision refused (no merge, stays guest, no identity
attached)**, disabled provider 404, unknown provider 404, redirect kicks off, invalid-state fails closed,
declined consent handled. Gate green: full suite full suite 1386 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors.

### ADR-0054 — OAuth account linking + email-collision safety (Phase 4 · M2.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** A Settings → Linked accounts page lets an authenticated user **link** or **unlink** each enabled
provider. Linking reuses the SAME `/auth/{provider}/callback` as login, disambiguated by an `oauth.link_intent`
session flag set by the POST `oauth.link` action — so a single registered redirect URI per provider serves both
flows. `SocialLogin::link()` attaches the identity to the CURRENT account and **refuses** if that identity is
already linked to a DIFFERENT account; `unlink()` is always safe (the account keeps its email + password, so it
is never locked out) and idempotent.

**The APEX flow, end to end.** A provider email that collides with an existing local account does NOT auto-merge
at login (ADR-0053). Instead the user **proves control** by signing in with their password, then links the
provider from settings — at which point the SAME identity attaches successfully. This is the "link to an
existing account ONLY after proven control" rule, demonstrated by a single test that walks both halves.

**Tested.** 7 feature tests: start-link flags the session + redirects; disabled-provider link 404s; link
attaches the identity; link refused when the identity is already linked elsewhere (no row written for the
attacker); unlink removes it; the **full collision→password-login→link** APEX flow; the page renders. Gate
green: full suite full suite 1393 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors.

### ADR-0055 — OAuth flow hardening: PKCE, state, CSRF, outbound-request analysis (Phase 4 · M2.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**State / CSRF (already in place, now pinned).** Socialite runs **stateful**: a `state` nonce is stored in the
session at redirect and validated on callback; a mismatch throws `InvalidStateException` and the controller
**fails closed** to the login (or settings) page. A test asserts every authorize URL carries `state=`. The
round-trip is **GET-only** (protected by the state nonce, not an app CSRF token); the linking initiator is a
**POST with `@csrf`**, and `oauth.redirect` rejects POST (405) — proven by test.

**PKCE (new, M2.3).** `SocialProviders::driver()` calls Socialite's `enablePKCE()` for providers that support
RFC 7636 — **Google + Discord** (a per-request S256 `code_verifier`/`code_challenge` defending the
authorization-code exchange against interception). **GitHub** OAuth Apps do not support PKCE, so it is omitted
there and the `state` nonce is the sole CSRF defence. Tests assert `code_challenge`/`code_challenge_method=S256`
present for Google + Discord and absent for GitHub.

**Discord driver registration.** Registered via `Socialite::extend('discord', …)` in `SocialiteServiceProvider`
(not the `SocialiteWasCalled` event), so the driver resolves without depending on the SocialiteProviders manager
replacing Socialite's binding — proven by a real-driver test.

**Outbound-request guard analysis (the SSRF question).** The brief asked to "reuse existing outbound-request
guards for provider calls." **Finding: not applicable / no SSRF surface.** Socialite's outbound HTTP — the
authorization redirect, the token exchange, and the userinfo fetch — targets **library-fixed provider
endpoints** (`accounts.google.com`, `github.com`, `discord.com`), never an attacker-influenced URL, so the
`WebhookUrlGuard`/`IpClassifier` SSRF kernel has nothing to gate. The only attacker-supplied value we persist is
the avatar URL, which is **clamped to `https://` + length and never fetched server-side**. Recorded here so the
absence of a guard is a deliberate, reasoned decision rather than an oversight.

**Tested.** 5 hardening tests (real drivers): state present; PKCE present (Google + Discord); PKCE absent
(GitHub); Discord resolves + PKCE; POST-to-redirect 405. The M2.1/M2.2 mocked suites were updated to stub
`enablePKCE`. Gate green: full suite full suite 1398 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors.

### ADR-0056 — SAML SSO: scaffold only, not validated against a real IdP (Phase 4 · M2.4) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review. ⚠ SCAFFOLD — NOT VALIDATED.**

**Decision.** Ship the **seam** for SAML SSO, not a working integration. NovFora provides the `SamlProvider`
contract (`isConfigured`, `loginUrl`, `consume → SamlAssertion`, `metadata`), a `SamlAssertion` DTO, a
`SamlException`, a `SamlManager` (detection), a detection-gated `SamlController` (login / ACS / metadata), the
IdP-config settings (`auth.saml.*`), and the routes — but **NO concrete provider implementation**. A real one
needs a SAML toolkit (e.g. `onelogin/php-saml`) and a **live IdP** to validate the XML-signature, replay, and
metadata handling; none exists in this environment, and shipping an unvalidated crypto/auth path would be worse
than shipping the interface. **This explicitly does NOT work end to end.**

**Behind detection (inert by default).** `SamlManager::enabled()` is true only when `auth.saml.enabled` is on
AND a concrete `SamlProvider` is bound in the container AND it reports `isConfigured()`. Since nothing binds a
provider by default, **every SAML route 404s** out of the box. An operator or a future module binds a real
implementation to light it up. The ACS POST is CSRF-exempt (the IdP posts cross-site, authenticated by the XML
signature inside the provider) — harmless while the route 404s.

**Account mapping.** The scaffold reuses the `social_accounts` table (`provider='saml'`, `provider_user_id` =
the SAML NameID): a **pre-linked** subject signs in; **just-in-time provisioning is deliberately NOT
implemented** (it would carry the same no-silent-merge rule as OAuth, ADR-0053). Fail-closed on any invalid
response.

**Tested (mocked, no real IdP).** 9 tests with a **fake** bound `SamlProvider`: all routes 404 by default /
setting-off / provider-not-configured; redirect to the IdP SSO URL; SP metadata served; pre-linked subject
signs in at the ACS; unknown subject refused (no JIT); invalid response fails closed; the DTO contract. Gate
green: full suite full suite 1407 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors. **Validate against a real IdP +
implement a concrete provider before claiming SAML works.**

### ADR-0057 — Installable PWA: manifest + a no-PII service worker (Phase 4 · M3.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** NovFora is an installable PWA. A web app **manifest** (`/manifest.webmanifest`: name, `start_url`/
`scope` `/`, `display: standalone`, theme/background colours, a scalable maskable SVG icon at
`/icons/novfora.svg`) + a root-scoped **service worker** (`/sw.js`) wired into the layout head, with an
`/offline` fallback page. Pure **progressive enhancement** — a browser without SW support ignores it and the
site is unchanged; nothing on the Baseline tier depends on it.

**The "never authed mutations / PII" fence — enforced two ways.**
1. The SW **only handles GET**; every mutation (POST/PUT/PATCH/DELETE) passes straight through to the network —
   never cached, never replayed.
2. Page HTML is cached **only when the server flags it safe**. The `PwaResponseHeaders` middleware sets
   `X-PWA-Cacheable: 1` solely on **guest, GET, 200** responses for **public** content paths (auth surfaces,
   `/api`, installer, feeds, sitemap are denylisted); an **authenticated** page never gets the flag, so the SW
   never stores a personal/PII page. This server-authoritative gate is more reliable than the SW trying to infer
   auth state from the response. Static shell assets (`/build`, `/icons`, fonts/images) are cache-first (no PII
   possible); navigations are network-first with the `/offline` fallback.

**Tested.** 8 tests: manifest is valid + installable (start_url/scope/display/icons, `application/manifest+json`);
the SW serves at root with `Service-Worker-Allowed: /` and its source contains the no-mutation
(`req.method !== 'GET'`) + header-gated-caching invariants; the offline page renders; a guest public page is
flagged cacheable; an **authenticated page is NOT** (PII protection); an auth-surface page is not flagged even
for guests; the head wires the manifest + SW. Gate green: full suite full suite 1415 passed / 1 skipped / 0 failed, pint clean, phpstan
(level 5) 0 errors. *(Production should add 192/512 raster icons for the widest install-prompt support; the SVG
maskable icon covers modern browsers.)*

### ADR-0058 — Web Push (VAPID) as an opt-in notification channel (Phase 4 · M3.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Dependency.** `minishlink/web-push ^10.1` (**MIT**, Apache-2.0-compatible) for VAPID signing + payload
encryption — pure PHP (ext-openssl/mbstring; no gmp needed in v10), Baseline-safe.

**Decision.** A third notification channel — **push** — built FROM the existing notification system, strictly
**opt-in** and **cron-tolerant**. The opt-in IS a device subscription: the browser subscribes with the site's
VAPID public key and POSTs its `PushSubscription` to `/push/subscribe`; the row's existence enables push for
that device. `Notifier::send()` gains one branch after the mail block — if the recipient prefers push (default
on once subscribed) AND has ≥ 1 subscription, it dispatches a **queued** `SendPushNotification` job drained by
the baseline cron `queue:work` (no persistent worker). Absent a subscription, **nothing is dispatched** and the
in-app/email channels deliver unchanged (the no-push fallback). The job builds the message via `PushPayload`
(pure mapping from the same notification data), sends to every device via `WebPushService`, and **prunes** any
subscription the push service reports gone (HTTP 410/404). VAPID keys live in **encrypted settings**
(`push.vapid_*`); `php artisan novfora:push:vapid` generates them (refusing to overwrite without `--force`).
`push` is registered in the notification channel set so the M3.3 preferences UI renders it.

**⚠ SCAFFOLDED delivery — not validated against a live push service.** No browser subscription / push endpoint
exists in this environment, so the encrypt-and-POST round-trip to a real push service (FCM/Mozilla/etc.) is
**unproven**; the library is real and the wiring is tested with a mocked sender. Validate end to end against a
real browser + push service before relying on delivery.

**Tested.** 10 tests: subscribe stores one row per endpoint (re-subscribe refreshes, no dup); unsubscribe;
auth required; the VAPID public-key endpoint reports disabled→enabled; the Notifier dispatches a push job ONLY
with a subscription; the in-app notification still lands with no subscription (fallback); per-event push opt-out
suppresses it; the payload build; the job sends + prunes the gone subscription (mocked sender); the job no-ops
when VAPID is unset. Gate green: full suite full suite 1425 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors.

### ADR-0059 — Push preferences UI: per-type opt-in + device enablement (Phase 4 · M3.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** The Settings → Notifications page gains the push controls. (1) The existing per-event × per-channel
matrix now renders a **Push** column alongside In-app and Email (the `push` channel added in M3.2), so each
event has a per-type push opt-in — saved as `NotificationPreference(channel='push')`, default on. (2) A new
**"Push notifications on this device"** card drives the browser subscription: inline **Alpine** (no Vite/Node
rebuild — the project ships prebuilt assets) reads the VAPID public key from `/push/public-key`, requests
permission, subscribes via the service worker's `pushManager`, and POSTs the subscription to `/push/subscribe`
(unsubscribe reverses it). It **degrades silently** where the browser lacks SW/PushManager support or the site
has no VAPID keys ("not enabled on this site yet"). Two-layer model: the device card is the per-device opt-in;
the matrix is the per-event delivery preference; the M3.2 Notifier requires BOTH (a subscription + the
per-event push pref) before dispatching.

**Tested.** 3 tests: the page renders the Push column + the device-enable control + all three channel headers;
a per-event push opt-out persists (`enabled=false`); push stays on by default. Gate green: full suite
full suite 1428 passed / 1 skipped / 0 failed, pint clean, phpstan (level 5) 0 errors. *(The browser subscribe round-trip is exercised
server-side via the M3.2 endpoints; the client JS itself is browser-only and unvalidated against a real push
service — ADR-0058.)*

### ADR-0060 — Meilisearch execution path via Scout, behind service-detection (Phase 4 · M4.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** Search shipped (ADR-0010) with two paths: `SearchService::posts()` (typeahead) already ran the
configured Scout engine with a DB fallback, but the faceted search page (`SearchService::search()`) ran
**always** on the database engine. M4.1 adds the enhanced Meilisearch execution path to the faceted page —
typo-tolerance + relevance at scale — **without** making it a hard dependency on the baseline tier.

**Decision.**
1. **Detection, not configuration.** `search()` routes to the Scout engine only when
   `ServiceTier::isEnhanced(Capability::Search)` (driver ∈ meilisearch/typesense/algolia). On the baseline
   `database` driver — the test/CI default — the engine path is never reached. Any engine error (unreachable,
   client absent, malformed response) returns `null` from the engine path and **degrades to the always-correct
   `databaseSearch()`**. The baseline tier can never break.
2. **Privacy: the index is NEVER the sole gate (apex).** The visibility filter `forum_id IN [...visible]` is
   applied natively (via `meiliFilter()`), AND every returned hit is **re-gated in PHP** against approval + the
   visible-forum set + the authoritative `Forum::clubContentVisibleTo()` club gate (mirroring the typeahead
   path's `SearchController::visible()`). A stale or poisoned index cannot leak a private-club or hidden post —
   proven by a test where the faked engine deliberately returns a hidden-club hit and the re-gate drops it.
3. **Engine-expressible queries only.** The engine path is taken only for a keyword query with no tag/type
   facet (`meiliFilter()` does not translate those); tag/type-faceted queries stay on the DB engine to remain
   correct. *(Assumption: this is acceptable — the keyword relevance/typo win is the enhanced-tier value; full
   facet translation to Meili is a future refinement.)*
4. **In-admin setup/health.** Admin → Settings → Search picks the driver (database|meilisearch) and the host +
   **encrypted** API key, pushed into `scout.driver` / `scout.meilisearch.*`. It **refuses to switch to
   meilisearch unless the host responds** (a pre-save `/health` probe) so an admin can never strand search on a
   dead engine, and surfaces live engine status. A **Reindex** action queues `ReindexSearch` (cron-drained,
   `WithoutOverlapping`); it is a no-op on the database driver. The published `config/scout.php` declares the
   Meili `index-settings` (`filterableAttributes: [forum_id, user_id, created_at]` — forum_id is load-bearing
   for privacy) and Typesense schema.

**Tested.** 10 tests. Search path (5, faked Scout engine — no real Meili): enhanced engine runs + re-gates a
hidden-club hit out (no leak); an active member sees their own club hit; engine error degrades to DB; a
type-facet stays on DB; the baseline driver never reaches the engine. Admin panel (5): non-admin 403; save host
on database driver; switch to meilisearch only when reachable + key stored encrypted; switch refused when
unreachable (stays on DB); reindex queues the job. Gate green: **full suite 1438 passed / 1 skipped / 0 failed**
(12409 assertions), pint clean, phpstan (level 5) 0 errors.

**⚠ SCAFFOLDED — NOT VALIDATED against a real Meilisearch.** No Meili instance exists in the build env; the
engine path is proven only against a faked Scout engine. Validate by pointing `MEILISEARCH_HOST`/`MEILISEARCH_KEY`
(or the ACP) at a real Meilisearch, running `php artisan scout:sync-index-settings` then `scout:import 'App\Models\Post'`,
and confirming relevance + that `forum_id` filtering holds. See PROJECT-STATE "SCAFFOLDED — NOT VALIDATED".

### ADR-0061 — Realtime broadcasting + channel authorization, behind service-detection (Phase 4 · M4.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** The baseline tier has no realtime daemon, so the UI updates by Livewire polling (the notification
bell @30s, the PM badge @60s). M4.2 adds the enhanced realtime path — instant updates over websockets when an
operator runs Reverb — **without** any baseline dependency, and with the **socket as a first-class security
boundary**: a user must never receive an event for content they cannot already view.

**Decision.**
1. **Channel authorization is the apex surface.** `routes/channels.php` authorizes three private channels —
   `notifications.{userId}` (owner only), `thread.{topicId}`, `conversation.{conversationId}` — each a thin
   delegate over `App\Broadcasting\ChannelAuthorizer`. The thread check resolves through the **same**
   permission engine (`forum.view` at the thread scope) **and** the query-level club gate
   (`Forum::clubContentVisibleTo`) the HTTP surfaces use; the conversation check is the participant-only
   `ConversationPolicy` (PMs live outside the ACL scope tree). Every method **fails closed**. The board is
   public-by-default, so a non-member can hold global `forum.view=ALLOW` yet is still denied a private-club
   thread by the club gate — proven by a test. Authorization is registered on **every** tier
   (`withBroadcasting` in `bootstrap/app.php`), so the no-leak boundary holds even with a null/log broadcaster.
2. **Events broadcast only when enhanced.** `PostCreated` (→ `thread.{id}`), `MessageSent` (→
   `conversation.{id}`), and a new `NotificationReceived` (→ `notifications.{id}`) implement `ShouldBroadcast`
   with `broadcastWhen()` gated on `ServiceTier::isEnhanced(Capability::Broadcast)` — so the baseline pays
   nothing (no queue, no broadcast). Payloads are **ids only** (no post/message body, no PII): the client
   refetches the rendered content it is already entitled to. `PostCreated` additionally requires
   `approved_state === 'approved'` so a held/pending reply never broadcasts.
3. **Polling stays as the fallback.** The notification bell keeps `wire:poll.30s` as the always-correct
   backstop and *additionally* subscribes to its private channel via Echo **only if `window.Echo` is present**
   (inert otherwise) — pure progressive enhancement. `config/broadcasting.php` ships the `reverb`/`pusher`
   connections, default `null`.

**Tested.** 14 tests. Channel authz (8): notifications owner-only; normal-forum thread allowed; `forum.view`
NEVER denied; unknown thread fails closed; **hidden-club thread denied to a non-member, allowed to an active
member + staff**; conversation participant-only; soft-left participant denied; unknown conversation fails
closed. Events (6): each broadcasts on the correct private channel with id-only payloads and the right
`broadcastWhen` gating; a pending reply never broadcasts; the Notifier pings only on the enhanced tier; the
auth endpoint is registered. Gate green: **full suite 1452 passed / 1 skipped / 0 failed** (12442 assertions),
pint clean, phpstan (level 5) 0 errors.

**⚠ SCAFFOLDED — NOT VALIDATED against a real Reverb.** No Reverb/Pusher server (nor the `laravel/reverb` +
`pusher/pusher-php-server` packages) is installed in the build env — the channel-authz **logic** is fully
proven server-side, but the websocket round-trip is not. The client-side live-append on the **thread page**
needs `laravel-echo`+`pusher-js` bundled (this repo ships prebuilt assets / no Node), so it is wired
server-side only. **Enable steps:** `composer require laravel/reverb pusher/pusher-php-server`;
`php artisan reverb:install`; set `BROADCAST_CONNECTION=reverb` + `REVERB_*` env; `npm install laravel-echo pusher-js`,
configure `window.Echo` (reverb/pusher), `npm run build`; run `php artisan reverb:start` under a supervisor.
See PROJECT-STATE "SCAFFOLDED — NOT VALIDATED".

### ADR-0062 — Presence / "who's online", opt-in with a presence-channel no-leak fence (Phase 4 · M4.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** The board already had an "online" heuristic (`User::isOnline`, `last_active_at` stamped by
`ThrottledLastActive`) and a theme `OnlineUsersWidget`, but it showed **every** recently-active member with **no
opt-in** — a privacy gap. M4.3 turns presence into a privacy-respecting, opt-in feature with a live (enhanced)
path and a baseline polling fallback, and closes the gap.

**Decision.**
1. **Opt-in, security-by-default.** A new `users.show_online_status` column (boolean, **default false**,
   reversible migration) governs whether a member appears in any presence surface. A member is **invisible
   until they deliberately opt in** via a privacy toggle on the appearance settings page. *(Assumption: "opt-in"
   in the brief + the project's "security by default" rule ⇒ default OFF; this makes the list sparse until
   members opt in — an admin-default could be added later. Recorded as a non-obvious assumption.)*
2. **One source of truth.** `App\Presence\OnlineMembers` is the only place the opt-in + active + recent-window
   rule lives (`recent()`, `count()`, `inClub()`); the theme widget, the new live widget, and any future
   surface read it, so the privacy rule can never drift. The existing `OnlineUsersWidget` was refactored onto it.
3. **Baseline-safe + enhanced.** A new `⚡online-members` Livewire widget (on the members directory) polls
   `OnlineMembers` every 60s on the baseline (no daemon), and on the enhanced tier *additionally* joins the
   `online` **presence channel** via Echo (inert if `window.Echo` is absent).
4. **Presence no-leak (apex extension of M4.2).** Two presence channels in `routes/channels.php` →
   `ChannelAuthorizer`: `online` returns the member's info **only if opted in** (symmetric — a non-opted-in user
   neither appears nor sees over the socket); `club-presence.{clubId}` returns info **only for an active member
   of that club** who opted in, so a non-member can never enumerate a private club's online roster.

**Tested.** 6 tests + 1 existing widget test updated. `OnlineMembers` lists only opted-in + recent + active;
club presence intersects the active roster + opt-in (non-member never enumerated); the `online` presence channel
authorizes only opted-in members; `club-presence` authorizes only active opted-in members and never a
non-member; the live widget lists only opted-in members; the opt-in toggle persists. The theme-widget test now
asserts an opted-out active member is hidden. Gate green: **full suite 1458 passed / 1 skipped / 0 failed**
(12466 assertions), pint clean, phpstan (level 5) 0 errors.

**⚠ SCAFFOLDED — NOT VALIDATED against a real Reverb.** The baseline polling path is fully real; the presence
**channel** (live join/leave) shares the M4.2 broadcaster, so it is proven only at the authorization level —
the websocket presence round-trip needs a real Reverb + bundled Echo (same enable steps as ADR-0061).

### ADR-0063 — Membership tiers: perk gating through the permission engine (Phase 4 · M5.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Context.** M5 adds paid memberships. M5.1 is the foundation: a tier model whose perks gate THROUGH the
existing permission engine (no parallel authz system), an admin tier manager, and a member-facing surface.
**No money is taken anywhere in M5.1** — granting is done by a PaymentProvider (M5.2 manual / M5.3 Stripe) or an
admin; this milestone is the catalogue + the grant/revoke mechanism.

**Decision.**
1. **Perks ARE permission keys (engine-true).** `App\Membership\TierProjector` mirrors the proven
   `ClubRoleProjector`: it projects a member's ACTIVE subscriptions into per-user, **global-scope** acl_entries,
   so a perk is a normal `$user->canDo('tier.ad_free', Scope::global())`. The 30-min resolver cache drops on the
   `AclVersion::bump()` every projection performs.
2. **Fixed perk universe (a security boundary).** `App\Membership\TierPerks::ALL` is a FIXED set of `tier.*`
   keys. The projector's clear step is **bounded to that universe**, and the admin form validates against it, so
   a tier can never grant an arbitrary capability (`admin.access`) and the clear never touches other global user
   grants — proven by a test that feeds a poisoned perk list (`admin.access`, `tier.bogus`) and asserts only the
   valid perk lands.
3. **Lifecycle through one service.** `MembershipService::activate/cancel/expireDue` are the only writers of a
   subscription's status, and every transition re-projects. Expiry is a baseline-safe hourly cron
   (`novfora:tiers:expire`, `withoutOverlapping`, skipped during a restore) — no worker needed. Every transition
   is audit-logged.
4. **Surfaces.** An admin manager (`admin.tiers`, staff + 2FA gated) does tier CRUD + perk selection; a
   member page (`/membership`) lists active tiers and the current subscription. Reversible migrations
   (`membership_tiers`, `member_subscriptions`); **no card data is ever stored** (`provider_ref` is an opaque id).

**Tested.** 14 tests. Gating (7): activate grants the right perks (and only those); cancel revokes; the hourly
expiry revokes; an inactive tier grants nothing; multiple active subscriptions union their perks; an arbitrary
key outside the universe is NEVER granted. Admin (5): non-admin 403; create-with-perks; a non-universe perk is
rejected at the form; edit/deactivate; delete-on-confirm. Member page (3): guest redirected; active tiers listed
+ inactive hidden; the current plan is shown. Gate green: **full suite 1472 passed / 1 skipped / 0 failed**
(12512 assertions), pint clean, phpstan (level 5) 0 errors.

**Assumption (recorded).** Each perk's *effect* (actually hiding ads, allowing a custom title, etc.) is wired
per-feature; M5.1 delivers the **gating mechanism** — the engine grant/revoke — which is what the brief specifies
("tier gating grants/revokes the right capabilities"). The starter perk set is illustrative and admin-editable.

### ADR-0064 — Payment-provider interface + the offline/manual provider (Phase 4 · M5.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**MONEY FENCE.** This build NEVER initiates a real charge. The only path that actually grants a membership is
the **offline/manual** provider: an admin records that a member paid (cash/transfer/comp) and grants the tier.
Stripe (M5.3) is a separate, charging-DISABLED adapter behind the same interface.

**Decision.**
1. **A small, semver'd `PaymentProvider` contract** (`key/label/isEnabled/supportsSelfCheckout/checkout`) so the
   member surface + ACP are provider-agnostic. The GRANT always flows through `MembershipService` (ADR-0063), so
   capabilities resolve identically regardless of how payment was collected.
2. **`ManualPaymentProvider` — the live-granting path.** `isEnabled() = true` (no external service), but
   `supportsSelfCheckout() = false` (it never shows a member "buy" button, and `checkout()` throws). It exposes
   `grant(user, tier, ?expiresAt)` → `MembershipService::activate(provider: 'manual', ...)` and `revoke()` →
   `cancel()`. Driven entirely from the admin surface.
3. **`PaymentProviders` registry** reports which providers are enabled and which support self-checkout. In M5.2
   it knows only `manual`; `selfCheckout()` is **empty** (so no online "buy" appears anywhere until Stripe is
   added + enabled in M5.3). Stripe is appended to `candidates()` in that milestone.
4. **Admin Memberships surface** (`admin.memberships`, staff + 2FA gated): resolve a member by username/email,
   pick an active tier, optionally set an expiry (days), grant — and a list of active grants with revoke. No
   card data is handled or stored.

**Tested.** 10 tests. Provider (5): grant activates + grants perks through the engine; grant honours an expiry;
revoke cancels + revokes; `checkout()` throws + `supportsSelfCheckout()` is false; the registry lists `manual`
as enabled with an empty self-checkout set. Admin (5): non-admin 403; grant-by-username flips the capability;
grant-by-email honours an expiry; an unknown member yields a soft error and grants nothing; revoke drops the
capability. Gate green: **full suite 1482 passed / 1 skipped / 0 failed** (12545 assertions), pint clean, phpstan
(level 5) 0 errors. *(No SCAFFOLDED caveat — the manual path is fully real and is the only live-granting path.)*

### ADR-0065 — Stripe hosted checkout (charging DISABLED) + a hardened webhook (Phase 4 · M5.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**MONEY FENCE (APEX).** This build NEVER initiates a charge. `StripePaymentProvider::isEnabled()` is **false by
default** (requires both the enable flag AND a configured secret key), so `checkout()` hard-throws and the
provider never reaches the Stripe API. Enabling Stripe is a deliberate, documented owner step (below).

**Decision.**
1. **Hosted Checkout, minimal PCI.** When enabled, `checkout()` creates a Stripe-hosted Checkout Session and
   redirects the member to Stripe — **card data never touches our server**. The grant happens later, only on a
   signed webhook, never on the checkout request. No `stripe/stripe-php` dependency is added: the session is a
   single `Http::asForm()->post()` to a **constant** API URL (no SSRF surface — success/cancel URLs come from
   our own named routes, never from input).
2. **Hardened webhook (APEX untrusted-input).** `POST /webhooks/stripe` mirrors the mail webhook's fail-closed
   discipline: dormant **404** until enabled + a webhook secret is set; **413** oversize; the
   `Stripe-Signature` HMAC verified FIRST (`StripeWebhookVerifier`: `hash_hmac('sha256', "{t}.{body}")` +
   `hash_equals` + a 300s replay window) → **401** on forgery/stale, no DB write; **422** malformed/incomplete;
   a valid `checkout.session.completed` GRANTS the tier **idempotently** (deduped on the Stripe session id);
   any other event type is acknowledged 200 without action. It never fetches a URL from the payload.
3. **Surfaces.** Admin → Settings → Payments stores the keys (secret + webhook secret **encrypted**) and refuses
   to flip `enabled` without a secret. The member page shows a "Subscribe" button only when a self-checkout
   provider is enabled (Stripe in `PaymentProviders::selfCheckout()`); otherwise "contact an administrator".

**Tested.** 18 tests, all with **synthetic signed events / a mocked HTTP client** (no real Stripe). Webhook (9):
dormant-404; forged-401; stale-401; oversize-413; valid grant flips the capability; replay idempotent;
unrelated event ignored; missing-metadata 422; unmappable user/tier acknowledged without grant. Provider (6):
disabled-by-default refuses checkout; enabled creates a hosted session with metadata + NO card data; registry
self-checkout only when enabled; route 404 when disabled / redirects when enabled. Admin (3): non-admin 403;
won't enable without a secret; secret stored encrypted. Gate green: **full suite 1500 passed / 1 skipped / 0
failed** (12593 assertions), pint clean, phpstan (level 5) 0 errors.

**⚠ SCAFFOLDED — NOT VALIDATED against live Stripe.** No Stripe account/keys in this build; the request SHAPE +
signature scheme are proven, but the real API round-trip, the exact form-encoding, and renewal handling
(`invoice.*` events extend an expiry — NOT built; this receiver handles `checkout.session.completed` only) are
unproven. **Enable steps (owner):** (1) create a Stripe account + products; (2) Admin → Settings → Payments:
paste the secret/publishable keys, toggle on; (3) add a Stripe webhook endpoint → `https://<site>/webhooks/stripe`
for `checkout.session.completed`, paste its signing secret; (4) run a real test-mode checkout and confirm the
grant; (5) consider `invoice.payment_succeeded`/`customer.subscription.deleted` handling before relying on
auto-renewal. Until then, the **offline/manual** provider (ADR-0064) remains the live-granting path.

### ADR-0066 — Paid-clubs hook: gate club creation on a membership perk (Phase 4 · M5.4) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision (money-fenced, NO new money path).** The "could-have" hook tying M1 (clubs) to M5 (memberships):
a new setting `clubs.require_membership` (bool, **default false**). When ON, `ClubCreation::canCreate()` ALSO
requires the non-staff member to hold the `tier.create_clubs` membership perk — granted through the engine by an
active subscription (manual or Stripe). It introduces **no new payment path**: the perk is acquired via the
existing M5.1–M5.3 mechanisms. Staff always create; the gate is additive to the existing trust/policy gate, so
with the flag OFF the baseline is unchanged. A toggle was added to Admin → Settings → Clubs.

**Tested.** 5 tests: default off leaves baseline behaviour; flag-on blocks a qualifying member without the perk
(service + the `clubs.create` route 403); flag-on allows a member who holds the perk (route 200); staff always
create; the admin toggle persists. Gate green: **full suite 1505 passed / 1 skipped / 0 failed** (12601
assertions), pint clean, phpstan (level 5) 0 errors. *(No real money involved — the hook gates on a perk, not a
charge; the perk itself is granted by the audited M5.1–M5.3 paths.)*

### ADR-0067 — Advanced spam intelligence: HOLD-only scoring with FP guards (Phase 4 · M6.1) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**APEX (untrusted-input).** A new `SpamScorer` adds reputation/behavioural scoring to the post-time pipeline.
It is **HOLD-ONLY** — `ContentModerator` caps its effect at HOLD, so it can never reject or delete a post; the
strongest action is routing to the existing moderation queue (`approved_state = 'pending'`). Human-in-the-loop
is preserved.

**Decision.**
1. **Signals (config-tunable, `antispam.intelligence`).** Content **similarity** (the author reposted
   near-identical content recently — a normalised-fingerprint match against their last N posts in a window),
   **burst** (more than a threshold of posts in a short window, beyond the per-minute rate limiter),
   **new-account**, and **tl0**. Each contributes weighted points; at/above `hold_threshold` (default 3) the
   post is held, with per-signal reasons (`spam:similarity`, …) appended to the verdict.
2. **False-positive guards (the priority).** Trusted members are **EXEMPT** and never scored — staff, trust
   level ≥ `trusted_floor` (3), or ≥ `established_posts` (50) approved posts. Short content (< 12 fingerprint
   chars) never triggers the similarity signal, so common short replies ("thanks", "+1") are never flagged. A
   new member's first *normal* post scores below the threshold (tl0 + new = 2 < 3), so onboarding isn't punished.
3. **Evidence for review.** A held post records a `spam_assessments` row (score + per-signal breakdown +
   moderation reasons, linked to the post) + an audit-log entry (`post.spam_held`) — the data the M6.2 review
   surface renders. Approved posts add no rows on the hot path. `SpamScorer` integrates via a single new step
   in `ContentModerator::review()`; the verdict now carries the `SpamScore`.

**Tested.** 8 tests. FP guards (4): trusted member exempt even with duplicate+burst; established-by-post-count
exempt; a new member's first normal post not held; a short repeated reply not flagged. Detections (2): a new
member reposting identical content held (similarity); a bursting new member held. Pipeline (2): a reposted
duplicate is held (`pending`) + records the assessment + is **never deleted** (the row still exists); a trusted
member's repost is approved with no assessment. One unrelated pagination fixture (20 rapid replies from a fresh
account) was retargeted to a trusted author — the burst behaviour it tripped is correct. Gate green: **full
suite 1513 passed / 1 skipped / 0 failed** (12618 assertions), pint clean, phpstan (level 5) 0 errors.

### ADR-0068 — Spam-intelligence review surface (Phase 4 · M6.2) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**Decision.** Admin → Spam intelligence (`admin.spam-intelligence`) renders the `spam_assessments` recorded in
M6.1 for posts still held — each held post with its **score**, its **per-signal breakdown** (similarity/burst/…
with point values), the moderation **reasons**, the author, the thread, and a content excerpt — highest score
first. Two actions: **approve** (clears the hold → `approved` + dispatches the post's notifications, reusing
`PostService::dispatchPostNotifications`) and **reject** (`rejected` + a **soft-delete** to the recycle bin —
never a hard delete, preserving the M6.1 hold-only/human-in-the-loop posture). The page is staff-gated in
`mount()` (admin.access + 2FA), and approve/reject **re-check `topic.moderate` on the post's thread** (mirrors
`ModerationController`), so the surface never widens authority.

**Tested.** 4 tests: non-admin 403 (page + Livewire); held posts listed with score + signals; approve clears the
hold; reject soft-deletes (and the row is still recoverable, `approved_state = 'rejected'`). Gate green: **full
suite 1517 passed / 1 skipped / 0 failed** (12634 assertions), pint clean, phpstan (level 5) 0 errors.

### ADR-0069 — External-signal tuning + the content-privacy fence (Phase 4 · M6.3) (2026-06-15)
**Status: Accepted — owner-authorized overnight build; flagged for review.**

**APEX (untrusted-input + privacy).** Centralises the operator's control over the already-wired StopForumSpam
signal and enforces the fence: **a community's post content never leaves the server without an explicit admin
opt-in.**

**Decision.**
1. **`ExternalSignalPolicy`** is the single gate: `apiEnabled()` (the existing live-API opt-in — metadata
   lookups only), `confidenceThreshold()` (admin-tunable block threshold, DB setting → config → 75),
   `maySubmitContent()` (the fence — **default false**), and `apiKey()` (encrypted). `RegistrationGuard` now
   reads the threshold from the policy, so admins can tune block-vs-flag without a deploy (default behaviour
   unchanged at 75).
2. **`SpamReporter` (opt-in, inert by default).** Reports a confirmed spammer to StopForumSpam, but makes **no
   outbound call** unless the API is enabled AND a submission key is set. The spammer's **post content** is
   included as evidence **only** when `maySubmitContent()` is on; otherwise only metadata (IP/email/username) —
   already the SFS posture — is sent. Wired into the M6.2 reject action (so rejecting genuine spam can report
   it), inert unless opted in.
3. **Admin surface.** Admin → Settings → Anti-spam gains the threshold, the SFS submission key (encrypted), and
   a prominent **"send post content to external services"** toggle with a privacy explanation — off by default.

**Tested.** 8 tests. Policy (2): content-submission denied by default; threshold reflects the setting.
RegistrationGuard (1): the tuned threshold flips block↔flag (faked SFS API at confidence 70). Reporter (4): no
call without a key; no call when the API is disabled; **metadata-only (no post content) without the content
opt-in**; content included only with the explicit opt-in. Admin (1): threshold + opt-in + encrypted key persist.
Gate green: **full suite 1525 passed / 1 skipped / 0 failed** (12652 assertions), pint clean, phpstan (level 5)
0 errors.

**⚠ SCAFFOLDED — NOT VALIDATED against the live StopForumSpam submission API.** No submission key in this build;
the gate logic + request shape are proven with a mocked HTTP client. The reporting feature stays inert until an
operator sets a key and opts in.

### ADR-0072 — Phase 5 adversarial security review (P5.1) (2026-06-16)
**Status: Accepted — owner-authorized GA-hardening run; flagged for review.**

**APEX (whole-app security).** A second full adversarial review — per-finding **verify-then-refute** over the
entire surface, with emphasis on the Phase 3/4 additions the Phase-1.5 + Wave-8.4 passes never covered. 11
domain reviewers fanned out; each HIGH/MEDIUM candidate faced an independent 3-lens refuter panel
(reachability · existing-mitigation · severity); survivors were re-read and fixed with a regression test.
Full writeup + findings table: [`docs/architecture/security-review-phase5.md`](docs/architecture/security-review-phase5.md).

**Recorded assumption — model routing.** CLAUDE.md routes security/permission/untrusted-input work to **Fable @
max**. `claude-fable-5` was **unavailable in this build environment** (every Fable sub-agent erred), so the apex
rung was taken at the next-highest available tier, **Opus 4.8 (1M)**, for both reviewers and panels — a
conservative, security-preserving fallback (a stronger model would only surface more). Flagged so it is not read
as a routing violation.

**Outcome.** **No HIGH confirmed.** 8 MEDIUM + 3 LOW + 2 INFO **fixed** (each with a test, no control weakened);
6 candidates **refuted**.
- **Fixed (Med):** (1) search forum-facet leaked private-club names to logged-in non-members → add
  `clubContentVisibleTo` gate; (2) OAuth/SAML login skipped mandatory **staff 2FA** → `ChallengesStaffTwoFactor`
  trait defers staff to Fortify's TOTP challenge on every SSO path; (3) OAuth JIT signup bypassed
  `registration.enabled` + the anti-spam screener + **email/IP bans** → `resolveForLogin` mirrors
  `CreateNewUser` (refuse / flag→pending); (4) REST `createPost` ignored the **locked-topic** gate → shared
  `Topic::isReplyable()`; (5) installer **DB-test SSRF** reachable as a direct Livewire action, bypassing the
  setup token → re-assert the token at the sink; (6) Stripe webhook granted without **`payment_status`** proof →
  require paid/no-payment-required; (7) **unbounded `@mention` fan-out** (mass-notify + sync DoS) → cap at
  `antispam.mention_fanout_cap` (10); (8) importer **legacy-attachment path traversal** → reject `..`/scheme at
  the read site.
- **Fixed (Low/Info):** (9) Stripe idempotency had no DB UNIQUE → reversible `UNIQUE(provider, provider_ref)`
  migration + violation catch; (10) `/api/v1` ran without the install/upgrade maintenance gates → applied ahead
  of token auth; (11) attachment on a soft-deleted post still downloadable → mirror the trashed gate;
  (12) manifest reserved-namespace check case-insensitive; (13) clamp + strip control/bidi from OAuth
  `display_name`/`nickname`.
- **Refuted (recorded):** sole-owner club orphan (data-integrity, not security — already an ADR-0047
  fast-follow); API skips the trust-tiered post rate limiter (bounded by `throttle:api` + the engine/anti-spam
  pipeline); `acl_entries` no DB UNIQUE (resolver is duplicate-tolerant); 2FA mutations need no password
  re-confirm (documented Phase-2 deferral, ADR-0019); OAuth callback IP-only throttle (protocol cap bounds it);
  sandbox quoted-URL scheme escape (admin-trust-gated).

**Gate.** Every fix committed only at a green boundary (Pest + PHPStan L5 + Pint). The new regression tests are
added across nine suites (clubs, import, modules, install, api, auth, membership, notifications, security).

### ADR-0073 — i18n completeness wave: proof locale + auth/error externalisation (P5.3) (2026-06-16)
**Status: Accepted — owner-authorized GA-hardening run; flagged for review.**

**Context.** ADR-0043 shipped the i18n framework (allowlist `Locales`, `SetLocale` precedence, validated
`POST /locale`, `<html lang/dir>` RTL, 7 registered locales) + the search surface, and explicitly deferred the
~200-view string sweep as "mechanical follow-up." P5.3 completes the framework-level guarantees and proves the
translation path, without attempting a full 201-view sweep (which is unverifiable without a browser in this
unattended run and is, per the Phase-5 fence, community-contributable).

**Decision.**
1. **Proof locale `es`** — a curated HUMAN translation of `lang/en/{common,search,auth,errors}.php` under
   `lang/es/`. Not a machine translation of the whole app (the fence forbids that); it proves switcher →
   `SetLocale` → `__()` end-to-end with real non-English strings + localised pluralisation.
2. **Externalisation wave** — every `auth/*` screen and every `errors/*` page (the highest-traffic
   unauthenticated surfaces, the first thing every visitor sees) into new `lang/en/auth.php` + `lang/en/errors.php`.
3. **Tests** (the fence's required three, now explicit): a missing key falls back to `en` per-key (an
   untranslated registered locale `fr`); RTL renders (`dir="rtl"`, already in ADR-0043); the switch persists
   (already in ADR-0043); plus the `es` proof locale renders Spanish.

**Recorded scope assumption (residue).** The authenticated front-end (`forum/clubs/pm/profiles/settings/…`, the
~92 `components/`) and the staff-facing `admin/` ACP (~33 views) remain on hardcoded English — DOCUMENTED as the
mechanical, community-contributable residual (see docs/architecture/i18n-and-rtl.md). The framework makes this
safe to land incrementally: an un-externalised string shows its literal English and any locale missing a key
falls back to `en`, so partial externalisation + partial locales are always correct. This is **not** a 100%
externalisation; it is a complete framework + proof + the visitor-facing surface, with the rest flagged.

**Tested.** `LocalizationTest` → 11 (was 9; + es-renders-Spanish, + fr-per-key-fallback). All `auth/*` +
`errors/*` views recompile and render (a sub-agent's smart-quote slip in 6 views was caught by the gate —
error-page renders 500'd — and fixed). Gate green (Pest + PHPStan L5 + Pint).

### ADR-0074 — Performance: hot-path query profiling + the N+1 regression gate (P5.4) (2026-06-16)
**Status: Accepted — owner-authorized GA-hardening run; flagged for review.**

**Context.** ADR-0045 shipped the load harness (seeder + k6/artillery + procedure) but ran no numbers (a
traffic test needs a server + binary + representative hardware, out of scope for an unattended build). P5.4 adds
the deterministic half — profiling the query SHAPE of the hot paths, which is what actually catches the perf bug
that hurts a forum (an N+1 turning one page into hundreds of queries).

**Decision / finding.** New `tests/Feature/Performance/HotPathQueryTest.php` (run in the normal gate) seeds a
page-full of items with distinct authors and asserts a BOUNDED query count per surface. Captured baseline:
board index **13 (warm/steady-state)**, forum listing/topic/search/clubs all **< 40–45**. **No steady-state N+1
was found.** The board index's cold build (~69 with 8 forums) populates the 60s `forum.index.tree` fragment
cache + warms the resolver/ACL cache — amortised to once per TTL. Hot-path columns are already indexed (posts
`(topic_id,position)`/`(topic_id,created_at)`/`user_id`/`approved_state`; topics `forum_id`; forums
`parent_id`), so the bounded queries are seek-friendly.

**No speculative index added.** A composite `(forum_id, is_pinned, last_posted_at)` for the forum-listing sort
was considered but NOT added: the `last_posted_at IS NULL` ordering expression may defeat index-sort use, and it
cannot be validated without an at-scale MySQL EXPLAIN — which would violate "tests with every change." It is
documented as a conditional, reversible enhanced-tier tuning step instead.

**Documented (load-testing.md):** the captured baseline table, the regression gate, the enhanced-tier procedure
+ suggested SLOs (baseline reads p95 < 600ms / search < 1.5s; enhanced reads < 250ms / search < 300ms),
capacity guidance, and the **validate-before-go-live** items (run k6/artillery at scale on the real
MySQL/enhanced host; EXPLAIN the forum-listing sort). The enhanced tier was **NOT run against a real host.**

### ADR-0075 — 1.0 release readiness: brand-rename completion, gate, version (P5.5) (2026-06-16)
**Status: Accepted — owner-authorized GA-hardening run; flagged for review.**

**Decision.** Complete the Phase-5 "rename surface #8" (ADR-0024/0026/0028) so the retired `nevo`/`NevoBB`
codename survives **only** in historical ADR/doc references, and bump to **1.0.0**.
1. **Command prefix** `nevo:` → `novfora:` (`RecomputeBadges`/`RecomputeReputation` commands + their
   schedules in `routes/console.php` + `DemoSeeder` + the 4 tests that invoke/assert them).
2. **Editor island** `nevoEditor` → `novforaEditor` (`resources/js/editor/island.js`, `app.js`,
   `components/content-editor.blade.php`); **assets rebuilt** (`npm run build`) so the prebuilt `public/build`
   carries the new name (old hashed asset removed — no `nevo` remains in the build).
3. **Dev/CI infra** DB/user/volume/network names + script copyrights renamed (`nevo_test`→`novfora_test`,
   `nevo`→`novfora`, `NevoBB`→`NovFora`) across `ci.yml`, `docker-compose.yml`, `docker/*`, `.env.example`,
   `scripts/*`, `.gitignore`, `pint.json`, the pagination view comment.
4. **CI brand gate:** a new `static`-job step fails the build if `git grep -i nevo` matches anything outside
   the historical doc set — the ROADMAP 1.0 exit criterion, now enforced.
5. **Version:** `config/app.php` gains `version => env('APP_VERSION', '1.0.0')` (surfaced by `/health`, the
   backup/install manifests, the upgrade fingerprint; replaces the `'1.0.0-mvp'` call-site fallback).
6. **Hygiene:** removed `.env.root-stale` — a stray committed duplicate of `.env.example` (blank `APP_KEY`,
   no real secret) accidentally committed in `b3ed796`; a `.env`-named file does not belong in the repo.

**Docs → 1.0.** New `CHANGELOG.md` (Keep-a-Changelog; the 1.0.0 entry summarises Phases 1–5 + the
validate-before-go-live caveats) and `docs/product/release-checklist-1.0.md` (pre-flight gates → cut → go-live
validation). `README`, `getting-started` (install+upgrade), `CONTRIBUTING`/`GOVERNANCE`/`CODE_OF_CONDUCT`,
`LICENSE` already present + brand-clean.

**Tested.** The renamed commands resolve + pass their cron tests; the full suite + PHPStan L5 + Pint stay green;
`git grep -i nevo` is docs-only. No test asserted the old `1.0.0-mvp` literal, so the bump is non-breaking.

### ADR-0076 — Fresh-install readiness: the from-scratch redeploy path, proven (P5.6) (2026-06-16)
**Status: Accepted — owner-authorized GA-hardening run; flagged for review.**

**Context.** The owner will redeploy 1.0 on a new host via the no-SSH path; it "MUST be clean." This unit
proves that path end to end.

**Decision / evidence.**
1. **Fresh-install smoke** (`tests/Feature/Install/FreshInstallSmokeTest.php`, in the normal gate): drives the
   SAME `InstallRunner` the web wizard + `novfora:install` CLI use, against a truly EMPTY sqlite DB, and
   asserts the outcome — schema created (users/groups/permissions/roles/acl_entries/forums/topics/posts), the
   system posture seeded (`admins`/`members`/`guests` + `tl4` groups, the permission catalogue, roles), and a
   first admin who is active + TL4 + email-verified + in `admins` AND **actually resolves
   `admin.access` through the engine** (the real proof, not just rows) — and the install **lock written last**.
   21 assertions, green.
2. **Build artifact:** `scripts/build-release.sh` (SKIP_NPM, host-prebuilt assets) produced a clean
   `novfora-release.zip` — `bootstrap/cache/packages.php` ships (the RH-1 cold-boot fix), `services.php`/
   `config.php`/`.env`/`storage/installed`/`install-token.txt`/`tests`/`docs`/`node_modules` all absent
   (asserted directly).
3. **Cold HTTP boot:** the extracted artifact, booted with `php -S` and a minimal env (blank APP_KEY, no DB,
   no `artisan` first), returns **`GET /` → 302 → /install** — the exact fresh-host first visit.
4. **Tooling hardening:** `scripts/lib/cold-client.php` (the verify-release poller) gained a 30s overall
   deadline — a server that accepts-but-stalls now fails the acceptance test in ~30s instead of the previous
   60×5s = 5-minute hang.

**Env note (not a defect).** `verify-release.sh`'s full run does not cleanly *return* under `docker exec` in
this WSL/Docker setup because the backgrounded `php -S` is not reaped without a container init/PID-reaper; its
two halves (the filesystem assertions and the cold-boot 302→/install) were each verified directly here and pass.
Run the script as-is in a normal container/CI (it passed in a prior session — see PROJECT-STATE beta bundle).
`.env.example` (APP_NAME=NovFora, DB=novfora) and `docs/getting-started.md` (wizard flow, prebuilt assets,
no-SSH) are current.

### ADR-0070 — First-class subdirectory install (RH-4) (2026-06-16)
**Status: Accepted — owner-authorized build; flagged for review.** Implements the design spike
`docs/product/rh4-subdirectory-install-spike.md`.

> **ADR-number correction (do not skip).** The spike drafted this as "ADR-0038 (DRAFT)" on 2026-06-14, when 0037
> was the highest entry. Since then the mega-build + Phase-4 builds consumed ADR-0036…0069, and **0038 is now
> taken** (Sandboxed template editing). To honour the task's "confirm the next ADR number doesn't collide", this
> decision is **renumbered ADR-0070** (next free after 0069). The spike file is annotated accordingly; the design
> is unchanged.

**Context.** NovFora installs cleanly at a domain/subdomain root (docroot → `public/`) but not into a
**subdirectory** of a web root (`example.com/community`), a common shared-host need. The copy-`public/` workaround
(runbook §3b) breaks three ways: dual `public/build` trees drift → asset 404s + dead Livewire; route/Livewire/
`@vite` URLs don't carry the `/community` prefix **before any `.env`/`APP_URL` exists**; and storage publishes
into the app's `public/`, not the served subdir. These are structural (per `real-host-findings.md` §RH-4), so a
patch is insufficient.

**Decision.**
1. **Request-time base-path detector** (`App\Support\Http\BasePathDetector`, invoked from
   `AppServiceProvider::boot()`). It derives the mount prefix from `SCRIPT_NAME` (the directory of the resolved
   front controller — this is what `RewriteBase`/the web-server alias determines) and calls `URL::forceRootUrl()`
   + `URL::useAssetUrl()` **only when** `APP_URL` is unset/`localhost` **and** the prefix is non-empty. It is
   strictly **conservative**: a configured non-localhost `APP_URL` is **never** overridden, and a root/subdomain
   layout (empty prefix) is a **no-op** — so the root layout is untouched (**G4**). This guarantees correct
   `route()`, Livewire `getUpdateUri()`/`livewire.js`, and `@vite`/`asset()` URLs at `/community/install`
   **before** any `.env` exists (**G1**). It survives cached config (the prefix comes from the request, not config).
2. **Canonical home at the mount root** — see **ADR-0071**. So a subpath install serves the board list at
   `/community/`, not `/community/forums`.
3. **One canonical `build/` + `storage/`.** **Option A** (default): symlink `public_html/community` → `<app>/public`
   — one Vite manifest, one storage tree, **no drift** (**G2**). **Option B** (no-symlink hosts, **G3**): a thin
   generated `index.php` stub + `.htaccess` (`RewriteBase /community/`) in the web subdir, with `build/` + `storage/`
   served via per-folder symlink/Alias or the existing cron copy-mirror (`PublicStorageLinker::refresh()` +
   `NOVFORA_PUBLIC_LINK`). **Option C** (hardened copy) is the explicit last resort. The canonical `public/.htaccess`
   and `vite.config.js` are **deliberately unchanged** — no global `RewriteBase`, no Vite `base` — so the root
   layout and the single root-relative manifest stay intact (a baked Vite base would itself cause drift).
4. **Installer subpath awareness.** The wizard pre-fills Site URL with the detected subpath
   (`getSchemeAndHttpHost() . getBasePath()`); `InstallRunner` writes `APP_URL` and — when the URL carries a path —
   `ASSET_URL`, so post-install `asset()`/`@vite` and `Storage::url()` (the `public` disk url = `APP_URL.'/storage'`)
   resolve under `/community/…`. `config/app.php` gains `'asset_url' => env('ASSET_URL')` (null default → existing
   installs unchanged). The `RedirectIfNotInstalled` allowlist is **confirmed prefix-agnostic** — `Request::is()`
   matches path-info relative to the mount root, so `install`/`livewire-*`/`build/*` match under a prefix without
   change (resolves spike open-question #3).
5. **Install matrix** gains a subdirectory case, a **root-layout regression guard** (G4), and a **rebuild-drift
   guard** (G2).

**Consequences.** Subdirectory installs are first-class; the §3b copy recipe is demoted to last-resort and the
runbook documents Options A/B/C + a concrete Hostinger walkthrough. New surface: a small conservative bootstrap
detector and an A/B/C host matrix the install tests cover.
**Known limitation (deferred follow-up, recorded not built) — ✅ RESOLVED by ADR-0078 (2026-06-17):** the PWA
manifest + service worker still emit root-relative paths (`start_url`/`scope`/`/icons/`/`/build/`/`/offline`). Under
a subpath the SW simply fails to register (a caught no-op) → offline caching is off; **core forum browsing + the
installer are unaffected**. Not in any RH-4 unit or acceptance test; tracked as a fast-follow. **Also recorded:** post-install HTTP URL correctness for
the subpath relies on the web server reporting a correct `SCRIPT_NAME`/base path (Options A and B both do); the
detector intentionally does not force from a configured `APP_URL`.

### ADR-0071 — Canonical home = the forum index at the mount root (RH-4.1b) (2026-06-16)
**Status: Accepted — owner-authorized build; flagged for review.** Supersedes the RH-8 redirect direction.

**Context.** RH-8 made `/` a permanent 301 → `route('forums.index')` (= `/forums`) for one canonical forum URL.
RH-4 requires the forum index to **be** the home **at the mount root**, so a subpath install serves the board list
at `/community/` (and a root install at `/`) — not at `…/forums`. A `/` → `/forums` redirect would push every
subpath user to `/community/forums`, defeating "the mount root IS the home".

**Decision.** Move the **`forums.index` route NAME to `/`** (still `ForumController::index`), so every
`route('forums.index')` call (nav wordmark, breadcrumbs, canonical/OG, sitemap, "Back to forum") generates the mount
root automatically with **no per-view edits**. `/forums` (and therefore `/community/forums`) becomes a permanent
**301 → the root** for back-compat with the live beta's existing links + SEO. The **uninstalled** root still 302s to
`/install` (`RedirectIfNotInstalled` unchanged) — only the **installed** root changes from "301 → /forums" to
"serves the forum index". `RootRouteTest`/`ExampleTest` are updated to the new contract.

**Consequences.** One canonical home at the root (root or subpath); existing `/forums` links + already-indexed URLs
301 to it (search engines fold them into the root over time). The forum-index fragment cache (RH-9) is unaffected —
it keys on content, not the route URI.

### ADR-0077 — Classic board-index "Info Center" (statistics + who's online) (2026-06-17)
**Status: Accepted — owner-authorized UI/UX build; flagged for review.**

**Context.** The board index ended with a bare recent-activity feed and no at-a-glance "state of the community"
panel — the phpBB/XenForo/SMF **Info Center** long-time forum users expect. The pieces already existed
(`ForumStatsWidget`'s cached counts; the opt-in `OnlineMembers` presence service) but were never assembled into a
default board-index surface.

**Decision.** Add an **Info Center** as a **default** board-index surface, rendered just above the activity feed: a
**Statistics** panel (total posts / topics / members, posts today, newest member) + an opt-in **Who's Online** panel.
Backed by `App\Forum\InfoCenter`, a read-model that follows RH-9 cache discipline exactly like
`App\Community\ActivityFeed`: it caches **primitives only** (the aggregate counts + the newest member's id) under
`novfora:infocenter:stats` for 60s, and rehydrates the newest member with `User::find()` strictly **after** the cache
boundary — never an Eloquent model in the store (which would 500 on a serialising host's cache hit). Counts reuse
`ForumStatsWidget`'s shape; who's-online delegates to `OnlineMembers`, the single source of truth for the
`show_online_status` opt-in + recent window. "Posts today" counts from local midnight in the **app timezone**
(`Carbon::today()`), matching how `created_at` is stamped.

**Privacy.** Every figure is an **aggregate count** (no post content, no titles) — exposure is identical to the
existing `ForumStatsWidget`, so there is **no new privacy boundary and no hidden-forum leak**. Who's-Online stays
opt-in (default invisible); newest member is already public via the members directory. Baseline-safe: plain Eloquent +
the file/DB cache, no Redis/daemon and **no migration** (progressive-enhancement rule).

**Scope fences (deferred — recorded, not built).** The persisted "record online" high-water mark, guest counting, and
birthdays each need new tracking/schema and concurrency care; they are **out of scope** here and left as clean seams.

**Consequences.** A new default surface on the board index; one read-model service + one Blade partial, **no schema
change** (a trivial, reversible deploy). Two live presence queries per index render (count + recent), matching the
existing live widget's read path.

**Tested.** `tests/Feature/Forum/InfoCenterTest.php` (6 cases): the block renders on the index; counts match the
canonical model queries; newest member = the most-recently-registered ACTIVE user (banned excluded); posts-today
honours the app-tz day boundary; who's-online respects opt-in + window; and the cache holds primitives only with the
newest member correctly rehydrated. Pint + PHPStan L5 green.

### ADR-0078 — Subpath-aware PWA + raster install icons (2026-06-17, resolves the ADR-0070 PWA deferral)
**Status: Accepted — owner-authorized unattended build; flagged for review.**

**Context.** ADR-0070 (RH-4) made `route()`/`asset()`/`@vite`/Livewire subpath-correct under a subdirectory mount
but explicitly **DEFERRED** the PWA: the manifest hard-coded `start_url`/`scope` `'/'` + root-absolute icon paths,
and the service worker hard-coded `'/offline'` + `'/build/'`/`'/icons/'` prefixes and registered at `'/sw.js'` with
`Service-Worker-Allowed '/'`. Under a `/community` mount the SW therefore failed to register (a caught no-op) → no
installability + no offline cache. The manifest also shipped only an SVG + the favicon — no 192/512 PNGs, which
Android/Chrome want for the richest install prompt.

**Decision.**
1. **Manifest (`PwaController`):** derive the mount base once from `url('/')` (`""` at a root, `"/community"` under a
   mount); set `start_url` + `scope` = `base.'/'`; emit every icon src via `asset()` so it inherits ASSET_URL / the
   prefix. Add `icon-192.png` (192, any), `icon-512.png` (512, any), `maskable-512.png` (512, maskable) alongside the
   existing SVG (any maskable) + favicon.
2. **SW registration (layout head):** register the `route('pwa.service-worker')` URL WITH an explicit `scope = base.'/'`;
   `apple-touch-icon` via `asset()`.
3. **SW source:** derive `SCOPE = new URL(self.registration.scope).pathname` inside the worker (no server templating)
   and make `OFFLINE_URL` + the `build/`//`icons/` cache-first prefixes scope-relative; bump `CACHE_VERSION` to v2.
   The GET-only fence + the `X-PWA-Cacheable` page-cache gate are **UNCHANGED**.
4. **`Service-Worker-Allowed`** header = `base.'/'` so the SW may claim the whole mount, never the parent site.
5. **Raster icons:** `icon-192`/`512` rasterized from `public/icons/novfora.svg` (rounded-corner transparency, `'any'`);
   `maskable-512` flattened onto the brand blue `#2563eb` so it is full-bleed for the maskable safe zone. Committed to
   `public/icons/` (a tracked dir, unlike the gitignored `public/build`).

**Root no-op fence (G4-style).** At a domain/subdomain root the base path is empty, so `start_url`/`scope`/
`Service-Worker-Allowed`/`SCOPE` are all `"/"` and the registration scope is `"/"` — byte-identical to the
pre-ADR-0078 behaviour. Tested both ways.

**Consequences.** The PWA installs and the SW registers + caches offline under `/community/` as well as a root; the
ADR-0070 "PWA under a subpath is DEFERRED" limitation is **RESOLVED**. No new server templating in the SW (it reads
its own scope). Three small PNGs added (~15KB). No migration.

**Tested.** `tests/Feature/Pwa/PwaTest.php` (14 cases): root manifest/SW unchanged (`start_url`/`scope`/allowed `'/'`,
register scope `'/'`); 192+512+maskable PNG entries present; subpath manifest `start_url`/`scope` `'/community/'` with
prefixed icon srcs; subpath `Service-Worker-Allowed '/community/'`; the head registers the SW at `/community/sw.js`
with scope `/community/`; the SW source derives `SCOPE` from `registration.scope`. Pint + PHPStan L5 green.

### ADR-0079 — i18n view-string sweep, wave 1 (forum / members / profiles) (2026-06-17, extends ADR-0043 + ADR-0073)
**Status: Accepted — owner-authorized unattended build; flagged for review.**

**Context.** ADR-0073 externalized the auth + error surfaces and left the rest of the front-end / ACP view copy as
community-contributable residue. This wave begins the systematic sweep: replace literal English in the Blade views
with `__()`/`trans_choice` keys backed by `lang/en/<domain>.php`, **domain-by-domain**, so each ships as its own
reviewable, revertible commit. **Partial coverage is always correct** — a missing key falls back to the literal en.

**Decision.**
1. **Per-domain lang files + keys.** New `lang/en/forum.php` + `profiles.php` (joining auth/common/errors/
   search). The members-directory labels went into `common.php` (members/directory/top_members) — see the
   collision constraint below. Cross-cutting words live in `common.php` (delete/save/cancel/**edit**/forums).
   `en` is authoritative; other locales fall back per missing string (ADR-0043).
2. **Case-collision constraint (hard, learned the hard way).** A group file `lang/en/<name>.php` makes
   `__('<Name>')` (the capitalized string-key, ADR-0073 style) return the WHOLE group array on a
   **case-insensitive filesystem** (the Windows/macOS dev mount + the forum-dev gate): Laravel's FileLoader
   matches `<Name>.php` → `<name>.php`, so `__('Members')` loaded `members.php` and 500'd on
   `htmlspecialchars(array)` (ForumStatsWidget / clubs / nav all call `__('Members')`). **Rule:** never name a
   group file after a word that is (or is likely to be) used as a bare `__('Capitalized')` string-key. So the
   members labels live in `common.*`. `forum`/`profiles` groups are retained ONLY because a grep confirmed
   **no** bare `__('Forum')` / `__('Profiles')` caller exists — do not introduce one (use `__('forum.x')`). A
   guard test pins `trans('Members')` as a string.
3. **Byte-for-byte English.** Every new key carries a complete `en` value identical to the original literal **bytes**
   (curly apostrophes `’`, em-dashes `—`, ellipses `…` preserved); the broad existing feature suites + a per-domain
   guard test (keys resolve + a representative page renders English with **no raw `"<domain>."` token** — the
   AuthLangKeysTest pattern) protect against drift.
4. **Count suffixes stay static.** Where the board markup renders a number styled separately from an always-plural
   suffix word (`"3"` + `"replies"`/`"views"`/`"topics"`/`"posts"`), the suffix is a STATIC key, **not** trans_choice
   — trans_choice would change the n=1 output, violating byte-for-byte. Correct singular/plural is deferred. (New
   *combined* count strings, e.g. the Info Center's "N members online", DO use trans_choice, where it changes no
   existing output.)

**Scope / residue (recorded, honest).** Swept this wave: **forum** (8 views), **members** (2), **profiles** (2) —
a residue scan verified no remaining bare user-facing literals in them. Already done earlier: auth, errors, search.
**Residue (community-contributable, same pattern):** clubs (~25), settings (~20), notifications (~20), tags (~15),
pm (~12), the ACP `admin/*` (~150+), and the Livewire ⚡ components under `resources/views/components/**` (~370+, the
largest pool). Highest-value next: `components/`, then `admin/`, then clubs + settings.

**Consequences.** Three more localizable domains; **no behaviour change** (English output identical); no migration.
The per-domain guard-test pattern is established for the remaining domains.

### ADR-0080 — ACP v3: admin & permission management architecture (parent) (2026-06-18)
**Status: Accepted — owner-approved program (2026-06-18). Implementation lands per slice with child ADRs
(0081…), each gated + flagged for review.**

**Context.** NovFora has a phpBB-grade permission **engine** (ADR-0006: ALLOW / NO (neutral) / NEVER,
`RoleExpander`, `PermissionResolver`, `PermissionSync`, scopes global/forum/club) and a read-only
`PermissionInspector`, but the admin-facing **management UX** is minimal — permissions are shaped through seeded
presets and the v2 groups manager. The ACP v3 spec (`docs/product/acp-v3-spec.md`, 2026-06-10) designs the full
management layer but predates Clubs, the inspector, and ADR-0025, and proposes parallel tables that would risk a
second evaluation path. The reconciliation + slice plan is `docs/product/acp-v3-kickoff-refresh.md`; the locked
foundations (section taxonomy + the engine seam) are `docs/product/acp-v3-foundations.md`.

**Decision.** Build ACP v3 as a multi-slice program **on top of the existing engine**, under binding guardrails:
(G1) every new construct is stored as / expands into `acl_entries` and resolves through the one resolver — **no
parallel evaluation**; (G2) global / forum / **club** scope throughout; (G3) additive, reversible migrations;
(G4) `PermissionInspector` is the **correctness oracle** for every write-path test; (G5) apex security fences;
(G6) **Fable @ max** for any `acl_entries` / resolver / delegation slice; (G7) i18n `admin.*` from day one;
(G8) never name a `lang` group that case-collides with a bare `__('Word')` string-key (ADR-0079).

Settled product/architecture choices: **top-level = multiple co-owners** (no single Root, no transfer protocol)
protected by a **last-owner guard** (the `isSoleAdmin` locked-re-check pattern, ADR-0025); **inheritance = Global
→ Forum (+ Club)** with a bulk "apply to every forum in this category" action (**no category scope**);
**temporary-access delegation = a TTL on `acl_entries`** (additive nullable `expires_at` the resolver honours,
auto-expiring, ceiling-bounded, 30-day cap shown, cron-pruned); **group auto-promotion = a full AND/OR builder**
(promotion-only); **per-forum moderator capabilities = preset bundles + a custom path** (custom reuses the role
builder); the **ACP nav restructure** (Invision-style icon rail + per-section dashboards) is **in this cycle**,
sequenced as an independent track. A single **ACP section taxonomy** (foundations §3) is the shared contract for
the nav and the `admin.<section>.access` bundles.

The only change reaching the locked engine is additive: a nullable, indexed `acl_entries.expires_at` with a
single resolver filter (`expires_at IS NULL OR > now`), a cron prune that bumps `AclVersion`, and extended
truth-table / inspector coverage. **The filter is authoritative** — a lagging sweep never honours an expired
grant. Because a grant lapses by wall-clock (which is no write, so it bumps no `AclVersion`), the cached `can()`
Gate path is held to the same guarantee by capping its cache horizon to the earliest contributing TTL — so a
resolved verdict self-expires exactly when its grant does, with no dependence on the prune cron (apex-review
finding, v3-0). The delegation **ceiling invariant** (recipient never exceeds the delegator's current mask;
co-owner never delegable) is the apex test of the delegation slice.

**Slice program (child ADRs, validated against migration dependencies before each locks):** v3-0 foundations +
the `expires_at` seam (this ADR) → **v3-h** nav shell + IA (ADR-0081) → **v3-c** card-per-group editor
(ADR-0082) → **v3-e** group system (AND/OR) → **v3-d** custom roles → **v3-b** moderator assignment → **v3-a**
co-owners + Admin Manager + bundles → **v3-f** delegation → **v3-g** staff flair / roster.

**Consequences.** The engine and inspector remain the single source of truth, changed only by the additive
`expires_at` seam; everything else is additive surface + projections. Old ACP routes 301 to their new section
homes. The nav restructure ships in-cycle but decoupled, so a slip never blocks the permission features.
Per-section access keys arrive with the bundles slice (v3-a); until then the new rail is visible to any admin and
the Security section houses the existing inspector under its current gate.

**Alternatives considered.** (a) Implement the spec verbatim with parallel `admin_permissions` / moderator
evaluation — rejected (violates G1, splits the security-critical path). (b) Single founder Root + 4-rail transfer
protocol — rejected by owner for multiple co-owners. (c) A true category permission scope — deferred
(engine / `ScopeChain` change; bulk apply-to-category covers the ergonomics). (d) Resolver-overlay delegation —
rejected for TTL-on-`acl_entries` (one eval path). (e) Ship as one monolith — rejected (unreviewable).

**Scope fences.** No multi-tenant admin model; no marketplace/payments admin; a true category scope is deferred.
The nav restructure (v3-h) **is** in scope (owner decision).

**References.** `docs/product/acp-v3-foundations.md`, `docs/product/acp-v3-kickoff-refresh.md`,
`docs/product/acp-v3-spec.md`.

### ADR-0081 — ACP v3 · v3-h: Invision-style icon-rail IA + per-section dashboards + 301 route moves (2026-06-18)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, flagged for review.**

**Context.** The ACP was a flat route-per-page list inside one grouped left sidebar (`AdminNavigation::groups()`).
The foundations §3 taxonomy locks an Invision-style information architecture (icon rail → section sidebar →
per-section dashboard) shared with the v3-a `admin.<section>.access` bundles. v3-h delivers that chrome and
re-homes the existing pages into their sections, decoupled so it never blocks the permission slices.

**Decision.** Rebuild the ACP shell clean-room (no copied markup) as three panes: an **icon rail** of the eleven
sections (Overview · Forums · Members · Groups · Moderation · Appearance · Plugins · Analytics · Settings ·
System · Security) → the active section's **sidebar** of sub-page clusters → the page content. Each section gets a
**dashboard landing** (`admin.<section>`, one invokable `SectionController` + a shared `admin.section` view) that
starts with the section summary and grows widgets as features land. A global **ACP search** (`admin.search`)
spans pages + settings + members (the sidebar keeps the instant client-side page/settings filter; Enter runs the
server search that adds member lookup). `AdminNavigation` is the single source for the rail, the per-section
sidebars, active-section detection, and the search index, so the nav can never drift from the routes.

**Page re-homing.** Every moved page keeps its **route NAME stable** (so every `route()` call-site and most tests
are untouched) and only its URL changes to sit under its section; the OLD URL **301s** to the new home via a bare
`Route::redirect` (a `RedirectController` route, excluded from the authz-walk and covered by a dedicated 301
test). The single exception is the Permission Inspector, which moved System → **Security** and was therefore
renamed `admin.system.permissions` → `admin.security.permissions` (its five call-sites updated); it stays under
its **existing** gate (co-owner gating arrives in v3-a).

**Guardrails.** PURE UI — **no new permission keys** this slice (the rail is visible to any admin via the existing
`admin.access` gate); feature pages stay shell-agnostic. i18n from the first commit under a single **`admin.*`**
group (+ shared `common.*`); the G8 collision check confirmed no bare `__('Admin')` string-key exists, and the
section labels live as `admin.sections.*` keys (never standalone `forums.php`/`members.php` group files). The icon
rail is a keyboard-operable `nav` landmark with `aria-current` + visible focus rings.

**Consequences.** Old admin bookmarks 301 to the new section homes; internal links are unaffected (names stable).
The authz-walk render-mirror visits every admin page through the new shell (all 200). Member search links to the
member's profile until the dedicated member-management surface lands in a later slice.

**Alternatives considered.** (a) Restructure only the nav, leave URLs — rejected (the spec requires the pages to
move + 301). (b) Move URLs *and* rename every route to its section — rejected (needless call-site/test churn;
"keep names stable where you can"). (c) Per-section `lang` group files — rejected (G8 case-collision risk).

### ADR-0082 — ACP v3 · v3-c: card-per-group permission editor (global / forum / club) + category bulk-apply (2026-06-18)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed, flagged for
review.**

**Context.** The headline "simple mode": the engine has fully supported group ACL since ADR-0006, but admins
could only shape permissions through seeded role presets and the v2 groups manager — there was no plain-language
editor. v3-c adds a card-per-group editor with **three homes on the SAME `acl_entries` data**: GLOBAL defaults
(Groups → Group permissions), per-FORUM overrides (Forums → forum → Permissions), and per-CLUB (the club manage
screen).

**Decision.** A `GroupPermissionEditor` service writes a group's OWN entries DIRECTLY (holder_type='group') at the
chosen scope — **not** via `RoleExpander` (which expands ROLE presets; this edits the group's standing entries).
Three UI states: **Yes = ALLOW (+1)** · **No = delete the row** (→ inherit; never a value=0 row) · **Never =
NEVER (−1)**. One Livewire SFC (`permissions.group-editor`, `#[Locked]` scope) serves all three homes; the
PermissionInspector is the test oracle. The **category bulk-apply** (foundations §4, option A) copies a source
forum's group overrides onto every forum under its parent category in ONE transaction, audited — the phpBB
"copy permissions" ergonomic without a category scope. `acl_entries` model events bump `AclVersion`
automatically; the 'no' branch uses a query-builder delete (which skips the event) so it bumps by hand.

**Apex security fences (the adversarial review found and these close two HIGH issues).** (1) **Gate:** global /
forum require `admin.access` + staff-2FA + the **manage-permissions capability** (`permissions.manage`); club
requires `club.manage` on that club — re-asserted on every action (Livewire actions skip route middleware).
(2) **Rank guard:** you cannot edit a group ranked at/above you (admins bypass; `rankPriority()` vs the target
group's `priority`). (3) **Per-key escalation fence (review HIGH #1):** only a full admin may grant/deny an
**Administration-tier** key (`admin.*`, `permissions.manage`, `users.manage`, `groups.manage`) — without it a
non-admin `permissions.manage` holder could hand admin access to a group it merely outranks. (4) **Self-lockout
guard (review HIGH #2):** the service refuses (throws; the SFC 403s) to strip the system **admins** group's own
`admin.access` / `permissions.manage` at global scope — that would brick all ACP access with no in-app recovery
(the last-owner-guard pattern; the full board-wide co-owner guard is v3-a). (5) `#[Locked]` scope +
key-visibility check (a forum/club editor exposes only forum-scoped keys); the bulk write is admin-only and
excludes club forums.

**Consequences.** The editor edits a group's standing entries — including the rows the seeded role presets wrote
(editing the members group's `forum.view` modifies that preset row). The full board-wide "≥ 1 admin path" guard
belongs to v3-a (co-owners); v3-c guards the system admins group's recovery keys as the interim fence. Inheritance
is shown per row (a forum/club "No" surfaces its global default). NEVER stays absolute and priority-independent.

**Verification.** Inspector-oracle tests across global / forum / club; No-removes-the-row + inheritance; NEVER
beats a higher-priority Yes; category bulk-apply writes every child forum; 403 for a non-permission-admin; the
rank guard; the escalation fence; the self-lockout guard (SFC + service backstop). A 4-lens APEX adversarial
verify-then-refute review ran before commit; its two HIGH findings are fixed and pinned by tests.

**Alternatives considered.** (a) Route writes through `RoleExpander` — rejected (it expands role presets, not a
group's own entries; would split the write path). (b) A true category permission scope — deferred (engine /
`ScopeChain` change; the bulk-apply covers the ergonomic). (c) Leave the escalation/lockout fences to v3-a —
rejected (the editor ships a write path now, so it must be safe now).

### ADR-0083 — ACP v3 · v3-e: group system — membership models + AND/OR auto-promotion + the membership-cache seam (2026-06-18)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed (the cache seam),
flagged for review.**

**Context.** The v2 groups manager had custom groups + a flat (all-AND) trust-level auto-promotion floor (Stage-A
A3, `TrustLevelManager`) but no per-group **membership model** (how humans join), no general **AND/OR**
auto-promotion, no public Groups page, and no primary-group chooser. The headline gap: a group is a permission
HOLDER, so adding/removing a user from a group changes their effective permissions WITHOUT touching `acl_entries`
— and pivot writes (`attach`/`detach`/`syncWithoutDetaching`/`updateExistingPivot`) fire no Eloquent model events,
so nothing invalidates the resolver caches automatically. This is the sibling of guardrail G9.

**Decision.** Build v3-e on the existing engine, additive + reversible:
- **Membership models** — `groups.membership_model` ∈ {`admin` (unchanged default), `request` (a moderated
  `group_join_requests` approval queue), `open` (a public Join button)}. Auto-promotion is ORTHOGONAL (the system
  can add a user to any model's group), driven by a non-empty `auto_promotion` rule tree. `GroupMembershipService`
  mirrors `ClubMembershipService` (request → approve/deny, open join, leave); every self-service join passes
  `GroupJoinGate` (verified + active + not-banned) so a banned/restricted/unverified account can't bypass new-user
  restrictions. System + trust groups are never self-joinable.
- **AND/OR auto-promotion** — `GroupAutoPromoter` generalises A3's flat floor to an arbitrary `{op:AND|OR, rules:[
  {criterion:posts|tenure_days|trust|reputation, gte:N} | <nested node>]}` tree. **Promotion-only** (never
  detaches/demotes), **idempotent** (`syncWithoutDetaching` + the already-member skip), **custom groups only**
  (trust groups stay owned by `TrustLevelManager`). **Back-compat:** the legacy flat `{min_*}` shape still
  evaluates (normalised as one AND node). Evaluated by a new hourly cron `novfora:groups:auto-promote` (the
  authoritative catch-up + the only path that crosses the time-based `tenure_days` bar) and eagerly by queued
  listeners on the criterion-moving events (`PostCreated`/`TopicCreated` → post count, `ReputationAwarded` → rep),
  mirroring the badge-award wiring. Malformed/unknown nodes fail-closed (never spuriously promote).
- **Public Groups page** — a public `GET /groups` directory listing ONLY `is_public` groups (per-group flag, OFF
  by default → the page and nav link are empty/hidden until an admin opts a group in). It exposes name,
  description, and member COUNT only — never a roster, never a hidden/non-public group.
- **Primary-group chooser** — a user picks their primary from groups they belong to; an admin override sets +
  LOCKS it (`group_user.is_primary_locked`), and the member's self-service change is refused while locked; an
  admin can unlock. Primary is COSMETIC (resolution reads ALL group memberships, never just the primary), so it
  needs no cache invalidation.

**Apex fence — the membership-cache seam (`MembershipCache`, G9's sibling).** Every join / leave / promote /
auto-promote / approval / admin-assign calls `MembershipCache::flushFor($user)`, which (1) reloads the user's
`groups` relation so the next `groupSignature()` reflects the new set — re-keying the cross-request resolved-verdict
cache for exactly that user; (2) flushes the per-request `PermissionResolver` memo (keyed user|perm|scope WITHOUT
the signature); (3) flushes the `VisibleForumIds` memo. The cross-request cache key embeds BOTH `AclVersion` AND the
signature, so the two ways a verdict changes are both covered: an `acl_entries` write bumps the version; a membership
change moves the signature. **Version-bump policy (refined after the adversarial review):** the ADDITIVE hot paths
(join / approve / auto-promote) do NOT bump — the per-user signature scopes the invalidation, and a global bump on
every auto-promotion during the hourly cron sweep would cold-start every other viewer's cache (the herd the signature
design exists to avoid). But `groupSignature()` is a pure, non-monotonic function of the id-set, so a REDUCTION/SWAP
(leave / remove / delete-reassign / trust demotion) can land a user back on a previously-cached signature; those rare
paths pass `bumpVersion: true` as defence-in-depth — harmless on the membership+ACL axes (the version prefix already
re-keyed on any ACL change) but it also dominates a recurring signature on orthogonal axes (e.g. a cached ban/status
verdict) and is robust to any future non-bumping write. `TrustLevelManager` and `GroupManager`'s membership paths
were routed through the same helper (consolidation — `TrustLevelManager` had only the inline memo flush, never the
relation refresh or `VisibleForumIds`); trust bumps only on an actual level change (an unchanged recompute stays on
the cheap signature path). The bulk reassign-and-delete path uses `flushRequestScopedMemos(bumpVersion: true)`.

**Consequences.** Auto-promotion reads the denormalised `users.post_count`/`reputation_points`/`trust_level`/
`created_at` (the criteria-source the kickoff names); event-driven promotion fires only on APPROVED content. The
builder UI edits a single-level AND/OR tree (the engine also evaluates nested trees — reachable via import/future
advanced UX). Demotion is intentionally out of scope (promotion-only, like A3). The full board-wide co-owner guard
remains v3-a. No new permission scope; no engine read-path change (the resolver is untouched).

**Verification.** Inspector-oracle tests (G4): a raw pivot attach WITHOUT the seam leaves the memo stale (the
hazard), and each real path (open-join, auto-promote, approve, admin-assign, leave) makes the inspector verdict
flip on the SAME instance immediately, plus the cross-request cached `can()` path. AND/OR semantics (AND/OR
branches, legacy-flat still promotes, promotion-only keeps a now-unqualified member, idempotent re-run = 0,
never touches a trust group). Membership models (open seats; non-open refused; banned/suspended/unverified can't
join; request→pending→approve adds, deny doesn't; re-request reuses one row; non-manager can't approve; leave
only on self-service). Public page (lists public only, hides a hidden group, never leaks a roster, gated join).
Gate: `php artisan test --parallel` · `pint` · `phpstan` L5 · `migrate` apply **and** rollback (down() drops the
columns/table; re-applies clean). A targeted adversarial verify-then-refute review ran on the cache seam.

**Alternatives considered.** (a) Bump `AclVersion` globally on every membership change — rejected (cache-thrash on
the auto-promote cron sweep; the per-user signature already invalidates precisely). (b) Fold custom-group
auto-promotion into `TrustLevelManager` — rejected (trust has single-membership + demotion + TL4-manual semantics
that custom groups don't; shared the cache seam instead, not the evaluator). (c) `membership_model` as a 4th value
including `auto` — rejected (auto-promotion is orthogonal to how humans join; modelling it as a join model would
forbid an open group from also auto-promoting). (d) A global "enable public directory" setting — deferred (the
per-group `is_public` flag, default off, already makes the page empty/hidden until opted in).

### ADR-0084 — ACP v3 · v3-d: custom role builder — convergent re-expansion + the escalation/self-lockout fences (2026-06-18)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed (convergence +
escalation seam), flagged for review.**

**Context.** Roles are reusable bundles of three-state values that EXPAND into `acl_entries` on assignment
(ADR-0006 / `RoleExpander`) — they are not a second evaluation layer. The v2 codebase seeded four read-only system
presets (administrator / moderator / member / guest) but had **no admin surface to build custom roles**, and
`RoleExpander::reexpand()` only ever UPSERTED: a key DROPPED from a role lingered on every assigned holder as a
stale grant (no convergence). Two apex hazards sit here: (1) the **escalation** vector — a non-admin
`permissions.manage` holder minting/assigning an Administration-cluster key is the v3-c-class HIGH; (2)
**self-lockout** — stripping the admins group's own `admin.access`.

**Decision.** Add a Groups → **Roles** builder (`/admin/groups/roles`, `<livewire:admin.roles>`) on the existing
schema — NO migration. A "custom role" is simply `roles.is_preset = false`; presets (`is_preset = true`) are
READ-ONLY (their permission set seeds the engine and defines the staff groups). The builder is a name + a
Yes/No/Never grid over the permission catalog, grouped into clusters by the catalog `group` field. All domain
logic lives in `App\Permissions\RoleManager` (the SFC is UI + self-guard); permission keys carry dots, so the grid
uses `setValue(key, state)` actions, not a dotted `wire:model` path.
- **Assignment** — a built role applies as a CUSTOM group's baseline via `RoleManager::assignToGroup` →
  `RoleExpander::assignToGroup` (expands at global scope). System groups are refused (their permissions are their
  identity). Per-forum mod-capability sets (v3-b) and admin bundles (v3-a) are future CONSUMERS of the same role
  model — kept general here, not built.
- **Re-expansion convergence (the correctness core).** `reexpand(Role, array $droppedKeys)` now upserts the
  current keys onto every assignment AND deletes the caller-supplied dropped keys at each assignment's scope;
  `retract(Role)` removes a role's whole footprint on delete. `RoleManager::save` captures the pre-edit key set,
  computes `dropped = old − new`, and converges every holder; `delete` retracts everywhere, drops the assignments,
  and removes the role. Query-builder deletes skip the `AclEntry` model event (G9), so the policy layer bumps
  `AclVersion` once per operation.

**Apex fences (enforced in the service as the actor-independent backstop; the SFC pre-checks for a clean 403 —
mirrors the v3-c `GroupPermissionEditor` pattern).**
- **Escalation.** The Administration cluster (catalog `group == 'Administration'`: `admin.access`,
  `admin.settings`, `users.manage`, `groups.manage`, `prefix.manage`, `badge.manage`, `permissions.manage`) may
  only be put in / assigned with a role by a FULL admin (`User::isAdmin()`). Independently, the **ceiling**: an
  ALLOW may only name a key the actor themselves hold at global (`canDo(key, global)`); NEVER is a restriction, not
  an escalation, so it is ceiling-exempt (but still admin-tier-fenced). The fence re-runs on assignment (against
  the role's CURRENT keys) and — after the adversarial review — on the destructive SFC actions (unassign / delete),
  so a non-admin can neither mint nor tear down an admin-tier role.
- **Self-lockout.** `save` keeps the admins group's recovery keys (`admin.access` + `permissions.manage`) ALLOW
  whenever the edited role is the admins baseline; `delete` / `unassignFromGroup` refuse to strip those keys from
  the admins group at global (actor-independent backstop, matching `protectsAdminRecovery`). In practice the
  dangerous precondition (a custom role on the admins SYSTEM group) is unreachable through the UI — assignment
  refuses system groups — so these guards are defence-in-depth for a hand-built / future-consumer state.

**Provenance caveat (the MEDIUM the review surfaced, scoped not fixed).** `acl_entries` has no `role_id`. Deletion
is KEY-SCOPED — only the named dropped/role keys are removed, so a grant on a DIFFERENT key at the same
(holder, scope) is never collaterally touched. But a key that is BOTH in a role AND independently set by the
**card editor** (v3-c) on the same (group, scope) is ONE physical row: removing the role removes it (last-writer
wins). **Operational rule:** a group is managed by a role baseline OR the card editor on a given key, not both. A
`role_id`/refcount provenance mechanism would also have to refactor v3-c — deliberately out of v3-d scope.

**Consequences.** No schema change; the gate's migrate apply+rollback exercises the existing reversible chain.
Custom roles expand at GLOBAL scope (a group baseline); scoped role assignment is a later-slice concern. The
builder edits a flat key→state map; the engine stores ALLOW/NEVER rows and omits NO (inherit). Editing a role
converges on EVERY assigned holder in one transaction; cache correctness rests on the single `AclVersion` bump.

**Verification.** Inspector-oracle tests (G4): a built role's verdict appears at the assigned scope; an ADDED key
appears on holders; a DROPPED key DISAPPEARS and its `acl_entries` row is gone (not merely shadowed) with the
version bumped; a co-grant on a different key SURVIVES the drop (surgical); a baseline SWAP converges; the
escalation fence blocks a non-admin minting an admin-tier key; the ceiling refuses an over-grant but admits a held
key and exempts NEVER; system presets can't be edited or deleted; deleting an assigned role retracts everywhere +
bumps `AclVersion`; the self-lockout guard holds on edit AND on delete/unassign; a non-admin is 403'd from
setting / assigning / unassigning / deleting an admin-tier role. Gate: `php artisan test --parallel` · `pint` ·
`phpstan` L5 · `migrate` apply **and** rollback. An apex adversarial verify-then-refute review ran on the
convergence + escalation seam (found 1 HIGH — self-lockout missing on the destructive paths — and 1 MEDIUM — the
provenance caveat; the HIGH was fixed + pinned by tests before commit, the MEDIUM scoped above).

**Alternatives considered.** (a) Add a `roles.type` enum to distinguish custom from preset — rejected (`is_preset`
already does, and consumers v3-b/v3-a will add their own discriminator if needed; no migration is cleaner).
(b) Reuse `GroupManager::setRole` for assignment — rejected (it deletes a group's `acl_entries` across ALL scopes,
clobbering card-editor forum overrides; v3-d's swap is surgical at global). (c) Full-replace convergence (delete
every key at the holder/scope, then re-expand) — rejected (clobbers card-editor co-grants on other keys; the
`old − new` diff is surgical). (d) Block role deletion while assigned — rejected in favour of delete-with-cleanup
(better UX; exercises the convergence machinery; no orphaned grants either way).

### ADR-0085 — ACP v3 · v3-b: per-forum moderator assignment — projector + the forum-scope grant-only / ceiling / rank fences (2026-06-19)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed (verify-then-refute;
1 finding fixed + pinned), flagged for review.**

**Context.** Moderation was global-only: the `moderators` system group holds the `moderator` preset at global
scope. v3-b adds PER-FORUM moderators — an admin assigns a user or group to moderate one forum with a capability
set — as a **projector slice** that adds NO new evaluation path (G1): it stores into / expands into `acl_entries`
and resolves through the existing `PermissionResolver`, exactly like `ClubRoleProjector`. The hazards: delegating
capabilities the actor doesn't hold (ceiling), elevating a same-or-higher-ranked target (rank), minting an
Administration-tier power as "moderation", and — surfaced by the apex review — minting forum-scope HARD-DENIALS
via a NEVER-valued custom role.

**Decision.** A `moderator_assignments` source-of-truth table (`holder_type`/`holder_id`, `forum_id` FK,
`role_id` FK NULLABLE **XOR** `bundle` slug, UNIQUE per holder+forum) + `App\Permissions\ForumModeratorProjector`
(`assign()`/`revoke()`, mirrors `ClubRoleProjector`) that expands the assignment into **forum-scope** `acl_entries`
via `RoleExpander::assign(role, holderType, holderId, Scope::forum())`. Three seeded preset bundles
(`forum-mod-full` / `-content` / `-queue`) as `is_preset` roles that — unlike `RoleSeeder` — are NOT expanded onto
any group; only the projector expands them, at forum scope, on demand. The custom path reuses the v3-d builder
(assign any `is_preset=false` role). Surfaces: a per-forum **Moderators** tab (`admin.forums.moderators`, a 3rd
structure-tree button beside Inspector + Permissions) and a global **Moderation → Moderators** pane.

**Apex fences (in the projector — the actor-independent backstop; the SFCs self-guard `admin.access` +
`permissions.manage` + staff-2FA for a clean 403).**
- **Grant-only (the review's finding).** A forum-mod role only ever GRANTS — `assign()` refuses any non-ALLOW
  (NEVER) value. A NEVER would mint a forum-scope hard-deny (e.g. `forum.view:NEVER` on a group) that the ceiling
  cannot catch (NEVER is ceiling-exempt by design) and that re-expands onto live holders on a later role edit. All
  bundles are ALLOW-only; custom roles must be too here.
- **Admin-tier refusal.** No Administration-cluster key (`admin.access`, …) may be a forum-mod capability,
  regardless of the actor — stricter than the v3-d ceiling (which only blocks non-admins).
- **Ceiling at FORUM scope.** Reuses `RoleManager::assertWithinCeiling`, now scope-parameterized (`?Scope`,
  default global = backward-compatible for the v3-d callers); the actor may only grant keys they can exercise on
  THIS forum (`canDo(key, Scope::forum())`).
- **Rank.** `ActorRank::canActOn` for USER holders — a non-admin can't elevate a same-or-higher-ranked user.

**G10 — key-scoping (the provenance caveat, honored not refactored).** `acl_entries` has no `role_id`; forum scope
is co-managed by the v3-c card editor. The projector is the SOLE manager of a moderator's forum-scope rows and uses
KEY-SCOPED deletes ONLY (the assigned role's own keys at the (holder, forum) cell) and drops the specific
forum-scope `RoleAssignment` on revoke / re-assign, so a later role edit's `reexpand` can never re-grant a revoked
holder. `bans.manage` rides in the full bundle for completeness but its catalog `scope_kind` is GLOBAL, so its
forum-scope row is INERT at resolution (bans stay global) — flagged in the bundle definition.

**Consequences.** Additive reversible migration (G3); the gate exercises apply+rollback+re-apply. No change to the
global `moderators` group or the `moderator` preset — the pre-v3 global-moderation suite is byte-identical. No
category scope (deferred, ADR-0080). **Deferred follow-up:** the per-user "Moderation" tab on the member-edit
screen (spec §4) — not built this pass.

**Verification.** Inspector-oracle (G4) at forum scope (7 + the review case): a USER grant → `user_allow`; the same
user DENIED on a different forum (scope isolation); a GROUP grant → `group_allow`; a preset bundle expands EXACTLY
its keys; **revoke** deletes the rows + flips to denied + bumps `AclVersion`; the **rank** guard refuses a
≥-ranked target; the **ceiling** refuses an unheld key and `admin.access` can't be a mod capability; **grant-only**
refuses a NEVER (nothing written). SFC tests pin the page/mount gate + assign (user + group) + revoke + the
ceiling throw surfacing as a flash. Gate: `pest` (1775/1777 — the 1 fail is the pre-existing v3-e `HotPathQuery`
budget, unrelated: v3-b touches no topic-render path) · `pint` · `phpstan` · `migrate` apply+rollback+re-apply. An
apex adversarial verify-then-refute review (security / integrity / concurrency lenses) ran before commit: 1 finding
(the NEVER forum-scope hard-deny vector) — fixed + pinned by a test; no other finding survived refutation.

**Alternatives considered.** (a) Mirror `ClubRoleProjector`'s direct `AclEntry` writes (no `RoleExpander`) —
rejected: the spec wants `RoleExpander` so a custom role's edit re-expands onto its forum moderators (convergence);
the cost (managing the forum-scope `RoleAssignment` on revoke) is contained. (b) Whole-(holder, forum) acl wipe on
revoke — rejected (clobbers card-editor co-grants; G10 key-scoped). (c) Store a preset by `role_id` instead of the
`bundle` slug — rejected (the slug is stable across re-seeds and cleanly distinguishes preset from custom). (d)
Allow NEVER in a mod role — rejected (the review finding; moderation is a grant surface, not a denial surface).

### ADR-0086 — ACP v3 · v3-a: co-owners + Admin Manager + per-section access bundles — the last-owner guard, the restricted-admin model, and per-section rail gating (2026-06-20)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed (two verify-then-refute passes; 3 findings fixed + pinned), flagged for review.**

**Context.** Every admin today is an `admins`-group member holding the flat `administrator` preset — no owner tier, no per-section scoping. v3-a adds the top tier: **multiple co-owners** (no single Root, no transfer protocol; ADR-0080) protected by a **last-owner guard**; an **Admin Manager** that grants an individual user a *subset* of ACP sections; and the ten `admin.<section>.access` keys that finally gate the Invision rail per-section (v3-h shipped it `admin.access`-flat and deferred this here). Additive (G1, no new eval path) and reversible (G3). The hazards: stranding the forum at zero co-owners; a restricted admin escalating to full admin; a co-owner's grant or a restricted admin's grants colliding on the same `acl_entries` rows (G10).

**Decision — section keys (step 1).** Ten `admin.<section>.access` keys in the catalog's Administration cluster (global), one per rail section. The `administrator` preset gains the NINE non-security keys additively; `admin.security.access` is co-owner-only. `PermissionSync` (add-only) propagates the nine to existing installs with no sync-code change, so no current admin loses the rail (`AdminAccessWalkTest` is the guard).

**Decision — co-owners (steps 2–3).** An additive, reversible `is_co_owner` pivot column on `group_user` (+ index) — a TIER marker that never feeds the resolver. The installer crowns the first admin (flag + a per-user `admin.security.access` grant). `App\Admin\AdminCoOwnerService` keeps the two COUPLED facts in lockstep: `grant()` (additive, idempotent, ceiling-irrelevant, actor-must-be-co-owner backstop) and `revoke()`/demote, whose FIRST act inside the transaction is `assertNotSoleCoOwnerLocked()` — a `lockForUpdate` re-read of the admins-group co-owner set, exactly mirroring `AccountDeletionService::assertNotSoleAdminLocked` (ADR-0025): two concurrent removals serialise, the first commits, the second sees the lone owner and aborts. Query-builder delete of the security grant + explicit `AclVersion::bump` (G9); `MembershipCache::flushFor` for the memos.

**Decision — Admin Manager / restricted admins (step 4, the G10 fork RESOLVED).** A full admin inherits every section via the `admins`-group preset (group-holder rows), so a per-user grant could only ADD, never SUBTRACT. **A restricted admin is therefore NOT in the `admins` group**; they hold the umbrella `admin.access` + their chosen `admin.<section>.access` keys as PER-USER global grants — DISJOINT rows from the preset. `App\Admin\AdminBundleService` only ever writes/deletes USER-holder rows for {`admin.access` + the nine non-security keys}, never a co-owner's `admin.security.access` and never a group row. Bundles (`AdminBundleSeeder`, `is_preset` roles, NOT group-expanded): Full / Community / Content / Style / Analytics / Custom(blank) — "starting points" `assign()` converges onto the user, then `setSectionAccess()` toggles one, `revoke()` strips all. Every GRANT is ceiling-checked (`RoleManager::assertWithinCeiling`): the section keys are Administration-tier, so only a FULL admin holding the key may grant it — a restricted admin (`isAdmin()===false`) can never assign or escalate. The destructive paths (`revoke`, `setSectionAccess` revoke branch) are full-admin-gated too (the review finding below).

**Decision — Security SFCs + per-section gating (steps 5–6).** Two Livewire SFCs (Co-owners + Admin Manager) under Security, each re-asserting `admin.security.access` in `mount()` AND every action (Livewire actions bypass route middleware — the v3-c/v3-d backstop discipline); wrapper views use the `@extends` envelope (no BUG-001 bare shell). `AdminNavigation::canAccessSection()` is the single per-section check honoured by the rail, the sidebar, and the search index; `SectionController` (nine landings) and the Analytics SFC re-assert it for direct URL loads. `overview` stays the any-admin home; the flat `admin.access` route middleware is unchanged (co-owners hold it).

**Apex correctness seams.**
- **Last-owner guard, SYSTEM-WIDE.** The apex review found the invariant was enforced only in `revoke()`, while two OTHER doors dropped the admins membership / co-owner flag unguarded: `AccountDeletionService` (deleting the sole co-owner when other admins remain → permanent zero-owner strand) and `GroupManager::removeMember` (detaching the sole co-owner from admins). BOTH now run the same locked guard — the deletion cascade gained a co-owner sibling of its sole-admin guard (+ an `isSoleCoOwner` UI pre-filter); group removal routes a co-owner teardown through the service (refuses the sole owner, clears the orphan-prone grant in lockstep), surfaced as a `GroupException`. The admin-forced delete path is additionally pre-blocked by the existing rank guard (no admin may force-delete an equal-rank admin).
- **G10 escalation fence.** `isAdmin()` is GROUP-based, so it is `false` for a restricted admin: `EnsureSystemPanelAccess` (key-based `canDo('admin.access')`) still ADMITS them, while `assertWithinCeiling`'s admin-tier rule (which requires full `isAdmin()`) correctly EXCLUDES them from minting Administration-tier keys — verified end to end.
- **Security-by-default (2FA).** Decoupling `admin.access` from the staff group surfaced a gap: `RequireTwoFactorForStaff` keyed on `isStaff()`, so a restricted admin (not in a staff group) could reach the panel with no second factor. It now requires 2FA for anyone who can reach the panel (`isStaff()` OR `canDo('admin.access')`). **Flagged:** this is a security-by-default call beyond the literal spec; the residual narrow vector (a crafted Livewire action against an admin SFC a restricted admin can reach, whose `mount()` 2FA check is still `isStaff()`-keyed) is noted for a follow-up sweep. Also note: a non-co-owner full admin no longer sees the Security section (the Permission Inspector now lives behind the co-owner gate) — the spec's deliberate "Security = co-owner-only" choice.

**Consequences.** One additive reversible migration (G3; the gate exercises apply+rollback+re-apply). No change to the resolver / `acl_entries` schema (G1/G2). Existing admins keep the full non-security rail (preset + PermissionSync; `AdminAccessWalkTest` crowns its operator a co-owner and walks the whole panel). Bundles are seed-only (mirroring v3-b's `ModeratorBundleSeeder`); the Admin Manager degrades gracefully via per-key toggles where a preset is absent on an upgrade. **Deferred:** v3-f delegation (the `expires_at` TTL) is the next slice.

**Verification.** Inspector/resolver-oracle (G4) across co-owner grant/revoke (flag + grant in lockstep, the verdict flips, `AclVersion` bumps), the last-owner TOCTOU (the locked guard re-reads live state; genuine last-of-two completes then the new sole owner is protected; the deletion + group-removal doors refuse the sole owner), bundle assign/replace/revoke + the per-key toggle, the G10 fence (a restricted admin can neither assign nor mint), per-section rail + landing gating (full admin = all-but-Security, co-owner = all, restricted = granted subset only, 403 by direct URL), restricted-admin 2FA, and the two SFC gates (page + action, co-owner only) + the appoint/remove + make-restricted-admin wiring. Two apex adversarial verify-then-refute reviews ran before commit (co-owner service: 21 candidates → 2 HIGH cross-path strand bugs fixed + pinned; bundle service: 16 candidates → the destructive-path actor backstop fixed + pinned; all other candidates refuted). Gate: `pest` (1814 pass / 1 skip / **1 pre-existing fail** — the `HotPathQuery` topic-budget that polish-R2 / PR #35 fixes; v3-a touches no topic-render path, the count is the inherited 42) · `pint` · `phpstan` L max · `migrate` apply+rollback+re-apply.

**Alternatives considered.** (a) A single Root owner + transfer protocol — rejected by owner (multiple co-owners, ADR-0080). (b) Restrict an admin by ADDING per-user grants while keeping them in `admins` — impossible (they already inherit everything; you cannot subtract with an additive engine), hence the not-in-`admins` model. (c) Track a restricted admin's "current bundle" via a `RoleAssignment` — rejected: bundles are starting points and per-key toggles diverge from any preset, so the persistent state is the section-grant SET, managed as direct `acl_entries`. (d) Inline a duplicate locked guard in `AccountDeletionService` vs sharing a helper — kept inline (mirrors its existing `assertNotSoleAdminLocked`, each service throws its own exception). (e) Leave restricted admins outside the 2FA mandate (a lighter tier) — rejected (security-by-default; an admin in capability carries the second factor).

### ADR-0087 — ACP v3 · v3-f: temporary-access delegation — the provenance table, the ceiling/no-clobber fences, and the current-mask cascade (2026-06-21)
**Status: Accepted — child of ADR-0080; owner-authorized unattended build, gated, APEX-reviewed (5-reason adversarial pass), flagged for review.**

**Context.** v3-0 (ADR-0080 §5) shipped the `acl_entries.expires_at` engine seam — the resolver's `expires_at IS NULL OR expires_at > now` filter, the cached-`can()` TTL cap, and the `novfora:acl:prune-expired` cron. v3-f is the first *product* on it: let a co-owner hand an individual ONE capability for a bounded window (≤ 30 days), auto-expiring, **ceiling-bounded** (the recipient never exceeds the delegator's current mask), with **co-owner / Administration-tier keys never delegable**. Additive (G1, no new eval path; no resolver change; no `acl_entries` schema change) and reversible (G3).

**Decision — table + model (step 1).** A new additive, reversible `delegations` provenance table (G10 — `acl_entries` carries no provenance): `delegator_id`/`recipient_id` (FK→users, cascade-on-delete), `permission_key`, `scope_type`/`scope_id`, `expires_at` **NOT NULL** (every delegation is time-boxed), `revoked_at` nullable (early-revoke marker), `created_at`; indexes `(recipient_id, expires_at)` + `(delegator_id, expires_at)`. `App\Models\Delegation` (created_at-only; a `live()` scope = not revoked AND not expired). It is the source-of-truth; the resolver never reads it (G1) — exactly the v3-b `moderator_assignments`/`ForumModeratorProjector` shape.

**Decision — `DelegationService` (apex, steps 2–3).** `grant()` runs four fences before any write (a rejection leaves zero rows): **co-owner only** (`canDo('admin.security.access')` — the actor-independent backstop to the SFC `mount()` gate); **non-delegable keys** (`admin.security.access` + any `Administration`-cluster key, rejected for *any* actor — stricter than the ceiling, which a co-owner as a full admin would pass — mirroring the projector's admin-tier refusal); **ceiling** (the *reused* `RoleManager::assertWithinCeiling([$key => Allow], $delegator, $scope)` AT THE TARGET SCOPE — the delegator must hold the key there, now); and **no-clobber** (below). The window is clamped `min(requested, now+30d)` and must be in the future. In one transaction it records the `Delegation` row and projects ONE time-boxed user-holder ALLOW row via `updateOrCreate` (the model write bumps `AclVersion` via `AclEntry::saved`); then `MembershipCache::flushFor($recipient)`. `revoke()` (co-owner-gated; shared with the cascade via a private `revokeRow`) sets `revoked_at`, KEY-SCOPED-deletes the mirrored row, and bumps `AclVersion` (G9 — a query-builder delete skips the `deleted` event), so the recipient's `can()` flips immediately with no dependence on the prune cron.

**Apex correctness seams (what the review pinned).**
- **The ceiling (the apex invariant).** A delegator cannot delegate a key they don't currently hold at the target scope (rejected, **zero rows**); `admin.security.access` and any Administration-tier key are never delegable; the 30-day cap clamps a longer request; revoke → recipient `can()` false **and** `AclVersion` bumped. Because the grant-time ceiling reuses the engine fence and only ever writes an ALLOW ≤ the delegator's reach, the recipient never resolves above the delegator's mask at grant time.
- **NO-CLOBBER (a review-class hazard).** `acl_entries` has NO unique constraint and `ForumModeratorProjector` can write a PERMANENT user-holder row at the same `(user, key, scope)` cell. A naive `updateOrCreate` on grant would time-box that permanent grant (NULL→TTL), and clobbering a user NEVER would silently lift a hard-deny. So grant **refuses** if a *live* user grant (NULL or future expiry) or any NEVER already occupies the cell; revoke deletes ONLY the `whereNotNull('expires_at')` ALLOW row, never a permanent grant the recipient may also hold there. (A dead, not-yet-pruned TTL row does not block — `updateOrCreate` revives it.)
- **"Current mask" — the cascade (the one design decision, RESOLVED: yes).** The ceiling is checked at grant time; the delegation then stands as a static time-boxed grant. To honour "never exceeds the delegator's *current* mask" if the delegator is later demoted, `cascadeForActor($delegator)` re-checks each LIVE delegation's `canDo(key, scope)` after the demotion commits + flushes and revokes those that no longer pass. **Wired into** `GroupManager::removeMember` (post-commit) — the path that actually matters: a co-owner's *delegable* capabilities flow from the `admins`-group preset (not the per-user security key), so admins-group removal is the real mask reduction — **and** `AdminCoOwnerService::revoke` + `AdminBundleService::revoke` (the spec-named demotion paths; defensive no-ops in practice — co-owner revoke leaves admins membership intact, and a restricted admin holds only non-delegable keys and cannot delegate). **Bounded gap (documented):** a co-owner's *group* later losing a key via `GroupPermissionEditor` is NOT cascaded this pass (a member-set fan-out, deferred); the ≤ 30-day auto-expiry caps the worst-case staleness, and the grant-time ceiling still bounds the recipient at grant time.
- **Auto-expiry is the seam, not new code.** The delegated row is dropped by the resolver's `expires_at` filter the instant it lapses (a test pins the cross-boundary `can()` flip with **no prune run**), and `novfora:acl:prune-expired` later hard-deletes it while the `delegations` row stays as audit history.

**Decision — Active Delegations SFC + route + nav + lang (step 4).** `resources/views/components/admin/security/⚡active-delegations.blade.php` (mount + every action assert the 2FA-confirmed co-owner gate — Livewire bypasses route middleware — exactly like `⚡co-owners`): a create form (recipient · capability dropdown of non-Administration keys · scope · days, the 30-day cap surfaced) and a list of live delegations (recipient, key *label*, scope *name*, granted-by, expires-in) with an early-Revoke. `@extends` wrapper view; route `security.delegations`; nav sub-page `[active_delegations, admin.security.delegations, clock]`; `admin.security.delegations.*` strings (G8).

**Consequences.** One additive reversible migration (G3; gate exercises apply+rollback+re-apply). No resolver / `acl_entries` schema change (G1/G2). Three demotion services gained a one-line post-commit cascade hook (`AdminCoOwnerService`, `AdminBundleService`, `GroupManager`). **Next: v3-g** per ADR-0080.

**Verification.** Inspector/resolver-oracle (G4, `explain()` uncached) across grant (recipient resolves the key; one TTL row written), the ceiling (delegate-what-you-don't-hold + delegate-broader-than-held → rejected, zero rows), non-delegable keys + non-co-owner actor (rejected), the 30-day clamp + past-window refusal, revoke (verdict flips on BOTH `explain()` and cached `can()`; `AclVersion` bumped; provenance kept), no-clobber (permanent-grant + NEVER refusals; revoke spares a permanent grant at the same cell), the auto-expiry seam (flip with no prune, then prune sweeps the dead row leaving the audit row), and the cascade (admins-group removal revokes the now-over-ceiling delegation; a still-held key is left intact) — 16 cases. Plus the SFC gate (403 non-co-owner / renders for a co-owner) + the Livewire grant→revoke round-trip. Gate: `pest` · `pint` · `phpstan` L max · `migrate` apply+rollback+re-apply.

**Alternatives considered.** (a) Build a parallel "temporary grants" evaluation path — rejected (G1; the v3-0 `expires_at` seam already auto-expires a normal `acl_entries` row). (b) `updateOrCreate` the projected row unconditionally — rejected (clobbers a permanent grant / lifts a NEVER; hence the no-clobber fence). (c) Delegate to groups/roles — out of scope (per-user, single-key this pass). (d) Defer the current-mask cascade entirely (document the gap) — declined for the paths that matter (wired into the real admins-removal trigger); only the `GroupPermissionEditor` group-key fan-out is deferred, bounded by the 30-day cap. (e) A new cron to expire delegations — rejected (the existing prune already sweeps any TTL row; the resolver filter is authoritative without it).
