# PROJECT-HISTORY.md — NevoBB completed milestone log

> Completed milestone records moved here from `PROJECT-STATE.md` to keep that file lean.
> **This file is reference-only** — do not load it every session. Read it when you need context on
> a specific past decision or implementation detail. Append new completed milestones here when
> PROJECT-STATE.md is updated.

---

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

> **Update 2026-06-02 (Spike 0 EXECUTED → GO):** all six criteria **PASS** with executed evidence — **Pest 10
> passed / 82 assertions** (incl. the #4 security suite) and **Playwright 6/6** (incl. the #1a GO-blocker, both
> paths). Run in a **Docker `php:8.3`** env (this box has no host PHP) + host Node/Playwright/Chromium. Memo:
> [`docs/product/spike-0-memo.md`](docs/product/spike-0-memo.md); reference scaffold in `hearth-spike/` (source
> committed, heavy artifacts git-ignored; env in `.spike-docker/`). **Key findings:** Livewire 4 = single-file
> components; the **editor must be non-reactive closure state** (a reactive proxy breaks ProseMirror →
> "mismatched transaction"); deferred `$wire.set` needs no debounce. **No fallback needed — ADR-0012 stands.**
> **NEXT: owner gate → begin Phase 1 M0** (port the validated pattern into the app at the repo root).

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

> **Repo baseline (2026-06-02):** `D:\Forum` is now **git-tracked** — first commit `a875a9a` on branch `main`
> (27 files, DCO sign-off), so Code and Cowork can commit between handoffs. The ready-to-paste Code kickoff
> prompt is saved at [`docs/product/spike-0-code-kickoff.md`](docs/product/spike-0-code-kickoff.md).
> *(Cowork-env caveat: the `D:\Forum` bash mount mangles git's own `config` write — if you must git-operate
> from Cowork, enable deletes via the file-delete permission and hand-build `.git` with plain writes. The Code
> build env has no such limitation.)*

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

> **Update 2026-06-02 (M1 DONE — Code):** **Phase 1 M1 (Identity & access) complete.** Two pillars.
> **(1) The permission-mask engine (ADR-0006 / security §1.2, implemented exactly):** three-state
> ALLOW/NO/NEVER over the global→category→forum→thread scope chain; NEVER short-circuits, user overrides
> group, groups merge most-permissive, deny-by-default; **NO = neutral/inherit** (interpretation "ii",
> reconciled with §1.1/§2.3 + phpBB's tri-state — flagged for explicit sign-off; the single flip-point
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
> stays green. **`hearth-spike/` retired.** **NEXT: M3 — Anti-spam baseline & moderation (ADR-0007).**

> **Update 2026-06-03 (M3 DONE — Code):** **Phase 1 M3 (Anti-spam baseline & moderation, ADR-0007) complete.**
> The whole subsystem is **unified with the M1 permission engine — no second permission system.**
> **(1) Trust→ACL gating:** TL gates seeded as `acl_entries` on TL0–TL4 from a config matrix
> (`config/hearth.php`) — TL0 = NEVER on links/images/mass-PM (absolute; an admin ALLOW cannot lift it,
> pinned by a test), TL1+ = ALLOW; attachments stay an admin-liftable soft seam. Enforced by **link/image
> suppression at the shared sanitize step**. Auto promotion/demotion via idempotent `hearth:trust:recompute`
> cron. **(2) Registration layer:** tri-state **allow / flag→pending / block** from StopForumSpam,
> disposable-email, honeypot+encrypted-timing, IP velocity + a `CaptchaProvider` abstraction (Q&A baseline,
> Turnstile pluggable). **(3) Posting/reactive:** `ContentScanner` contract + word filters + new-user
> moderation queue + per-trust rate limiting + Spam Cleaner + user/IP/email/range bans. **(4) Moderation + MCP:**
> approval queue, reports, warnings/infractions (typed, point-weighted, time-decaying, threshold consequences).
> **Pest 212 passed / 674 assertions**; all prior suites stay green. **NEXT: M4.**

> **Update 2026-06-03 (M4 DONE — Code):** **Phase 1 M4 (Notifications · Search · SEO · Theme) complete.**
> No new dependencies. **(1) Notifications:** custom merge-aware `Notifier`, database + mail channels,
> per-event×channel preferences, `email_suppressions`, Livewire polling bell. **(2) Search (ADR-0010):**
> Scout `Searchable` on `body_text`, DB driver (MySQL FULLTEXT/LIKE), degrades to direct DB query when
> Meilisearch absent, per-user read-watermark (`topic_reads`). **(3) SEO:** canonical URLs, Open Graph,
> schema.org DiscussionForumPosting JSON-LD, cached XML sitemap, robots. **(4) Theme (ADR-0009):** semver'd
> Blade override layer (`ThemeManager`, THEME API **v1.0**), a11y floor baked in (skip-link, `:focus-visible`,
> AA-contrast CSS tokens). **(5) Profiles:** signatures, custom fields, avatars/covers. **Pest 247 passed /
> 760 assertions**; all prior suites stay green. **NEXT: M5.**

> **Update 2026-06-03 (M5 DONE → Phase 1 / MVP COMPLETE — Code):** **Core MVP is shippable. No new
> dependencies.** **(1) No-SSH web installer (ADR-0020):** browser wizard + `InstallRunner` (shared with
> `hearth:install` CLI); write `.env` → DB → migrate → seed → create admin → `storage:link` → LOCK last.
> Lock = `storage/installed` file marker, written last; installer 403s once present; no re-trigger vector.
> **(2) Backups + restore:** `hearth:backup` (cron + `--keep`), `hearth:restore` (manifest+SHA-256), Admin →
> Backups panel. **(3) Health:** `GET /health` (DB, cache, cron freshness, tier, install state). **(4) One
> cron line (ADR-0011).** **(5) Demo seed + getting-started.** **(6) `.env.example` finalized.** **(7) Perf
> budgets in CI.** **(8) Dusk executed: 2 passed.** All six Phase 1 exit criteria met. **Pest 272 passed /
> 879 assertions.** Verified in Docker `php:8.3` + `mysql:8`.

> **Update 2026-06-03 (PHASE 1.5 — Validation & Hardening — Code):** adversarial security review + real-host
> readiness pass. **Fixed (each with regression test):** (H-1) attachment IDOR; (H-2) TL0 link/image
> suppression covers signatures; (H-3) pure-PHP MySQL dump+restore fallback; (M-1) narrow `User`
> mass-assignment; (M-2) installer pre-checks marker writability; (M-3) baseline `SecurityHeaders` middleware
> (CSP + nosniff + frame-ancestors); (M-4) `.env` written 0600; (L-1) `APP_DEBUG` forced off pre-install;
> (L-2) HTTPS-only cookie on https install. **Part 2:** `hearth:doctor` preflight,
> `PublicStorageLinker` copy-based fallback, `docs/REAL-HOST-VALIDATION.md`. **Pest 289 passed / 940 assertions.**

> **Update 2026-06-03 (PHASE 1.5 — Security Fix Pass — Code):** owner chose to fix ALL ten flagged items
> (F-A..F-M3 + tenant_id). **Fixed:** F-A setup token (install-token.txt); F-B rate-limit + mandatory
> honeypot + single-use Q&A nonce; F-C StopForumSpam fail-safe + `hearth:antispam:warm`; F-D trust promotion
> needs topics-read signal; F-E trust change re-renders posts; F-F actor-vs-target rank check; F-G explicit
> `$fillable` on six ACL models; F-H `tenant_id` removed from User mass-assignment; F-I auth-event audit
> logging; F-M3 strict nonce CSP behind `HEARTH_CSP_STRICT` toggle. **Pest 310 passed / 1012 assertions.**

> **Update 2026-06-05 (REAL-HOST RH-6 — installer wizard front-end FIXED — Code):** root cause: Livewire
> auto-starts from `DOMContentLoaded` listener with no `readyState` fallback; shared-host JS optimizer
> deferred the script past the event → `start()` never ran → directives unbound. Fix: standalone install
> layout declares Livewire runtime explicitly + a boot guard. Coverage: `InstallerWizardTest` drives the full
> wizard in real Chrome; `InstallerLayoutTest` in-process guard. **Pest 314 passed / 1026 assertions; Dusk 3.**
> `hearth-release.zip` sha256 `b385a4bca…`.

> **Update 2026-06-06 (REAL-HOST RH-7 — install-enforce middleware ate Livewire's hashed endpoint — Code):**
> root cause: `RedirectIfNotInstalled` allow-listed `'livewire/*'` but Livewire 4 serves updates under
> `livewire-<hash>/...`; every `wire:click` POST was 302'd to `/install`. Fix: allowlist matches
> `'livewire-*/*'` + the live path from `app('livewire')->getUpdateUri()`. New enforcement-ON feature test.
> **Pest 319 passed / 1047 assertions.** `hearth-release.zip` sha256 `ebff3944…`.

> **Update 2026-06-06 (REAL-HOST RH-8 + RH-9 — post-install fixes — Code):** live install completed
> end-to-end. RH-8: root route served Laravel welcome page; fixed to 301-redirect `/` → `/forums`; deleted
> `welcome.blade.php`. RH-9: `serializable_classes => false` (anti-object-injection, kept) + Eloquent
> Collection cached = `__PHP_Incomplete_Class` on cache hit; fixed to cache primitive array tree + rehydrate
> read-only `ForumNode` value objects after boundary. `ForumIndexCacheTest` verified to fail pre-fix.
> **Pest 331 passed / 1108 assertions.** `hearth-release.zip` sha256 `f48862b0…`.

> **Update 2026-06-06 (HYGIENE — RH-5 assets + CI freshness guard + Dusk enforce-ON split — Code):**
> RH-5: stale committed `app.css` drifted from source (P1.5/RH-8 template change never rebuilt); rebuilt,
> added `assets-fresh` CI step (`npm run build` → `git diff --exit-code`). Vendor+compiled-views required
> for deterministic build. Dusk enforce-ON harness split: PASS 1 (installer, enforcement-ON, fresh DB),
> PASS 2 (app/editor, enforcement-off). **Pest 333 passed / 1128 assertions.**

> **Update 2026-06-06 (DEFAULT THEME / UI POLISH — Code):** Hearth now looks like the product.
> PART 1: design tokens (`--surface/--ink/--accent/…`, light+dark, density modifier). PART 2: per-user
> colour mode + density settings (reversible columns, JS-free server-rendered). PART 3: Blade component
> library (`ui/*` — button/input/badge/alert/card/avatar/…) + restyled global shell + all core pages
> restyled via 7-group parallel agent fan-out. PART 4: mobile-first, WCAG AA tokens, ≥44px touch targets,
> CSS 7.8 KB gz. PART 5: dropped bunny.net font (system-ui, offline build); `source(none)` + own `@source`s;
> own pagination views. **Pest 342 passed / 1143 assertions; Dusk 3 passed; assets-fresh reproducible.**

> **Update 2026-06-07 (THEME POLISH ROUND 1 — Code):** classic LEFT poster sidebar on desktop (avatar,
> display name, staff/role badge from `author.groups`, joined date, post count); info-dense topic table on
> board view (Subject · Replies · Views · Last post); sub-boards card; right-aligned latest activity on
> forum index; breadcrumbs nav-tree. Adversarial 6-lens review: fixed two WCAG 1.4.1 in-row link affordances,
> un-eager-loaded topic forum/author, mobile board parity. **Pest 347 passed / 1162 assertions; CSS 8.0 KB gz.**

> **Update 2026-06-07 (RH-10 — no-SSH auto-upgrade — Code):** `SchemaState` (O(cache-read) detection +
> release fingerprint) + `UpgradeRunner` (every-minute Schedule::call, withoutOverlapping, backup-first,
> migrate→flush→exit-maintenance, idempotent on kill) + `PreventRequestsDuringUpgrade` (branded 503). Controls:
> `HEARTH_AUTO_UPGRADE=true` default; false = Admin → System → Upgrade SFC. Adversarial review (34 agents):
> 2 HIGH fixed (24h overlap-mutex strand; killed-mid-run `upgrading` flag wedge). **Pest 378 passed / 1286
> assertions.** ADR-0021.

> **Update 2026-06-07 (RH-11 — no-SSH panel restore — Code):** `RestoreRunner` (cron-driven, file-coordinated,
> wraps `RestoreService` in RH-10 choreography). Load-bearing: file-based maintenance state (survives DB swap),
> drained by single cron line. Choreography: validate + engine-mismatch check → pre-restore safety snapshot →
> restore DB+storage → flush → audit. RH-11→RH-10 hand-off tested. Panel: each backup row gains Restore
> (admin.access + staff-2FA + typed confirmation). Failure: single-attempt, fail-safe; never auto-retried.
> Adversarial review (22 agents): HIGH + 5 MEDIUM fixed. ADR-0022.

> **Update 2026-06-07 (ACP v1 — admin shell, dashboard, structure manager, settings, system surface — Code):**
> ADR-0023. PART 0: `settings` table + typed `Settings` on a `SettingsRegistry`; precedence DB→config()→default;
> secrets encrypted + masked in audit log. PART 1: `<x-admin.shell>` grouped left nav + `/admin` dashboard
> (pending-actions, stat cards, health strip, recent audit); authz-walk test. PART 2: forum structure manager
> (create/edit/reorder; binding delete-safety via StructureService). PART 3: six settings pages (general,
> registration, email+test-send, moderation, anti-spam, appearance — AA-safe accent CSS vars, layout width,
> poster position, etc.). PART 4: system surface (service-tier, permissions inspector, backups, upgrade,
> custom-fields, audit-log viewer, Tasks). **Pest 451 passed / 1593 assertions; CSS 8.34 KB gz.** Admin Dusk
> journey + screenshot gate wired (`AdminJourneyTest`: login→dashboard→create board→public index; light/dark ×
> desktop/mobile). Release `hearth-release.zip` sha256 `5c4472a9…`.

> **Update 2026-06-08 (ACP v1.1 — post-deploy bug patch — Code):** two live bugs + test gap.
> BUG 1: registration SFC `gates()` type-hinted `Settings $settings` but called arg-less from Blade → 500;
> fix: resolve `app(Settings::class)` internally. Swept all 17 admin/settings SFCs — only instance.
> BUG 2: "Forum width" didn't govern topic view — topic/search containers pinned `size="md"`; fixed to
> `size="lg"`. TEST GAP: authz-walk tested non-admin denial but not admin render; added mirror
> (`AdminAccessWalkTest`) + width regression guard. Asset build byte-identical.

> **Update 2026-06-08 (SPIKE P2 — baseline deliverability → GO — Code):** cron-batched digest with
> exactly-once ASSEMBLY (transactional UNIQUE row claim + floored period_key + two-phase `mailed_at` self-heal)
> + daemon-free tri-path bounce ingestion (HMAC webhook / cron-polled IMAP+VERP / manual ACP floor) + volume
> hygiene (per-tick send cap, period-bucketed). Branch `claude/spike-p2-deliverability`; PR #8 merged.
> Adversarial 6-lens review (19 agents): HIGH (bounce parser trusted unauthenticated body headers → fixed to
> VERP-only identity) + MEDIUM (gated user re-scanned forever → period retired). GO criteria → 6 permanent
> test files in `tests/Feature/Deliverability/*`. No new dependencies. Dormant behind
> `hearth.deliverability.enabled` (default false).

> **Update 2026-06-08 (ACP v2 — member-group manager + staff/group name colours — Code):**
> PART 1: member-group manager (`Admin → Members → Groups`). `GroupManager` service (system-protection,
> delete-with-reassign, membership boundary, permissions via `RoleExpander`). `⚡groups` SFC (mirrors
> `⚡structure`). Priority cap 79 (below Moderators). Every change audited. PART 2: staff/group name colours.
> AA-safe `GroupColor` palette → `--group-*` tokens (light + both dark). `User::displayGroup()/nameColor()`
> (highest-priority coloured group wins). `<x-ui.user-name>` component at 11 name sites; `.groups`
> eager-loaded. Schema: `groups.description` new only (reused pre-existing `groups.color` M1 seam). Reversible.
> Adversarial review (18 agents): 4 findings fixed — HIGH membership-boundary bypass on delete-with-reassign,
> MEDIUM priority cap, MEDIUM AA palette (4 light hexes), MEDIUM setRole audit gap. All 4 have regression tests.
> Self-verified green in Docker `hearth-dev`: **Pest 518 passed / 1 skipped (1930 assertions)**; Pint PASS
> (361 files); Larastan level-5 clean; composer/npm audit clean; CSS 8.54 KB gz; assets rebuilt.
> Branch `claude/acp-v2-groups` pushed; PR pending. ADR-0024 (NevoBB name) already on main.
