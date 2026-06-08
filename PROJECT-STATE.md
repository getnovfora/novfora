# PROJECT-STATE.md — Hearth (session resume / handoff)

> **Purpose:** single source of truth for *where this project stands right now*. **Both** a new
> **Claude Code** session and a **Claude Cowork** session should read this **first**, every time.
> Keep it at the repo root (`D:\Forum`). **Whoever is working should keep this file updated.**
> Supersedes any earlier copy of this file.
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` (doc index) ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (the Stage A set).

## What this is (one line)

**Hearth** (working codename) — open-source (**Apache-2.0**), **self-hosted** forum/community
platform; modern PHP; **two tiers from one codebase** (baseline shared PHP host / enhanced
Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; first-class anti-spam; **strict
clean-room**.

## CURRENT STACK (approved — this overrides any stale mention elsewhere)

**Laravel 13 + Livewire 4 + Alpine + Blade**, server-rendered. **PHP 8.3 floor** (8.4+ recommended).
**MySQL 8 / MariaDB** default; PostgreSQL on Docker/VPS. Vite, prebuilt assets (no host Node).
- Approved at the Phase 0 gate via **ADR-0001 / ADR-0002** (revises the brief's original 11 / 3 / 8.2).
- **The `wire:ignore` + Alpine island pattern is the intended TipTap integration mechanism** (Livewire's
  `wire:ignore` excludes the editor DOM from morphing — distinct from Livewire 4's separate "islands"
  re-render feature — making the rich-text editor a first-class supported pattern and de-risking the
  project's #1 technical risk).
- **Divergence RESOLVED (2026-06-01):** `CLAUDE.md` and `docs/PROJECT-BRIEF.md` now read **13 / 4 / 8.3**;
  ADR-0001/0002 are marked **Accepted** in `DECISIONS.md`. *(The original input
  `forum-software-super-plan-v3.md` is intentionally left as the historical prompt — say the word to update
  it too.)*

## How we work (Code vs Cowork — same `D:\Forum` folder, two tools)

- **Claude Code (build):** scaffolds and writes the Laravel app. Plan-before-code per phase.
- **Claude Cowork (knowledge work):** reviews plans/docs, preps the gate decision packets, writes
  status summaries, refreshes competitive research, coordinates phases. **Does not write app code.**
- **Don't run both against the working tree at the same time.** Commit between handoffs; let git be
  the source of truth for what changed.
- **Two stages, gated:** Stage A — Discovery (done) -> **Phase 0 gate (passed)** -> Stage B — phased
  implementation (plan-before-code per phase). **No Stage B application code until the Phase 1 plan
  is signed off by the owner.**

## Status (as of this handoff)

> **▶ PHASE 1 / CORE MVP COMPLETE (2026-06-03).** M0–M5 all done and shippable: runs on a baseline shared host
> via the **no-SSH web installer** (one cron line drives everything), identical code on the enhanced tier,
> **Pest 272 / 879** green + **Dusk executed (2)**, all CI guards pass, demo seed + getting-started, upgrade/
> restore proven. The six exit criteria → evidence are mapped in the **M5 DONE** update below. **NEXT is a
> strategic owner call (not a milestone): Phase 2 vs. a Phase 5 hardening/release pass.**

- **Stage A: COMPLETE.** All Section 8 deliverables produced and the Checkpoint-1 research fixes
  applied: `docs/research/` (comparison + complaints), `docs/architecture/`
  (technical-stack-recommendation, system-architecture, data-model-initial, plugin-and-theme-system,
  security-and-permissions, testing-strategy), `docs/product/` (mvp-scope, roadmap,
  feature-prioritization), and the governance/living docs.
- **Phase 0 gate: PASSED.** Decisions locked at the gate:
  - Stack revision **approved** -> 13 / 4 / 8.3 (ADR-0001/0002).
  - **Full MVP** chosen as the Phase 1 framing (editor cut #1 *not* applied -> richer editor node set
    is in Phase 1, which front-loads the WYSIWYG<->Livewire spike).
  - Two polish items **approved**: add a **2FA** MoSCoW row (admin/mod 2FA = Must, general-user =
    Should) and a **Akismet** phase note in the anti-spam roadmap.
- **Deferred to Phase 1 (needs the scaffold):** `.env.example`, seed data, runnable getting-started.

## Immediate next actions (in order)

> **Update 2026-06-01:** Step 1 ✅ **done** — stack reconciled to **13 / 4 / 8.3**, ADR-0001/0002 **Accepted**,
> 2FA + Akismet polish applied, **Reverb DB-driver note** added to system-architecture, `CODE_OF_CONDUCT.md`
> created. Step 2 ✅ **done** — Phase 1 plan drafted at
> [`docs/product/phase-1-plan.md`](docs/product/phase-1-plan.md) (**Full MVP**; **Spike 0** first with a hard
> **GO/NO-GO** + three fallbacks). **Step 3: Phase 1 plan APPROVED (2026-06-01)** with two trims → Phase 2
> (dark-mode + 2nd example theme; search filters/facets). **BLOCKER for Spike 0:** this environment has
> **Node/npm/git but no PHP / Composer / MySQL** — the Laravel build toolchain must be present before Spike 0
> can scaffold, run, and be verified. **Owner decision: the build runs in the Claude Code env.** Spike 0 is
> packaged as a deterministic handoff → [`docs/product/spike-0-handoff.md`](docs/product/spike-0-handoff.md).
> **NEXT (Code build session): execute Spike 0, fill the GO/NO-GO memo, report the result back** — then the
> confirmed editor pattern folds into the M0→M5 build (and ADR-0012 updates if a fallback is chosen).
>
> **Update 2026-06-02 (Spike 0 EXECUTED → GO):** all six criteria **PASS** with executed evidence — **Pest 10
> passed / 82 assertions** (incl. the #4 security suite) and **Playwright 6/6** (incl. the #1a GO-blocker, both
> paths). Run in a **Docker `php:8.3`** env (this box has no host PHP) + host Node/Playwright/Chromium. Memo:
> [`docs/product/spike-0-memo.md`](docs/product/spike-0-memo.md); reference scaffold in `hearth-spike/` (source
> committed, heavy artifacts git-ignored; env in `.spike-docker/`). **Key findings:** Livewire 4 = single-file
> components; the **editor must be non-reactive closure state** (a reactive proxy breaks ProseMirror →
> "mismatched transaction"); deferred `$wire.set` needs no debounce. **No fallback needed — ADR-0012 stands.**
> **NEXT: owner gate → begin Phase 1 M0** (port the validated pattern into the app at the repo root).
>
> **Update 2026-06-02 (Cowork):** **Spike 0 handoff pressure-tested + hardened** before the Code session runs it.
> Key fix: renamed the mechanism to **`wire:ignore` + Alpine island** — verified `wire:ignore` ≠ Livewire 4 "islands"
> (islands = partial re-render; `wire:ignore` = the DOM-morph exclusion the editor actually needs; ADR-0012 had it
> right). Also: criterion #4 now calls out the `nodesToHtml` renderer + a defined node set (it shipped as an empty
> stub); the reference code **dynamic-imports** the editor so criterion #6 (budget) can pass; criterion #1 split into
> **1a = GO-blocker** vs **1b `wire:navigate` = best-effort/documented** (reconciled into
> [`phase-1-plan.md`](docs/product/phase-1-plan.md) §4); upload-stub + "lossless" comparison defined; TipTap version-pin,
> Dusk-needs-Chrome, and a ~1-day time-box added; memo template records all resolved versions. **Build-readiness
> confirmed: Laravel 13 is GA (2026-03-17, PHP 8.3 floor) — the scaffold's first command resolves.** **NEXT is
> unchanged: the Code session executes the corrected Spike 0 and returns the memo.**
>
> **Repo baseline (2026-06-02):** `D:\Forum` is now **git-tracked** — first commit `a875a9a` on branch `main`
> (27 files, DCO sign-off), so Code and Cowork can commit between handoffs. The ready-to-paste Code kickoff
> prompt is saved at [`docs/product/spike-0-code-kickoff.md`](docs/product/spike-0-code-kickoff.md).
> *(Cowork-env caveat: the `D:\Forum` bash mount mangles git's own `config` write — if you must git-operate
> from Cowork, enable deletes via the file-delete permission and hand-build `.git` with plain writes. The Code
> build env has no such limitation.)*
>
> **Update 2026-06-02 (Cowork — Spike 0 GO reviewed + findings folded):** verified the GO against the committed
> memo + evidence (Pest 10/82, Playwright 6/6) — a clean GO, no gaps. **Folded the outcome into the durable
> docs:** ADR-0012 marked **validated** with its binding constraint (*editor in per-instance closure state, never
> a reactive Alpine property*), and the 7 findings added to [`phase-1-plan.md`](docs/product/phase-1-plan.md) §4 as
> **M2 implementation notes**. **NEXT = owner gate: begin Phase 1 M0** (already approved in the Phase 1 plan,
> 2026-06-01) — scaffold the real app at the **repo root** (skeleton + service-tier detection + CI + installer
> skeleton + reversible-migration baseline). The validated editor pattern + `CanonicalRenderer` **port in M2**,
> not M0 (per the plan); then M1→M5. Retire `hearth-spike/` once the real app supersedes it. M0 build kickoff:
> [`docs/product/m0-code-kickoff.md`](docs/product/m0-code-kickoff.md). *(These Cowork doc edits are on disk;
> commit them from the Code env — the Cowork mount is unreliable for git writes.)*
>
> **Update 2026-06-02 (M0 DONE — Code):** **Phase 1 M0 (skeleton & guardrails) complete** at the repo root.
> Laravel **13.13** + Livewire **4.3** + Scout merged in (preserving docs/git); baseline-safe drivers +
> `.env.example` (MySQL). **Service-tier detection (ADR-0003):** probes that never throw + `hearth:tier` CLI
> + a local-gated `Admin → System → Service Tier` Livewire panel + **5 forced-absence tests**.
> Reversible-migration guard + `hearth:backup` skeleton. **Prebuilt Vite assets committed** (no host Node).
> **CI** (Pint, Larastan, Pest, `composer audit`, asset budget) — green; full local run: **Pest 9 passed + 1
> todo**, Larastan clean, Pint 46 files. Built via a Docker **php:8.3 + mysql:8** dev env (`docker-compose.yml`,
> `docker/dev/`). Commits `4227af5`…`d686cbd` on `main`; dep licenses recorded in `DECISIONS.md`.
> **NEXT: M1 — Identity & access** (auth + 2FA + the **permission-mask engine**, ADR-0006) per
> [`phase-1-plan.md`](docs/product/phase-1-plan.md) §5. The validated editor pattern + `CanonicalRenderer`
> port in **M2**; retire `hearth-spike/` then.
>
> **Update 2026-06-02 (M1 DONE — Code):** **Phase 1 M1 (Identity & access) complete.** Two pillars.
> **(1) The permission-mask engine (ADR-0006 / security §1.2, implemented exactly):** three-state
> ALLOW/NO/NEVER over the global→category→forum→thread scope chain; NEVER short-circuits, user overrides
> group, groups merge most-permissive, deny-by-default; **NO = neutral/inherit** (interpretation "ii",
> reconciled with §1.1/§2.3 + phpBB's tri-state — **flagged for explicit sign-off**; the single flip-point
> is marked inline in `PermissionResolver::compute()`). Per-request memo + a resolved cache keyed by a
> global ACL version × the user's group-set signature (event-driven invalidation, incl. scope-topology
> changes); **correctness never depends on the cache.** Exposed via Laravel Gate (`$user->can('perm',
> $scope)`), deny-by-default. The **"why can/can't X" inspector (§1.4)** = a service + `hearth:why` CLI + an
> ACP Livewire panel, all reading the same resolution (no re-implementation). Schema: groups + group_user,
> permissions + acl_entries (5-col resolution index), roles/role_permissions/role_assignments, minimal
> forums/topics scope nodes (materialised path), bans; nullable `tenant_id` seam only (ADR-0004). **Seeds**
> (idempotent, production-safe): system groups (guests/members/moderators/admins) + trust levels tl0–tl4
> (the gating primitive; promotion automation is M3) + permission catalog + role presets expanded onto
> groups. **(2) Auth (ADR-0019):** Laravel **Fortify** (headless) behind **our own clean-room Blade views** —
> register/verify/login/logout/sessions, password reset, **argon2id**, login throttling; **2FA/TOTP
> mandatory for staff** (`RequireTwoFactorForStaff`), opt-in for users; admin panels gated on `admin.access`
> via the engine. Passkeys deferred (`laravel/passkeys` dormant). **DoD met:** the M0 `PermissionMaskTest`
> placeholder is filled with an **exhaustive truth table** (ALLOW/NO/NEVER × scope chain × group-merge ×
> primary/secondary × bans × §1.5 deleted/moved-scope edges), each assertion using the inspector trace as
> the **oracle** (`can()` must agree with `explain()`). **Full suite: Pest 106 passed / 325 assertions**
> (M0 tier + operability stay green); Larastan clean; Pint clean; runs on the baseline tier (PHP 8.3 +
> MySQL + cron). Dep licenses (fortify / google2fa / bacon-qr / passkeys) in `DECISIONS.md` (ADR-0019);
> `.env.example` gains `HASH_DRIVER=argon2id`. Commits on `main` (small, conventional, signed-off).
> **NEXT: M2 — forum CRUD + content storage + the validated editor / `CanonicalRenderer` port**; retire
> `hearth-spike/` then. **OPEN ITEM for the owner: confirm the NO = neutral ("ii") interpretation** (a
> one-branch flip switches to strict-"i" if you want a set NO to hard-stop inheritance).
>
> **Update 2026-06-03 (M2 DONE — Code):** **Phase 1 M2 (Forum structure, content & the WYSIWYG editor)
> complete.** The owner's **NO=neutral ("ii") sign-off is now recorded durably** (security §1.1 + ADR-0006).
> **Schema (ADR-0005 / data-model §2-3, reversible):** forums/topics extended (counts, last-post pointers,
> type/status/pin/approved_state, reserved prefix/poll/moved seams) + new posts (canonical storage:
> body_format / body_canonical / **body_html_cache** / body_text), post_revisions, attachments, append-only
> audit_log; SoftDeletes on forums/topics/posts/attachments — which cleanly realises the resolver's §1.5
> "deleted scope → inherit from surviving parent" via the recycle bin. Denormalised counters via model events
> (no COUNT(*) on read paths). **Content security boundary (ported from Spike 0, extended):**
> `app/Content/CanonicalRenderer` (TipTap-JSON→HTML, M2 node set incl. tables/spoilers/hr/strike) + the
> `ContentSanitizer` allowlist (symfony/html-sanitizer) — **kept hand-rolled, no tiptap-php dep**;
> `ContentRenderer` dispatches tiptap_json vs **Markdown** (CommonMark, raw-HTML-escaped + unsafe-links-denied)
> through the SAME sanitizer. HTML is always regenerated server-side; client HTML never trusted. **Editor:**
> the validated `wire:ignore` + Alpine-island TipTap ported with **all 7 findings** (closure-state, deferred
> $wire.set, defer-tick insert, StarterKit-bundles-Link), richer nodes (tables, /slash menu, @mentions via
> suggestion, images) + Markdown toggle, as a reusable `<x-content-editor>`; **lazy chunk 132 KB gz** (main
> bundle 1 KB) — under the ≤180 KB budget; prebuilt assets committed. **CRUD + per-node authz:** server-rendered
> forums→topics→posts, **every gated action through the M1 engine** (deny-by-default); **anonymous browsing
> resolves as the Guests group** (`User::guest()`, no second code path). Livewire composers (create/reply/edit)
> with revisions. **Moderation:** lock/pin/sticky/move/soft-delete/**recycle bin**/restore + own-vs-any post
> deletes (PostPolicy) + **audit log**. **Attachments:** typed allowlist + size + sha-256 + off-web-root +
> tier-graceful image dims/thumbnails (GD when present), authorized streaming, wired to the editor upload.
> **Tier-graceful index caching.** **Fixed an M0-scaffold bug:** `shouldRenderJsonWhen` only covered `api/*`,
> so AJAX endpoints 500'd on validation errors — now honours `expectsJson()`. **Tests:** the **XSS battery**
> (extended to the M2 node set + the Markdown path), per-node authz, CRUD, moderation, soft-delete/restore,
> attachments, counters, and the editor round-trip (server half). **Pest 148 passed / 510 assertions** (M0
> tier + M1 truth-table/auth suites STAY green); Larastan + Pint clean; reversible migrations; `composer audit`
> clean. The Spike-0 battery is also written as a **Dusk journey** (`tests/Browser`) that runs in a
> Chrome-enabled CI (`php artisan dusk`) — the normal `pest` run excludes Browser, so CI without a browser
> stays green. **`hearth-spike/` retired.** **Deferred (per the scope fence):** anti-spam enforcement +
> moderation queue/approval workflows + ACP/MCP + word-filters (M3); reactions/PMs/notifications/search/SEO/theme
> (M3/M4); oEmbed embeds + reactions/polls/tags *features* (Phase 2, seams only). Commits on `main` (small,
> conventional, signed). **NEXT: M3 — Anti-spam baseline & moderation (ADR-0007)** — trust levels enforced
> through the ACL, registration blocklist, moderation queue + ACP/MCP, per [`phase-1-plan.md`](docs/product/phase-1-plan.md) §5.
>
> **Update 2026-06-03 (M3 DONE — Code):** **Phase 1 M3 (Anti-spam baseline & moderation, ADR-0007) complete.**
> The whole subsystem is **unified with the M1 permission engine — no second permission system.**
> **(1) Trust→ACL gating (the crux):** TL gates seeded as `acl_entries` on TL0–TL4 from a config matrix
> (`config/hearth.php`) — **TL0 = NEVER on links/images/mass-PM** (absolute; an admin ALLOW cannot lift it,
> pinned by a test), TL1+ = ALLOW; attachments stay an admin-liftable soft seam. Enforced by **link/image
> suppression at the shared sanitize step** (canonical stays lossless, ADR-0005). The inspector explains a block
> as "tl0 group: post.links = NEVER". **Auto promotion/demotion** via the idempotent `hearth:trust:recompute`
> cron (stats + infraction points; TL4 manual). **(2) Registration layer** (Fortify `CreateNewUser`): tri-state
> **allow / flag→pending / block** (flag-don't-block) from StopForumSpam (live→cron-cached→no-signal),
> disposable-email, honeypot+encrypted-timing, IP velocity + a `CaptchaProvider` abstraction (**Q&A baseline,
> Turnstile pluggable, degrades to Q&A**); `registration_checks` purged on a GDPR retention cron. **(3)
> Posting/reactive:** a `ContentScanner` **contract** (local heuristics now; **Akismet = Phase 2** behind it) +
> word filters (replace/flag/block) + a **new-user moderation queue** (TL0 first-N, and any `status=pending`
> account — which closes the registration-flag→queue loop) via `approved_state`, with pending content hidden
> from non-staff; **per-trust rate limiting** (cache-backed, tier-graceful); **Spam Cleaner** (bulk soft-delete +
> ban); user/IP/email/range **bans** (issuing/lifting now **bumps the ACL version** so a cached verdict can't
> outlive a ban). **(4) Moderation + MCP:** approval queue (approve/reject), reports→staff dashboard, **warnings/
> infractions** (typed, point-weighted, time-decaying, threshold consequences moderate→temp-ban→ban,
> acknowledge-to-restore), an MCP control-panel + in-thread Report action — all gated through the engine and
> audited. **Schema (reversible):** registration_checks, blocklist_cache, reports, warning_types, warnings,
> word_filters (`rate_limit_hits`/`mod_actions` intentionally omitted — see DECISIONS). **Tests:** the DoD
> battery — NEVER hard-gate through the engine **and admin-ALLOW-can't-lift**, tri-state registration, queue+
> approval, bans/word-filters, and the **tier-graceful suite** (force StopForumSpam/CAPTCHA absence → degrade,
> never error). **Pest 212 passed / 674 assertions** (M0 tier + M1 truth-table/auth + M2 suites STAY green);
> Larastan + Pint clean; `composer audit` clean; **migrations reverse cleanly on MySQL 8**; runs on the baseline
> tier (PHP 8.3 + MySQL + cron). **No new dependencies.** The M3 server-rendered flows are covered by feature
> tests; the M2 Dusk editor journey is unchanged. Small conventional DCO commits on `main`. **NEXT: M4 —
> Notifications · search · SEO · theme**, per [`phase-1-plan.md`](docs/product/phase-1-plan.md) §5.
>
> **Update 2026-06-03 (M4 DONE — Code):** **Phase 1 M4 (Notifications · Search · SEO · Theme) complete** — the
> last build milestone before M5. **No new dependencies.** **(1) Notifications (data-model §7):** a custom
> merge-aware `Notifier` on two channels — **database (in-app, polled)** + **mail (queued, cron-drained,
> ADR-0014)** — for replies, @mentions (parsed from the canonical doc), and moderation/warning notices; a new
> same-thread reply/mention **merges** into the recipient's unread notification ("X and N others…"); held posts
> notify at approval, not at write. Per-event×channel **preferences**, an `email_suppressions` list, a
> `hearth:mail:test` self-test, a **Livewire polling bell** (Reverb is Phase 4). **(2) Search (ADR-0010):**
> `Post` is Scout `Searchable` over `body_text` on the **database** driver (MySQL FULLTEXT/LIKE), approved-only,
> results filtered to forums the viewer can see; a `SearchService` **degrades to a direct DB query when the
> engine (Meilisearch) is absent** (forced-absence test). Inline typeahead + a per-user **unread / "what's new"**
> read-watermark (`topic_reads`). **(3) SEO (system-architecture §6):** canonical URLs, Open Graph, schema.org
> **DiscussionForumPosting JSON-LD**, a cached **XML sitemap** (empty containers + non-viewable content
> excluded) + robots. **(4) Theme (ADR-0009 — the deliberate part):** a **semver'd Blade override layer**
> (`ThemeManager`, THEME API **v1.0**) resolving **active theme → parent → core** so a child theme overrides any
> view with **no core edit** (proven by a fixture test); manifest-declared `api_version`; **a11y floor** baked in
> (skip-link + `#main`, `:focus-visible`, AA-contrast CSS-custom-property tokens) — themes restyle, can't strip;
> docs in [`docs/THEME-API.md`](docs/THEME-API.md). One mobile-first default theme + a primary nav. **(5)
> Profiles (data-model §1):** signatures via the M2 canonical pipeline + sanitizer (client HTML never trusted),
> admin-defined custom fields (ACP CRUD), avatars/covers. **Schema (reversible):** notifications,
> notification_preferences, email_suppressions, topic_reads, custom_fields(+values), users.signature_*. **Pest
> 247 passed / 760 assertions** (M0–M3 suites STAY green); Larastan + Pint clean; `composer audit` clean;
> **migrations reverse cleanly on MySQL 8**; **asset budget green** (main JS 1 KB gz, editor chunk 132 KB gz,
> rebuilt). Tier-graceful suite covers search + notifications. Runs on the baseline tier. Small conventional DCO
> commits on `main`. **Phase-1 §3 reconciled** (reports/warnings/edit-history now correctly listed as built).
> **NEXT: M5 — Operability & the runnable milestone** (no-SSH web installer, automated backups + restore,
> upgrade rehearsal, demo seed + getting-started, `.env.example` finalize, perf budgets in CI), per
> [`phase-1-plan.md`](docs/product/phase-1-plan.md) §5.
>
> **Update 2026-06-03 (M5 DONE → Phase 1 / MVP COMPLETE — Code):** **Phase 1 M5 (Operability & the runnable
> milestone) complete. The Core MVP is shippable.** **No new dependencies.** **(1) No-SSH web installer
> (ADR-0020, the headline):** a browser wizard (system check + tier detection → DB → site & admin → run) on a
> standalone pre-install layout, backed by one `App\Install\InstallRunner` shared with a `hearth:install` CLI
> — write `.env` (+ generate APP_KEY) → point the live connection at the new DB → verify → migrate → seed
> posture → optional demo → create the admin (argon2id, email-verified, staff) → `storage:link` → **LOCK
> last**. The lock is a `storage/installed` **file marker** (checkable before the DB exists, survives a DB
> wipe), written last; once present the installer 403s — **no re-trigger, no admin-reset web vector** (reset =
> a filesystem action). A pre-install boot hook hardens a fresh upload (file session/cache, sync queue, ensured
> APP_KEY) so it boots with no DB; `RedirectIfNotInstalled` sends an un-installed site to the wizard.
> Security-tested: lock, input validation, redirect, full-run integrity, no-secret-leak. **(2) Backups +
> restore:** completed the M0 skeleton — one portable `.zip` (DB dump + storage mirror + a manifest with a
> **SHA-256** of the dump); `hearth:backup` (cron + `--keep` retention), `hearth:restore` (manifest+hash
> validated, `--force`), and an **Admin → Backups** Livewire panel (run/download/delete, authorized in-component,
> path-safe). **(3) Health:** `GET /health` (DB, cache, cron-queue freshness, tier, install state) — never
> throws, never leaks, 503 on DB-down. **(4) One cron line (ADR-0011):** the scheduler now drives the bounded
> queue drain + a liveness heartbeat + backups + the trust/anti-spam jobs. **(5) Demo seed + getting-started:**
> an idempotent believable community via the real `PostService`; [`docs/getting-started.md`](docs/getting-started.md).
> **(6) `.env.example` finalized** (every key documented, enhanced-tier blocks commented). **(7) Perf budgets in
> CI:** query-per-page Pest gates (thread ≤30, index ≤15, no N+1) + asset budgets (main JS <50 KB, no chunk
> >180 KB, CSS <50 KB) + a MySQL backup→restore round-trip. **(8) Dusk executed for real:** the M2 editor battery
> now runs GREEN in a Chrome-enabled `docker/dusk/` image + CI job — compose+post a topic end-to-end and the
> criterion-1a morph-survival GO-blocker, **2 passed**.
>
> **Phase 1 EXIT CRITERIA — all six met (each → evidence):**
> 1. **Runs on the baseline tier via the no-SSH installer; one cron line.** → installer suite green; the wizard
>    locks after install; `schedule:run` drives the whole tier (SchedulerTest).
> 2. **Identical code on the enhanced tier, no code change.** → ServiceTier (ADR-0003) + forced-absence suites;
>    `.env.example` enhanced blocks; tier-graceful search/notifications/cache stay green.
> 3. **Tests green incl. permission-mask truth-tables + service-tier fallback.** → **Pest 272 passed / 879
>    assertions** (M0–M4 + M5), both non-negotiable suites included.
> 4. **All CI guards pass.** → Pint clean (241 files), Larastan clean, `composer audit` clean, **Dusk executed
>    (2 passed)**, query/asset budgets enforced.
> 5. **Demo seed + getting-started produce a working community; `.env.example` current.** → DemoSeeder
>    (idempotent) + [`getting-started.md`](docs/getting-started.md); `.env.example` finalized.
> 6. **Upgrade/restore proven on the baseline tier.** → reversible-migration cycle + backup→restore round-trip
>    asserted green (BackupRestoreTest) + a MySQL end-to-end round-trip in CI.
>
> **Verified in Docker `php:8.3` + `mysql:8`** (this box has no host PHP); Dusk in a `php:8.3` + system
> Chromium/ChromeDriver image. Small conventional DCO commits on `main`. **Manual follow-up (per the phase-1
> risk table): live shared-host validation on ≥2 real hosts** can't run in the container — the installer ships
> requirement/writable-path probes + a host-compatibility checklist; real-host testing is flagged. **NEXT is a
> strategic owner decision (not a milestone): Phase 2 (Community) vs. a Phase 5 hardening/release pass before a
> public 1.0** — the Cowork session preps that decision packet.
>
> **Update 2026-06-03 (PHASE 1.5 — Validation & Hardening, pre-beta — Code):** an **adversarial security
> review + real-host-readiness** pass over the complete Phase-1 codebase (reviewed as untrusted), per
> [`p1.5-validation-hardening-kickoff.md`](docs/product/p1.5-validation-hardening-kickoff.md). **Findings →
> [`docs/SECURITY-REVIEW.md`](docs/SECURITY-REVIEW.md):** 9 clear-cut issues **FIXED with a regression test
> each**, 10 **FLAGGED for owner decision**. **Fixed:** (H-1) attachment-download **IDOR** — `forum.view`
> now gates attached files (was an anonymous, enumerable leak of private-forum files); (H-2) **TL0
> link/image suppression now covers signatures** (a public surface that bypassed the gate); (H-3,
> baseline-readiness) a **pure-PHP MySQL dump+restore fallback** so cron backups work on shared hosts that
> disable `proc_open`/lack `mysqldump` (round-trips green on real MySQL); (M-1) narrowed `User`
> mass-assignment — `trust_level`/`status`/`signature_html`/`avatar_path`/`cover_path` out of `#[Fillable]`
> (latent priv-esc / stored-XSS); (M-2) the installer **pre-checks marker writability** so an
> unwritable-`storage/` host can't finish installed-but-**unlocked**; (M-3) a baseline `SecurityHeaders`
> middleware (CSP + nosniff + frame-ancestors + Referrer/Permissions-Policy); (M-4) `.env` written **0600**;
> (L-1) `APP_DEBUG` forced off pre-install; (L-2) HTTPS-only cookie on an https install. **Flagged (not
> changed):** the unauthenticated install window (no-SSH tradeoff); registration rate-limit/honeypot/Q&A
> weaknesses; StopForumSpam cold-cache fail-open; trust-promotion gaming; write-time suppression not
> recomputed on demotion; no actor-vs-target rank check; six unguarded authz models (guard before the roles
> ACP); mass-assignable `tenant_id` (scope-fenced — left untouched); auth-event audit-log gaps; the strict
> nonce-CSP follow-up. **Part 2 — real-host readiness:** a **`hearth:doctor`** preflight (extends
> RequirementChecker: disabled functions, `open_basedir`, drivers, mail, symlink-works, backup method,
> coarse-cron) — **green on the Docker baseline**; a **copy-based public-storage fallback**
> (`PublicStorageLinker` + `hearth:storage:publish` + a cron refresh) for symlink-disabled hosts; and
> **[`docs/REAL-HOST-VALIDATION.md`](docs/REAL-HOST-VALIDATION.md)** — a runbook for installing on ≥2 real
> shared hosts. **Suite: Pest 289 passed / 1 skipped (MySQL-gated) / 940 assertions** (M0–M5 stay green);
> Pint clean, Larastan clean, `composer audit` + `npm audit` clean; the M2 **Dusk editor journey stays green
> under the new CSP**. Verified in Docker `php:8.3` + `mysql:8`. Small conventional DCO commits on `main`.
> **NEXT (human step): run [`docs/REAL-HOST-VALIDATION.md`](docs/REAL-HOST-VALIDATION.md) on ≥2 real shared
> hosts; then the owner triages the flagged items → private beta.**
>
> **Update 2026-06-03 (PHASE 1.5 — Security Fix Pass — Code):** the owner reviewed the review and chose to
> **fix ALL ten flagged items** (F-A..F-M3 + tenant_id) toward public-1.0 readiness — hardening only, no new
> features, and without weakening the spec's "flag-don't-block on uncertainty." Every strict control has a
> test-env opt-out (mirroring `HEARTH_CAPTCHA`/`HEARTH_SFS_API`) so M0–M5 + P1.5 stay green. **Fixed (each
> with a regression test):** **F-A** pre-install **setup token** (`storage/install-token.txt`, 0600; wizard
> step-1 + `hearth:install` require it, gating the DB-test SSRF; consumed on install); **F-B** registration
> **rate-limit** + **mandatory** honeypot/timing + **single-use Q&A nonce**; **F-C** StopForumSpam **fail-safe**
> (degrade→pending, never silently allow) + `hearth:antispam:warm` cron so the blocklist is never cold;
> **F-D** trust promotion now needs the §2.3 **topics-read** engagement signal (a self-poster can't lift the
> TL0 link gate); **F-E** a trust change **re-renders** the user's posts (re-suppress on demotion); **F-F**
> actor-vs-target **rank check** (a mod can't ban/warn/spam-clean an admin or peer); **F-G** explicit
> `$fillable` on the six ACL models; **F-H** `tenant_id` removed from `User` mass-assignment; **F-I**
> **auth-event audit logging** (login/failed/logout/lockout/reset/2FA → `audit_log`). **F-M3** strict
> nonce-based CSP **shipped behind a toggle** (`HEARTH_CSP_STRICT`, default OFF) — script-src drops
> `'unsafe-inline'` via a per-request nonce that `@vite`/Livewire pick up; making it default-on still needs
> the Alpine CSP build + an inline-style refactor (tracked in SECURITY-REVIEW §6, F-M3). **Suite: Pest 310
> passed / 1 skipped / 1012 assertions** (M0–M5 + P1.5 stay green); Pint + Larastan + `composer audit` + `npm
> audit` clean; **Dusk editor journey green under the shipped (baseline) CSP — and verified green under the
> strict CSP toggle too**. `docs/SECURITY-REVIEW.md` §1/§6 record each F-x → Fixed. Small conventional DCO commits on `main`. **NEXT is unchanged: the human
> real-host validation on ≥2 shared hosts → private beta.**
>
> **Update 2026-06-05 (REAL-HOST RH-6 — installer wizard front-end FIXED + full-wizard browser coverage — Code):**
> the live cPanel validation surfaced a dead installer front-end (RH-6): on a clean subdomain with all assets 200,
> the wizard rendered but every `wire:click` fired no request — the install couldn't be completed in a browser.
> **Root cause (read from Livewire 4's `dist/livewire.esm.js`):** Livewire auto-starts from a single
> `DOMContentLoaded` *event listener* with no `readyState` fallback — it sets `window.Alpine.__fromLivewire`
> synchronously (so Alpine is always "present") but calls `Livewire.start()` (which builds `$wire` and binds every
> `wire:` directive) only when that event fires. The standalone pre-install layout delivered the runtime **solely**
> via Livewire's response-rewrite auto-injection; a shared-host JS optimizer (LiteSpeed/Cloudflare, ubiquitous on
> cPanel) that defers that `<script>` so it runs *after* `DOMContentLoaded` leaves the listener dangling →
> `start()` never runs → Alpine present but directives unbound, no console error (the exact symptom). A clean
> `php artisan serve` runs the script synchronously *before* the event, so it worked locally — the gap was
> invisible because **Dusk only ever drove the editor, never `/install`.** **Fix (Blade-only,
> `resources/views/install/index.blade.php` — no asset rebuild):** the standalone layout now declares Livewire's
> runtime itself (explicit `@livewireStyles`/`@livewireScripts`, deterministic; FrontendAssets' render-guards
> prevent double-injection) plus a tiny boot guard that calls `Livewire.start()` once if the bundle loaded late,
> with a `livewire:init` flag so it can never double-start the on-time path (CSP-nonce-aware under the strict
> toggle). **Coverage (the real fix for "invisible"):** `tests/Browser/InstallerWizardTest.php` drives the FULL
> wizard in real Chrome (system → token → DB [a disposable MySQL] → site/admin → install → **Done**, then asserts
> the lock → `/install` 403s) via a new `docker/dusk/compose.yml`; `tests/Feature/Install/InstallerLayoutTest.php`
> is an in-process guard that renders `/install` with auto-injection OFF and asserts the runtime still ships and
> isn't duplicated. **Suite: Pest 314 passed / 1 skipped (1026 assertions); Dusk 3 passed (editor + full
> installer); Pint + Larastan + `composer audit` clean.** Bundle rebuilt + cold-boot-verified
> (`RELEASE_VERIFY=PASS`): `hearth-release.zip` **12,936,542 bytes**, sha256
> `b385a4bca2c9725e40a0fea6bf3ff78997d06889b3a0e25bf16627862b03bce5` (ships `bootstrap/cache/packages.php`; the
> fixed install view is inside). RH-6 → **FIXED** in [`docs/product/real-host-findings.md`](docs/product/real-host-findings.md).
> **NEXT unchanged: human real-host re-test of the subdomain install (now operable) → then RH-4 (subdirectory,
> design-first) + RH-5 (assets + CI guard).**
>
> **Update 2026-06-06 (REAL-HOST RH-7 — install-enforce middleware ate Livewire's update endpoint → FIXED — Code):**
> RH-6 was a misdiagnosis; the live-host browser replay proved Livewire boots fine and the wizard's failure is a
> **server-side middleware redirect**. **Root cause:** `app/Http/Middleware/RedirectIfNotInstalled.php` allow-listed
> `'livewire/*'`, but Livewire 4 serves its update/asset routes under a **per-install hashed prefix**
> `livewire-<hash>/...` (the hash derives from APP_KEY, `EndpointResolver::prefix()`), so the wizard's own
> `wire:click` POST to `livewire-<hash>/update` missed the allowlist and was **302-redirected to /install** — Livewire
> got HTML where it expected JSON, threw, and hard-reloaded to a blank step 1 (the "pasted the token, nothing happens"
> symptom). **Why CI missed it:** the installer suite runs with `HEARTH_INSTALL_ENFORCE=false` *and* via `Livewire::test()`,
> which **disables the middleware stack** — so the redirect never fired. **Fix (surgical):** the allowlist now matches the
> hashed endpoint two ways — a static `'livewire-*/*'` pattern (kept `'livewire/*'`) **and** the live path derived at
> runtime from `app('livewire')->getUpdateUri()` (guarded; empty never passed to `is()`); rest of the allowlist
> unchanged; a normal page still redirects. A repo sweep found no other un-hashed-prefix assumption. **Regression
> coverage (the gap, now closed):** new `tests/Feature/Install/InstallerEnforcedLivewireTest.php` runs with enforcement
> **ON** against the **real web-middleware stack** — renders `/install`, harvests the live update URI + component
> snapshot, and POSTs a faithful Livewire update (X-Livewire + JSON): it pins the hashed prefix vs. the old pattern,
> proves the middleware lets the update endpoint through (yet still redirects a non-allowlisted page), asserts the POST
> is **not** bounced to `/install`, and drives `Continue→toStep2` to **advance to step 2** end-to-end. These three
> failed on the unfixed middleware (`Expected 200, received 302 → /install`) and pass after the fix. **Suite: Pest 319
> passed / 1 skipped (1047 assertions)** (M0–M5 + P1.5 + RH-6 stay green); Pint + Larastan + `composer audit` clean.
> Bundle rebuilt (`scripts/build-release.sh`) + cold-boot-verified (fresh extract, empty APP_KEY, no DB → `GET /` →
> **302 → /install**): `hearth-release.zip` **12,937,205 bytes**, sha256
> `ebff39444dae1f6357e0f7b9c27fe5e0d4ad1ac58687d12da447ab15d27db956` (ships `bootstrap/cache/packages.php`; the fixed
> middleware is inside; `/hearth-release.zip` stays gitignored). Note: the Dusk installer journey stays
> `HEARTH_INSTALL_ENFORCE=false` on purpose (it shares a served app with the editor journey, which needs to reach
> `/forums` un-redirected); RH-7 being a server-side redirect, the enforcement-ON feature tests are the authoritative
> guard and run in the normal Pest CI. RH-7 → **FIXED** in
> [`docs/product/real-host-findings.md`](docs/product/real-host-findings.md). **NEXT: human re-uploads the rebuilt
> bundle and completes the subdomain install on the live host (the validation's primary goal) → then RH-4 (subdirectory,
> design-first) + RH-5 (stale assets + CI freshness guard).
>
> **Update 2026-06-06 (REAL-HOST RH-8 + RH-9 — post-install fixes → FIXED — Code):** the live-host install
> **completed end-to-end** (RH-7 validated on `hearth.adorablespider.com`: wizard → demo community → topics
> render). The post-install smoke then surfaced two real bugs, both fixed this pass (no new product features).
> **RH-8 — root route was the Laravel scaffold welcome page:** `routes/web.php` still had
> `Route::get('/', fn () => view('welcome'))`, so post-install `/` rendered Laravel's marketing page while the
> forum lived only at `/forums` (invisible until now — pre-install everything redirects to `/install`, and no
> test asserted `/`). **Fix:** `/` is the community home — it **301-redirects to the canonical `/forums`** (the
> lower-churn option: `/forums` is referenced across views + the sitemap, so zero references change; one
> canonical URL, no duplicate content); the scaffold `resources/views/welcome.blade.php` is **deleted**
> (clean-room). Enforcement unchanged (pre-install `/` still → wizard; cold-boot stays `302 → /install`).
> **RH-9 — security hardening × object cache = poisoned fragment cache (the `/forums` 500):** `config/cache.php`
> sets `serializable_classes => false` (P1.5 anti-object-injection — **kept**), so on a **serializing** store
> (database/file/redis = every real deployment) `DatabaseStore::unserialize(allowed_classes:false)` turns any
> cached object into `__PHP_Incomplete_Class`. `ForumController@index` cached a **live Eloquent Collection**, so
> on a cache **hit** the view's `$node->isCategory()` ran on the incomplete-class **name string** →
> `Call to a member function isCategory() on string`. The whole suite runs `CACHE_STORE=array`
> (`serialize:false`), which round-trips objects by reference — exactly why it was invisible. **Fix (keep the
> hardening, fix the data):** `index` now caches a **primitive array tree** and rehydrates read-only
> `App\Forum\ForumNode` value objects **after** the cache boundary (objects never enter the cache — a value
> object wouldn't survive either); a **repo-wide cache-write sweep** confirmed this was the only object-write
> (the queue heartbeat already stores an epoch int — the live `queue.ok:null` was the cron not yet running, not
> deserialization); a defensive note added to `config/cache.php`. **Tests (the missing class — cache HIT through
> a serializing store):** `ForumIndexCacheTest` (database store, `/forums` twice — *verified to FAIL on the
> unfixed controller with the exact live error*), `QueueHeartbeatCacheTest` (heartbeat round-trip → `queue.ok`
> never null), and a `PublicRoutesSmokeTest` (installed + demo seed; every public route, twice, no 5xx).
> **Suite: Pest 331 passed / 1 skipped (1108 assertions)** (M0–M5 + P1.5 + RH-6/RH-7 stay green); Pint +
> Larastan + `composer audit` clean. Bundle rebuilt (`scripts/build-release.sh`) + cold-boot-verified
> (`RELEASE_VERIFY=PASS`, `GET / → 302 → /install`): `hearth-release.zip` **12,924,197 bytes**, sha256
> `f48862b0aed5cef7323d4d9a8d43ad977c9ff9b90271de716e7c2fe9834c0e86` (`/hearth-release.zip` gitignored). **NEXT:
> human redeploys the rebuilt bundle (or changed files) to the live host → then RH-4 (subdirectory, design-first)
> + RH-5 (stale assets + CI freshness guard) + the Dusk enforce-ON harness split (RH-7 follow-up).****
>
> **Update 2026-06-06 (HYGIENE — RH-5 assets + CI freshness guard + Dusk enforce-ON split → DONE — Code):** the
> pre-theme hygiene closeout, per [`hygiene-rh5-dusk-kickoff.md`](docs/product/hygiene-rh5-dusk-kickoff.md). No
> product features. **(1) RH-5 — stale committed assets + drift guard.** `/public/build` is committed BY DESIGN
> (baseline shared hosts have no Node); a P1.5/RH-8 template change (utilities removed; `welcome.blade.php`
> deleted) was never rebuilt, so the committed `app.css` hash had **drifted from source**. **Confirmed + rebuilt:**
> the offline toolchain reproduced the committed JS + font assets **byte-for-byte**, so only the freshly-compiled
> `app.css` changed (`app-QDMk9TCF.css` 42,977 B → `app-BzzAoEro.css` 18,200 B; the stale CSS carried dead
> `--tw-translate/rotate/skew/space-x-reverse` utilities). Committed the `public/build` diff (manifest + the new
> hashed CSS) as `chore(assets)`. **CI guard:** the `assets` job now runs an **`assets-fresh`** step —
> `npm run build` then `git diff --exit-code -- public/build` — so any future drift FAILS CI with a clear
> "rebuild + commit" message (cheap; reuses the budget build). Rule documented in `CONTRIBUTING.md`. **Sanity
> net:** `tests/Feature/Assets/ViteManifestTest.php` renders the `@vite([...])` head + walks the manifest and
> asserts every referenced hash exists on disk (verified to fail on a stale/missing hash; hot-mode-immune) — no
> Node needed to run. **PR-CI hardening (the guard earned its keep on PR #2):** the freshness build is only valid
> if it builds under the SAME conditions as the committed assets. The first PR run exposed two non-determinisms in
> `npm run build`: (a) `app.css` `@source`s the framework's **Pagination Blade in `vendor/`**, so the Node-only
> `assets` job (no `composer install`) produced a smaller, pagination-less CSS that could never match — fixed by
> installing **composer/vendor** in the `assets` job; and (b) it `@source`s compiled Blade in
> `storage/framework/views`, so the job now **clears compiled views** for a deterministic source set. The
> canonical (vendor-present, clean-views) build is what's committed and what CI reproduces.
> **(2) Dusk enforce-ON harness split (the RH-7 follow-up).** The Dusk harness served ONE app with
> `HEARTH_INSTALL_ENFORCE=false`, so `InstallerWizardTest` never exercised real pre-install enforcement in a
> browser. Now **two sequential serve passes** (`docker/dusk/run.sh` + the CI Dusk job): **PASS 1 — INSTALLER**
> serves enforcement-**ON** with no marker on a fresh DB so the wizard's every `wire:click` flows through
> `RedirectIfNotInstalled` like production (installing into a disposable MySQL); **PASS 2 — APP** serves
> enforcement-off for `EditorJourneyTest` (unchanged). Per-pass `.env` + DB + installer sandbox, no shared state;
> the CI Dusk job gained a MySQL service + `pdo_mysql` (the wizard's install target). The enforcement-ON
> `InstallerEnforcedLivewireTest` feature tests stay the authoritative RH-7 guard; this adds the real-browser belt.
> On PR #2 **PASS 2 (editor) passed**; PASS 1 timed out at step 3→4 — the installer test lacked the deferred-
> `wire:model` settle pauses the editor test uses, so under enforcement timing on the single-threaded
> `artisan serve` the step-3 fields could serialize stale; **hardened** with settle pauses + generous timeouts.
> **Suite: Pest 333 passed / 1 skipped (1128 assertions)** (M0–M5 + P1.5 + RH-6→RH-9 stay green); Pint + Larastan
> + `composer audit` clean. **Could NOT run here (reported, not skipped silently):** the Dusk passes — this
> sandbox has no Chrome and no MySQL server (and `fonts.bunny.net` is network-blocked, which is why the rebuild
> reused the deterministic committed fonts); the harness change runs in `docker/dusk/` + the CI Dusk job. Bundle
> rebuilt + cold-boot-verified (`RELEASE_VERIFY=PASS`, `GET / → 302 → /install`): `hearth-release.zip`
> **12,918,488 bytes**, sha256 `3844efebfd8a5dbc378e7f33595ac924a45b596feb171a5427107f9c5bb22d56`
> (`/hearth-release.zip` gitignored). Small conventional DCO commits on `claude/practical-ritchie-gHg7A` (PR #2).
> **NEXT:** the hygiene board is clear → the default theme / UI polish pass ([`theme-design-brief.md`](docs/product/theme-design-brief.md));
> RH-4 (subdirectory, design-first) remains afterward.**
>
> **Update 2026-06-06 (DEFAULT THEME / UI POLISH — built, on a branch + PR — Code):** Hearth now *looks* like
> the product the brief promised. Appearance + the two named settings only — no route/data/behaviour changes,
> no theme-API breaks. Per [`theme-design-brief.md`](docs/product/theme-design-brief.md) (the taste contract)
> and the kickoff. **(PART 1 — tokens):** one CSS file (`resources/css/app.css`) of design tokens — a
> slate-neutral + indigo-accent scale and a semantic set (`--surface/--surface-raised/--ink/--ink-muted/
> --line/--accent/--accent-ink/success/warn/danger` + a `--danger-strong` button fill) with **light + dark**
> values from ONE set; Tailwind 4 utilities generated **from** the tokens (`@theme`), so child themes override
> the custom properties without touching templates (THEME-API contract intact). system-ui type scale
> (13/14/16/18/22/28) with tabular-numeral counts, radii 6/10/16, two shadows. Dark resolves on BOTH
> `prefers-color-scheme` (auto) and a manual `[data-theme]`; **density** is a `[data-density=compact]` root
> modifier scaling the `--spacing` unit (not parallel templates). a11y floor preserved (skip-link,
> `:focus-visible`, `--hearth-*` aliases) + reduced-motion. **(PART 2 — appearance settings, the only
> behaviour additions):** per-user **colour mode** (auto/light/dark) + **density** (comfortable/compact) on
> the users table (reversible); `settings/appearance` form works with **NO JavaScript** (server-rendered
> `<html>` attributes), a header toggle + footer switch persist via fetch, guests via an inline no-flash boot
> snippet + localStorage; covered by `tests/Feature/Settings/AppearanceTest` (persistence + rendering effect +
> guest defaults + validation). **(PART 3 — pages/components):** a token-driven Blade component library
> (`resources/views/components/ui/*` — button/input/badge/alert/card/avatar+initials/breadcrumbs/tabs/
> dropdown/modal/toggle/empty/container/icon) + a restyled global shell (header with wordmark/search/colour
> toggle/notification bell/user menu, mobile nav, breadcrumb bar, flash, footer); ALL core + staff pages
> restyled (forum index/view/topic + composers, auth, search, profiles, notifications, settings, moderation,
> admin) via a 7-group parallel agent fan-out, each adversarially reviewed; friendly **error pages**
> (404/403/500/503/419/429, self-contained so they render even on a 500); the standalone **installer**
> recoloured to the new palette and **decoupled from the app CSS bundle**. **(PART 4 — gates):** mobile-first,
> verified good at **360px** (the guest header overflow was found in a screenshot and fixed); WCAG AA token
> contrast in both modes; ≥44px touch targets; styling never needs JS; **CSS bundle 7.8 KB gz** (budget 50).
> **(PART 5 — build determinism):** dropped the bunny.net font plugin (system-ui only → fully offline build);
> `app.css` uses `source(none)` + `@source`s only the app's own tracked sources (no `vendor/`, no
> `storage/framework/views`); published + restyled our **own pagination views** (so the vendor `@source`
> could go); deleted the committed font assets/manifest; the CI `assets` job is now Composer-free; rebuild
> rule documented in `CONTRIBUTING.md`. **Evidence:** **Pest 342 passed / 1 skipped (1143 assertions)**
> (M0–M5 + P1.5 + RH-6→RH-9 all stay green); Pint + Larastan + `composer audit` clean; **Dusk 3 passed**
> (editor journey under the restyled composer + the **screenshot gate**); `assets-fresh` reproduces the
> committed bundle byte-for-byte. **Screenshots** (light/dark × mobile/desktop of the four core pages) at
> [`docs/product/theme-screenshots/`](docs/product/theme-screenshots/README.md) — *font caveat: headless
> Debian Chromium falls back from system-ui, so judge layout/colour/contrast, not the font face.* Bundle
> rebuilt + cold-boot-verified (`RELEASE_VERIFY=PASS`, `GET / → 302 /install`): `hearth-release.zip`
> **12,788,269 bytes**, sha256 `5a3a22fefb1e7038ea6e6f3e1f5c4cc5d39da509cbe740050e1a94828724d953`. Small
> conventional DCO commits on branch **`claude/default-theme`** (PR opened). *(An unrelated, additive
> `docs/product/status-review-2026-06-06.md` commit from a parallel Cowork session landed on the branch — no
> overlap with the theme work.)* **NEXT: owner reviews the PR screenshots against the brief → iterate/merge →
> deploy the themed bundle to the live host (the first deploy that *looks* like Hearth) → then RH-4
> (subdirectory install, design-first + ADR) and Phase 2 (Community).**
>
> **Update 2026-06-07 (THEME POLISH ROUND 1 — owner visual feedback, on PR #3 — Code):** executed Part A of
> [`theme-polish-round-1.md`](docs/product/theme-polish-round-1.md) on `claude/default-theme` (PR #3 stays
> open — not merged). Presentation only; every theme gate still holds; no new columns/behavior (the data
> model already carried it). Benchmarked to the ProBoards reference the owner supplied. **(1) Topic view —
> classic LEFT poster sidebar** (owner default): desktop posts get a left column (larger avatar, display name,
> a staff/role badge derived from the eager-loaded `author.groups`, joined date + post count from loaded
> columns), body to the right; collapses to the top-bar layout on mobile. `.hearth-prose`, `id=post-*`, the
> SEO `@push('head')`, moderation controls, and the reply-composer island all preserved (the admin-switchable
> top/left/right option is Part B). **(2) Board view (forum/show) — info-dense topic table:** a real desktop
> table (Subject + “by starter” · Replies · Views · Last post → links to the topic's latest page) reflowing to
> stacked rows on mobile (no horizontal scroll); tabular numerals; pinned/locked badges on the subject cell;
> `view_count` rendered as stored. **(3) Sub-boards block:** a ProBoards-style card above the table when the
> forum has children (permission-filtered with the same `forum.view` check the index uses, reusing
> `forum-row`). **(4) Forum index rows:** right-aligned “latest activity” (`last_posted_at` + a link via
> `last_topic_id`), collapsing on mobile. **(5) Breadcrumbs:** a nav-tree (home icon + chevrons) in the
> prominent under-header bar on board + topic pages. **Plumbing (read-only):** `Topic::lastPostUser` +
> `Forum::lastTopic` belongsTo on existing columns (maintained by `Post::syncAggregates`); `ForumNode` carries
> `last_posted_at`/`last_topic_id` as cache-safe primitives (Carbon rehydrated after the boundary — RH-9);
> bounded eager-loads only (`lastPostUser`, `author.groups`, `children`, topic `forum`+`author`), all within
> the **≤30 thread / ≤15 index** query budgets. **Adversarial review:** a 6-lens parallel audit (tokens, AA,
> 360px, contracts, N+1/correctness, completeness) — tokens/responsive/contracts clean; fixed the two WCAG
> 1.4.1 (use-of-colour) in-row link affordances, the un-eager-loaded topic forum/author, the mobile board
> last-post parity, and a dt/dd nit. **Evidence:** **Pest 347 passed / 1 skipped (1162 assertions)** (+5 new
> board-table tests; all prior suites stay green); Pint + Larastan + `composer audit` clean; **Dusk 3 passed**
> (editor journey under the restyled composer + the refreshed screenshot gate); `assets-fresh` reproduces the
> committed bundle (CSS **8.0 KB gz**, budget 50). **Screenshots refreshed** (board table + sub-boards + left
> sidebar + index + auth + settings, light/dark × mobile/desktop) at
> [`docs/product/theme-screenshots/`](docs/product/theme-screenshots/README.md). Bundle rebuilt +
> cold-boot-verified (`RELEASE_VERIFY=PASS`, `GET / → 302 /install`): `hearth-release.zip` **12,791,419 bytes**,
> sha256 `d8b177ab0d76fd96bef3ed0ee38ecd492507738c7e91d948048ca68c98ce1681`. Small conventional DCO commits on
> `claude/default-theme`. **NEXT: owner re-reviews the refreshed PR #3 screenshots → iterate or merge → deploy;
> Part B (community-feel pack: info center, group colours, view-count incrementing, poster-position option) is
> triaged for Phase 2.**
>
> **Update 2026-06-07 (RH-10 — no-SSH auto-upgrade: "it migrates automatically" is now TRUE — Code):** built on
> a branch from **main** (theme PR #3 already merged), per [`rh10-auto-upgrade-kickoff.md`](docs/product/rh10-auto-upgrade-kickoff.md).
> **The gap:** getting-started §5 promised auto-migration on deploy, but nothing implemented it — the only
> `migrate` was at install time. Extracting the themed release over a live install runs new code against the
> old schema **with no way to migrate** — saving Appearance settings errors (a write to the not-yet-existing
> `users.color_mode`/`density`), and any future destructive migration breaks pages site-wide (additive *reads*
> degrade to `null`, strict mode off — so it's the missing migrate path, not a guaranteed every-page 500,
> that's the gap). **Fix (ADR-0021, `App\Upgrade`):** (1)
> **`SchemaState`** — O(cache-read) request-path detection via a cached flag + a release **fingerprint**
> (sha256 of the deployed migration filenames; a glob, no DB) that gates the instant new code lands (closing
> the deploy→first-tick window), refreshed by the scheduler tick's real `migrator` check; `GET /health` gains a
> non-secret `schema` block (`pending`/`upgrading`/`stuck`/`auto`/`last`). (2) **`UpgradeRunner`** — an
> every-minute `Schedule::call` (`withoutOverlapping` + a cache lock) that, when pending: enter maintenance →
> **backup first** (failure aborts) → `migrate --force` in-process → refresh caches → exit maintenance →
> audit-log; killed mid-run, it resumes idempotently next tick. (3) **`PreventRequestsDuringUpgrade`** — a
> branded **503** (Retry-After, self-refreshing) for every request except `/health`+assets; never a SQL error.
> (4) **Failure** — roll back only this run's batch, hold (`schema.stuck`, no retry loop) after
> `max_auto_attempts` (default 2), self-clearing when the operator re-uploads the previous release. (5)
> **Controls** — `HEARTH_AUTO_UPGRADE=true` default; `false` = manual via *Admin → System → Upgrade* (new SFC,
> admin+2FA+confirm) or `php artisan hearth:upgrade`; documented asymmetry (auto protects signed-in pages,
> manual keeps the panel reachable). **No new dependencies. No theme/installer/Phase-2 changes (scope fence
> held).** **Evidence (Docker `php:8.3`):** **Pest 378 passed / 1 skipped (1286 assertions)** — +36 RH-10 tests
> (detection on/off · lock · backup→migrate ordering & abort · failure→rollback+stuck+maintenance · health
> schema · 503-not-SQL in-window · auto-off+manual apply), all prior suites stay green; Pint + Larastan +
> `composer audit` clean; `assets-fresh` reproduces the committed bundle (Blade-only changes, no asset drift);
> Dusk unaffected (the gate is dormant without schema drift). Docs updated: getting-started §5 (window/toggle/
> recovery) + §6, REAL-HOST-VALIDATION §6a (live upgrade runbook), real-host-findings RH-10 → FIXED, ADR-0021.
> Small conventional DCO commits on **`claude/rh10-auto-upgrade`**. **An adversarial multi-lens review of the
> diff was run** (34 agents): 2 HIGH hard-kill failure modes found + fixed (a 24h overlap-mutex strand, and a
> killed-mid-run `upgrading` flag wedging the site) + 4 nits; 6 findings verified-and-refuted. **Release bundle
> rebuilt + cold-boot-verified** (`RELEASE_VERIFY=PASS`, `GET / → 302 /install`) from the branch (= main +
> RH-10, linear): `hearth-release.zip` **12,813,544 bytes**, sha256
> `451def6a40c3aed76ff3c3dfc235bc221a0c0ae39d2db5d101f3368ea2c30b5d` (ships `bootstrap/cache/packages.php`;
> `/hearth-release.zip` stays gitignored). **NEXT (human): push `claude/rh10-auto-upgrade` + open the PR
> (this env has no `gh`); merge on top of the theme; re-run `scripts/build-release.sh` post-merge for the
> canonical artifact (identical to the above unless main moved — docs are excluded from the bundle), then
> deploy it. Deploying it IS RH-10's first real-world validation: extract the zip over the live install and,
> within ~2 min, `GET /health` `schema.pending` flips true→false and Appearance settings start working — the
> appearance migration applies itself via cron, no SSH (live script in the kickoff + REAL-HOST-VALIDATION §6a).**
>
> **Update 2026-06-07 (RH-11 — no-SSH panel restore: the Backups panel can now RESTORE → the last beta gate —
> Code):** built on a branch from **main**, per [`rh11-panel-restore-kickoff.md`](docs/product/rh11-panel-restore-kickoff.md).
> **The gap:** `hearth:restore` existed only as a CLI command; *Admin → System → Backups* could create/download
> but not restore, so a no-SSH operator had **no recovery path** (same documented-but-unimplemented class as
> RH-10). **Fix (ADR-0022, `App\Backup`):** a **cron-driven, file-coordinated** restore wrapping the existing
> `RestoreService` in the RH-10 choreography (`RestoreRunner`, sibling of `UpgradeRunner`). **The load-bearing
> design call:** a restore overwrites the live DB — and on the baseline tier the cache, session, AND queue all
> live in it — so the maintenance state is a **file** (`RestoreState`, outside `storage/app`, surviving the DB
> swap) and the run is drained by the **single cron line** in CLI context (no web timeout, no self-wiping
> DB-queue job, no mid-request session/cache churn). (1) **Choreography:** validate (manifest + streamed
> SHA-256 + **restorable into THIS engine** — refuse before touching anything) → **pre-restore safety
> snapshot** (the restore is itself reversible) → restore DB+storage from a private temp copy of the target →
> flush caches + `SchemaState::refresh()` → audit. (2) **RH-11 → RH-10 hand-off (tested):** a restored OLDER
> schema is re-detected, the RH-10 gate holds the site, and the auto-upgrade tick migrates it forward;
> `UpgradeRunner` + every DB-touching cron job stand down during the restore window. (3) **Gate + health:**
> `PreventRequestsDuringUpgrade` serves the branded 503 (restore variant) from the file state first; `/health`
> gains a non-secret `restore` block (a stuck restore → degraded). (4) **Panel:** each row gains **Restore** —
> admin.access + **staff-2FA** (self-guarded) + a **typed confirmation** (the backup's exact name); it records
> the request then redirects to the self-refreshing maintenance page. (5) **Failure (single-attempt,
> fail-safe):** never auto-retried — a validation failure lifts the gate; a restore-step failure or a crash
> mid-restore (detected via the free file lock + a stale `running`) HOLDS the site (`restore.stuck`); no-SSH
> recovery = delete `storage/hearth-restore.json` via the file manager, then re-restore. CLI `hearth:restore`
> routes through the same runner. **No new dependencies. No theme/installer/upgrade scope creep.** **FLAGGED
> follow-up (not built):** restore from an UPLOADED off-host archive (guarded untrusted-zip upload) — noted in
> the findings. **Adversarial review of the diff was run** (22 agents, 6 lenses + per-finding verification): a
> **HIGH** (a CLI mid-restore failure below the old retry cap lifted the gate over a half-restored DB) + 5
> MEDIUM (queue:work not stood down; an unbounded hard-kill resume loop; a safety snapshot taken of a
> half-restored DB on resume; no no-SSH stuck recovery) + LOW/NIT — **all fixed** by the single-attempt
> redesign + the cron stand-down + the file-delete recovery + early engine-mismatch refusal + test isolation
> (2 findings refuted). Docs updated: getting-started §5, REAL-HOST-VALIDATION §6/§6b, real-host-findings
> RH-11 → FIXED, ADR-0022, `.gitignore` + `.env.example`. **Could NOT run here** (this env has no
> PHP/Composer/Docker/MySQL — confirmed): the Pest suite, Pint, Larastan, `composer audit`, and the release
> rebuild (`build-release.sh`/`verify-release.sh` → size + sha256) are the **Docker `php:8.3` / human step**,
> as in every prior RH-* pass. Change is server-rendered + PHP only (no asset rebuild). Small conventional DCO
> commits on **`claude/rh11-panel-restore`**. **NEXT (human): run the suite + rebuild the bundle in Docker;
> push the branch + open the PR (no `gh` here); merge; deploy; then the live rehearsal in the kickoff §"Live
> rehearsal" (create → download → marker post → restore from the panel → marker gone, `/health` green, audit
> shows the restore) closes the last beta gate.**
>
> **Update 2026-06-07 (ACP v1 — admin shell, dashboard, structure manager, settings, system surface — Code):**
> built the **admin control panel** so the operator is self-sufficient (no more URL-guessing or `.env`
> hand-edits), per [acp-v1-kickoff.md](docs/product/acp-v1-kickoff.md). Branch **`claude/acp-v1`** off main
> (includes RH-11), 6 conventional DCO commits. **No new dependencies.**
> - **PART 0 — settings infrastructure (ADR-0023):** a `settings` table + typed `App\Settings\Settings` on a
>   code `SettingsRegistry`. Precedence **DB row → config() (folds env→default) → registry default**; defaults
>   are **not** seeded as rows, so a panel override persists across deploys while an unset key tracks
>   env/config (the owner's new-user-hold case). Whole bag read once/request, cached as **primitives only**
>   (RH-9), write-through invalidation, **defensive read** (safe pre-install / during `package:discover`).
>   Secrets encrypted + **masked in the audit log**, never echoed (placeholder forms). `applyToConfig()` pushes
>   overrides into live `config()` so the mailer / anti-spam / `app.name` / theme honour them **unchanged**.
> - **PART 1 — shell + dashboard:** `<x-admin.shell>` = a persistent grouped left nav (Overview · Settings ·
>   Members · Content · Moderation · System) from one `AdminNavigation` source the **client-side quick-search**
>   also indexes (pages + settings labels). `/admin` dashboard: pending-actions row → stat cards (cached) →
>   health strip (reuses the `/health` internals in-process) → recent audit. **Authz:** every admin route/SFC
>   on `admin.access` + staff-2FA; an **authz-walk test** asserts every GET admin page denies non-admins.
> - **PART 2 — forum structure manager (the owner's #1 ask):** category→board→sub-board tree with
>   create/edit/reorder; **binding delete-safety** — a board with topics is deleted only by choosing a
>   destination (StructureService moves topics + recomputes both counters authoritatively + audits); never
>   silent. New boards inherit the global role presets (usable immediately, documented). Per-node link to the
>   permission inspector (`?scope=` pre-fill).
> - **PART 3 — six settings pages** (general, registration, email + **test-send**, moderation, anti-spam,
>   appearance) — each a focused Livewire SFC on PART 0. Board-offline 503 + site notice; registration on/off +
>   email-verification toggle (existing mechanisms); SMTP password encrypted w/ placeholder; appearance
>   (theme, **AA-safe accent CSS vars**, `--layout-max-width` width, visitor mode/density, poster position,
>   board-list style, wordmark) read inline by the layout + topic + board views (presentation only — Dusk
>   selectors + markup contracts preserved). Knobs without a mechanism (post-edit window; approval/invite
>   modes) are **flagged, not invented** (scope fence).
> - **PART 4 — system surface:** migrated service-tier/permissions/backups/upgrade/custom-fields into the shell
>   (behaviour unchanged); **NEW** read-only **audit-log viewer** (paginated, filterable) + **Tasks** page
>   (the single-cron-line schedule + last-run where knowable).
> - **Evidence (Docker `php:8.3`; this box has no host PHP):** **Pest 451 passed / 1 skipped (1593
>   assertions)** — all prior suites stay green; **Pint** clean (315 files); **Larastan** clean; `composer
>   audit` + `npm audit` clean; **CSS 8.34 KB gz** (budget 50), `assets-fresh` reproducible. ADR-0023 +
>   getting-started §4 (ACP) updated. **Admin Dusk journey + screenshot gate** added
>   (`tests/Browser/AdminJourneyTest.php`: login → dashboard → create a board → see it on the public index;
>   captures `acp-*.png` dashboard/structure/settings/audit, light/dark × desktop/mobile) and wired into
>   `docker/dusk/run.sh` PASS 2 + the CI Dusk job — **run in the Docker Dusk harness / CI** (Chrome + MySQL),
>   like the theme screenshots. **Could NOT complete the local Dusk run in this sandbox:** `php artisan serve`
>   was unstable here (port-bind race + broken-pipe), so every page 500'd — which **also failed the
>   pre-existing `EditorJourneyTest`**, confirming an environment issue, not an ACP defect (all 451 Pest tests
>   pass). The Dusk passes + the four screenshot sets therefore run in the **CI Dusk job** (clean runner; prior
>   passes were green there) / a human Docker run. **Release bundle rebuilt + cold-boot-verified** (`RELEASE_VERIFY=PASS`,
>   `GET / → 302 /install`): `hearth-release.zip` **12,889,984 bytes**, sha256
>   `5c4472a943f015f81589bb8d37f7a59ebb498248f144c194f5a5541a28b30e24`. **Concurrency note:** a parallel
>   session is renaming the project **Hearth → NevoBB** (CLAUDE.md +
>   ADR-0024) in the same tree — left untouched by this branch; reconcile at merge. **NEXT (human): push
>   `claude/acp-v1` + open the PR (no `gh` here) with the four screenshot sets; merge; deploy. After deploy,
>   flip "new-user first-post hold" to 0 from Admin → Settings → Moderation (replaces the temporary config
>   edit, which the deploy overwrites).**

1. **Reconcile the stack sign-off:** update `CLAUDE.md` and the brief to **13 / 4 / 8.3**; mark
   **ADR-0001/0002 Accepted** (drop "flagged for sign-off"); **apply the two polish items** (2FA row,
   Akismet phase note). Add a note that Laravel 13's **Reverb database driver (no Redis)** is
   available for the enhanced tier (baseline stays on polling — Reverb still needs a running process).
2. **Draft the Phase 1 plan-before-code** (no code yet). **Sequence the WYSIWYG<->Livewire spike
   first**, validating the **`wire:ignore` + Alpine island pattern** (with the Vue/React component bridge as fallback) as
   the TipTap integration mechanism. The spike must have an explicit **go/no-go criterion and a
   fallback path** before anything is built on it. Then the rest of Phase 1: skeleton + **service-tier
   detection / driver abstraction**, **no-SSH web installer**, auth (register/verify/sessions), forum
   CRUD (categories->forums->topics->posts, server-rendered), the **permission-mask engine**,
   moderation queue, the editor, email notifications, theme foundation, **backups + reversible
   migrations** — **runnable on PHP 8.3 + MySQL + cron**.
3. **Hold for plan approval.** No application code until the owner signs off on the Phase 1 plan.

## Working rules (full list in `CLAUDE.md`)

Strict clean-room (study schemas/semantics/concepts; importers copy *data*, never code) · progressive
enhancement (no baseline feature hard-depends on Redis / WebSocket / worker / external search) ·
reversible, non-destructive migrations · security by default (OWASP, argon2id, CSRF, CSP, rate limits,
audit log, sanitized rich text) · tests with every feature (permission-mask + tier-fallback suites
dedicated) · semver'd module/theme API contracts · conventional commits, ADRs for non-obvious choices.

## Model & effort (account is on Claude Max)

**Code tab:** Opus 4.8 at **`xhigh`** effort (set explicitly; default is `high`). Reserve deep
reasoning for permission-mask resolution, anti-spam, security, importers, the plugin/theme API, and
the editor spike; Sonnet 4.6 for mechanical breadth.
