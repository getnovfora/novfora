# PROJECT-HISTORY.md — NovFora completed milestone log

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
> [`docs/product/spike-0-memo.md`](docs/product/spike-0-memo.md); reference scaffold in `nevo-spike/` (source
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
> prompt is saved at [`docs/product/archive/spike-0-code-kickoff.md`](docs/product/archive/spike-0-code-kickoff.md).
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
> not M0 (per the plan); then M1→M5. Retire `nevo-spike/` once the real app supersedes it. M0 build kickoff:
> [`docs/product/archive/m0-code-kickoff.md`](docs/product/archive/m0-code-kickoff.md). *(These Cowork doc edits are on disk;
> commit them from the Code env — the Cowork mount is unreliable for git writes.)*

> **Update 2026-06-02 (M0 DONE — Code):** **Phase 1 M0 (skeleton & guardrails) complete** at the repo root.
> Laravel **13.13** + Livewire **4.3** + Scout merged in (preserving docs/git); baseline-safe drivers +
> `.env.example` (MySQL). **Service-tier detection (ADR-0003):** probes that never throw + `novfora:tier` CLI
> + a local-gated `Admin → System → Service Tier` Livewire panel + **5 forced-absence tests**.
> Reversible-migration guard + `novfora:backup` skeleton. **Prebuilt Vite assets committed** (no host Node).
> **CI** (Pint, Larastan, Pest, `composer audit`, asset budget) — green; full local run: **Pest 9 passed + 1
> todo**, Larastan clean, Pint 46 files. Built via a Docker **php:8.3 + mysql:8** dev env (`docker-compose.yml`,
> `docker/dev/`). Commits `4227af5`…`d686cbd` on `main`; dep licenses recorded in `DECISIONS.md`.
> **NEXT: M1 — Identity & access** (auth + 2FA + the **permission-mask engine**, ADR-0006) per
> [`phase-1-plan.md`](docs/product/phase-1-plan.md) §5. The validated editor pattern + `CanonicalRenderer`
> port in **M2**; retire `nevo-spike/` then.

> **Update 2026-06-02 (M1 DONE — Code):** **Phase 1 M1 (Identity & access) complete.** Two pillars.
> **(1) The permission-mask engine (ADR-0006 / security §1.2, implemented exactly):** three-state
> ALLOW/NO/NEVER over the global→category→forum→thread scope chain; NEVER short-circuits, user overrides
> group, groups merge most-permissive, deny-by-default; **NO = neutral/inherit** (interpretation "ii",
> reconciled with §1.1/§2.3 + phpBB's tri-state — flagged for explicit sign-off; the single flip-point
> is marked inline in `PermissionResolver::compute()`). Per-request memo + a resolved cache keyed by a
> global ACL version × the user's group-set signature (event-driven invalidation, incl. scope-topology
> changes); **correctness never depends on the cache.** Exposed via Laravel Gate (`$user->can('perm',
> $scope)`), deny-by-default. The **"why can/can't X" inspector (§1.4)** = a service + `novfora:why` CLI + an
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
> `nevo-spike/` then. **OPEN ITEM for the owner: confirm the NO = neutral ("ii") interpretation** (a
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
> stays green. **`nevo-spike/` retired.** **NEXT: M3 — Anti-spam baseline & moderation (ADR-0007).**

> **Update 2026-06-03 (M3 DONE — Code):** **Phase 1 M3 (Anti-spam baseline & moderation, ADR-0007) complete.**
> The whole subsystem is **unified with the M1 permission engine — no second permission system.**
> **(1) Trust→ACL gating:** TL gates seeded as `acl_entries` on TL0–TL4 from a config matrix
> (`config/novfora.php`) — TL0 = NEVER on links/images/mass-PM (absolute; an admin ALLOW cannot lift it,
> pinned by a test), TL1+ = ALLOW; attachments stay an admin-liftable soft seam. Enforced by **link/image
> suppression at the shared sanitize step**. Auto promotion/demotion via idempotent `novfora:trust:recompute`
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
> `novfora:install` CLI); write `.env` → DB → migrate → seed → create admin → `storage:link` → LOCK last.
> Lock = `storage/installed` file marker, written last; installer 403s once present; no re-trigger vector.
> **(2) Backups + restore:** `novfora:backup` (cron + `--keep`), `novfora:restore` (manifest+SHA-256), Admin →
> Backups panel. **(3) Health:** `GET /health` (DB, cache, cron freshness, tier, install state). **(4) One
> cron line (ADR-0011).** **(5) Demo seed + getting-started.** **(6) `.env.example` finalized.** **(7) Perf
> budgets in CI.** **(8) Dusk executed: 2 passed.** All six Phase 1 exit criteria met. **Pest 272 passed /
> 879 assertions.** Verified in Docker `php:8.3` + `mysql:8`.

> **Update 2026-06-03 (PHASE 1.5 — Validation & Hardening — Code):** adversarial security review + real-host
> readiness pass. **Fixed (each with regression test):** (H-1) attachment IDOR; (H-2) TL0 link/image
> suppression covers signatures; (H-3) pure-PHP MySQL dump+restore fallback; (M-1) narrow `User`
> mass-assignment; (M-2) installer pre-checks marker writability; (M-3) baseline `SecurityHeaders` middleware
> (CSP + nosniff + frame-ancestors); (M-4) `.env` written 0600; (L-1) `APP_DEBUG` forced off pre-install;
> (L-2) HTTPS-only cookie on https install. **Part 2:** `novfora:doctor` preflight,
> `PublicStorageLinker` copy-based fallback, `docs/REAL-HOST-VALIDATION.md`. **Pest 289 passed / 940 assertions.**

> **Update 2026-06-03 (PHASE 1.5 — Security Fix Pass — Code):** owner chose to fix ALL ten flagged items
> (F-A..F-M3 + tenant_id). **Fixed:** F-A setup token (install-token.txt); F-B rate-limit + mandatory
> honeypot + single-use Q&A nonce; F-C StopForumSpam fail-safe + `novfora:antispam:warm`; F-D trust promotion
> needs topics-read signal; F-E trust change re-renders posts; F-F actor-vs-target rank check; F-G explicit
> `$fillable` on six ACL models; F-H `tenant_id` removed from User mass-assignment; F-I auth-event audit
> logging; F-M3 strict nonce CSP behind `NOVFORA_CSP_STRICT` toggle. **Pest 310 passed / 1012 assertions.**

> **Update 2026-06-05 (REAL-HOST RH-6 — installer wizard front-end FIXED — Code):** root cause: Livewire
> auto-starts from `DOMContentLoaded` listener with no `readyState` fallback; shared-host JS optimizer
> deferred the script past the event → `start()` never ran → directives unbound. Fix: standalone install
> layout declares Livewire runtime explicitly + a boot guard. Coverage: `InstallerWizardTest` drives the full
> wizard in real Chrome; `InstallerLayoutTest` in-process guard. **Pest 314 passed / 1026 assertions; Dusk 3.**
> `nevo-release.zip` sha256 `b385a4bca…`.

> **Update 2026-06-06 (REAL-HOST RH-7 — install-enforce middleware ate Livewire's hashed endpoint — Code):**
> root cause: `RedirectIfNotInstalled` allow-listed `'livewire/*'` but Livewire 4 serves updates under
> `livewire-<hash>/...`; every `wire:click` POST was 302'd to `/install`. Fix: allowlist matches
> `'livewire-*/*'` + the live path from `app('livewire')->getUpdateUri()`. New enforcement-ON feature test.
> **Pest 319 passed / 1047 assertions.** `nevo-release.zip` sha256 `ebff3944…`.

> **Update 2026-06-06 (REAL-HOST RH-8 + RH-9 — post-install fixes — Code):** live install completed
> end-to-end. RH-8: root route served Laravel welcome page; fixed to 301-redirect `/` → `/forums`; deleted
> `welcome.blade.php`. RH-9: `serializable_classes => false` (anti-object-injection, kept) + Eloquent
> Collection cached = `__PHP_Incomplete_Class` on cache hit; fixed to cache primitive array tree + rehydrate
> read-only `ForumNode` value objects after boundary. `ForumIndexCacheTest` verified to fail pre-fix.
> **Pest 331 passed / 1108 assertions.** `nevo-release.zip` sha256 `f48862b0…`.

> **Update 2026-06-06 (HYGIENE — RH-5 assets + CI freshness guard + Dusk enforce-ON split — Code):**
> RH-5: stale committed `app.css` drifted from source (P1.5/RH-8 template change never rebuilt); rebuilt,
> added `assets-fresh` CI step (`npm run build` → `git diff --exit-code`). Vendor+compiled-views required
> for deterministic build. Dusk enforce-ON harness split: PASS 1 (installer, enforcement-ON, fresh DB),
> PASS 2 (app/editor, enforcement-off). **Pest 333 passed / 1128 assertions.**

> **Update 2026-06-06 (DEFAULT THEME / UI POLISH — Code):** NovFora now looks like the product.
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
> `NOVFORA_AUTO_UPGRADE=true` default; false = Admin → System → Upgrade SFC. Adversarial review (34 agents):
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
> desktop/mobile). Release `nevo-release.zip` sha256 `5c4472a9…`.

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
> `novfora.deliverability.enabled` (default false).

> **Update 2026-06-08 (ACP v2 — member-group manager + staff/group name colours — Code):**
> PART 1: member-group manager (`Admin → Members → Groups`). `GroupManager` service (system-protection,
> delete-with-reassign, membership boundary, permissions via `RoleExpander`). `⚡groups` SFC (mirrors
> `⚡structure`). Priority cap 79 (below Moderators). Every change audited. PART 2: staff/group name colours.
> AA-safe `GroupColor` palette → `--group-*` tokens (light + both dark). `User::displayGroup()/nameColor()`
> (highest-priority coloured group wins). `<x-ui.user-name>` component at 11 name sites; `.groups`
> eager-loaded. Schema: `groups.description` new only (reused pre-existing `groups.color` M1 seam). Reversible.
> Adversarial review (18 agents): 4 findings fixed — HIGH membership-boundary bypass on delete-with-reassign,
> MEDIUM priority cap, MEDIUM AA palette (4 light hexes), MEDIUM setRole audit gap. All 4 have regression tests.
> Self-verified green in Docker `nevo-dev`: **Pest 518 passed / 1 skipped (1930 assertions)**; Pint PASS
> (361 files); Larastan level-5 clean; composer/npm audit clean; CSS 8.54 KB gz; assets rebuilt.
> Branch `claude/acp-v2-groups` pushed; PR pending. ADR-0024 (NovFora name) already on main.

---

## Phase 2 (Community) milestone detail — relocated from PROJECT-STATE.md (2026-06-12)

> Full build detail (gates, test counts, adversarial-review findings, scope fences) for the Phase-2 milestones
> that landed 2026-06-09 → 06-12, moved here to keep `PROJECT-STATE.md` lean. All on `main`. (ACP v2 detail is in
> the 2026-06-08 update above.)

### P2-M1 — Engagement & content depth — MERGED on `main` (2026-06-11)
Pint PASS (418 files) · Larastan L5 clean · **Pest 711 passed / 1 skipped (2324 assertions)** ·
`composer audit` + `npm audit` clean · CSS 9.08 KB gz (budget 50) · assets-fresh (no drift) · query budgets
hold (thread ≤30 with reactions **and** a poll; index/board ≤15/≤25). Each slice security/integrity-reviewed
(reactions, polls, **oEmbed** via dedicated adversarial-review workflows). 7 PR slices, stack order:
1. `claude/p2-m1-reactions` — single-choice typed reactions; `post_reaction_counts` (authoritative recount);
   RH-9 version-keyed page cache; `react.create` (member, ungated, rate-limited); `Reacted` event seam.
2. `claude/p2-m1-polls` — polls/options/votes; **locked-poll-row vote integrity** (amendment #5); `poll.create`
   soft-TL-gated, `poll.vote` ungated; `⚡poll` + create-topic block; RH-9 result cache.
3. `claude/p2-m1-prefixes` — ACP CRUD (mirror `⚡groups`); `prefix.manage` (admin); AA-token badges; board filter.
4. `claude/p2-m1-tags` — tags + polymorphic taggables; `tag.create` **hard NEVER at TL0** (durable namespace),
   `tag.apply` ungated; usage_count authoritative; tag listing + chips.
5. `claude/p2-m1-drafts` — `post_drafts` own-only; debounced `$wire.saveDraft` (Spike #3), closure-local editor
   (Spike #1); DB-backed restore-on-mount.
6. `claude/p2-m1-edit-history` — format-aware diff (amendment #3; NOT body_text) + dependency-free LCS;
   `post.history.view` (author+staff); `⚡post-history` modal.
7. `claude/p2-m1-oembed` (⚙) — `SsrfGuard` (DNS-resolve + block private/6to4/NAT64/mapped, redirect-revalidate,
   IP-pin, caps, fail-closed) + dedicated sandboxed-iframe `EmbedPolicy` (allowlist) / link-card facade,
   injected post-sanitization; `oembed_cache`; CSP frame-src. The integration tip carries `.env.example`,
   the PROJECT-STATE update, and a one-line fix to slice 6's modal (FQ `Carbon` — caught by the integrated suite).
**DECISIONS.md** records the diff source/extraction, the oEmbed allowlist+sandbox policy, and the
NEVER/trust-gate reasoning per new key (budget held → no ceiling-change ADR needed).
**Post-build adversarial DoD audit (15 agents · 7 dimensions · per-gap verify):** 5/7 dimensions PASS with zero
confirmed gaps (permission wiring, RH-9 cache discipline, content-pipeline/SFC, docs/commits, ACP-render/Dusk).
Five LOW/MEDIUM **test/doc-coverage** gaps — all on already-correct code — were closed on the oembed tip: the
SSRF empty-DNS + missing/CRLF-`Location` guards and `EmbedPolicy` src/allow/sandbox escaping now carry permanent
tests (`locationIsUnsafe` extracted so the response-splitting branch is directly asserted); the prefix/tag/
`tags.show` board budgets (≤25/≤25/≤45) are recorded in `system-architecture.md §7`. No behavioural defects,
security holes, or permission/cache/commit issues found.
**Carried forward to M2 (NOT in this packet):** the §6 account-deletion/privacy-cascade ADR (reactions/poll-
votes/tags hard-delete with their owner; the cascade is owner-confirmable before PMs land) and the Dusk
browser-journey screenshots for react/poll/prefix/tag/draft (wired into the dusk harness; run in CI).

### P2-M2 Half-A — Deliverability light-up & rich notifications — MERGED on `main` (2026-06-11)
A LIGHT-UP + WIRE-IN of the dormant Spike-P2 pipeline (no rebuild), per
[`p2-m2a-deliverability-code-kickoff.md`](docs/product/archive/p2-m2a-deliverability-code-kickoff.md). Six items, small
DCO commits:
1. **Activate** — `.env.example` `NOVFORA_DELIVERABILITY=true`/`NOVFORA_DIGEST=true`; the SPF/DKIM/DMARC +
   on-domain-`From` operator checklist surfaced on the ACP Email page (memo §5).
2. **`Notifier`→`DigestQueue` wiring (⚙)** — the mail channel routes by digest cadence: immediate = unchanged
   live path; daily/weekly = staged into the cron digest; `off` = no notification mail. Idempotency stays on
   the committed UNIQUE row (no lock). In-app channel unaffected; its id seeds the digest dedupe.
3. **One shared `SuppressionGate` (⚙)** — `Notifier::suppressed()` delegates to it (single send-time gate).
4. **Event vocab + reaction end-to-end** — `reaction`/`pm.received`/`follow` across `EVENTS`, mail/in-app/digest
   renderers and the prefs UI. Only `reaction` has a live emitter: a QUEUED, **auto-discovered**
   `SendReactionNotification` (P2-M1 `Reacted` → notify the post author); kept off the hot react action (≤15
   budget held). `pm.received` (M2 Half-B) / `follow` (M3) get emitters there — no fake emitters.
5. **`⚡notification-preferences` SFC** — per-event×channel toggles + an off/immediate/daily/weekly cadence
   picker over `DigestPreference`; own-prefs-only.
6. **Memo follow-ups (⚙)** — unsubscribe **GET-confirm / POST-apply** split; **SES + Mailgun** webhook parsers
   (total + conservative; SNS-unwrap); **non-VERP manual-review queue** (`bounce_reviews`, reversible; populated
   only when VERP is off so a forged bounce can't flood it; ACP card to suppress-by-hand / dismiss).
**Gates:** the deliverability suite stays green and EXTENDS to the wiring (Deliverability 73, Notifications 23,
Reactions all green); Pint PASS (whole repo) · Larastan **L5 clean (0 errors)** · assets rebuilt fresh (CSS
**9.09 KB gz**, budget 50) · no new dependencies · reversible migration only. Full local suite **738/751 passed,
1 skipped**; the 12 non-passing are PRE-EXISTING sandbox **filesystem-permission** failures
(`storage/framework/testing/disks` is root-owned → `Storage::fake()` mkdir errors in the attachment/avatar tests;
`InstallerTest`/`HostDoctorTest` writable-path probes) — **zero** in notification/deliverability/reaction code;
green on CI's clean filesystem (the authoritative full gate, per the spike caveat). **DECISIONS.md** records the
`off`-cadence semantics, the auto-discovered+queued reaction listener, the SES/Mailgun shapes, and the non-VERP
review-queue forgery-flood guard.

### P2-M2 Half-B — Multi-participant PMs — MERGED (PR #17, commit `535a924`)
Built per [`docs/product/archive/p2-m2b-pms-code-kickoff.md`](docs/product/archive/p2-m2b-pms-code-kickoff.md), 10 small DCO
commits: schema → **TL0 mass-PM NEVER pin** + PmRateLimiter + ConversationPolicy → send spine (pm.send re-check /
rate / cap / **ignore** / single ContentRenderer path / report-on-PM) → live **`pm.received`** emitter →
**ADR-0025 deletion cascade** → inbox/conversation/composer UI + nav unread badge → DECISIONS → adversarial-review
hardening → Dusk journey + harness fix. **Gates:** Pint · Larastan **L5** · **full suite 794 passed / 1
skipped (2542 assertions)** · query budgets inbox ≤15 / conversation ≤30 · PM Dusk journey green +
light·dark × mobile·desktop screenshots (`tests/Browser/screenshots/p2m2b-*.png`). **Adversarial review**
(7 finder dims × 2 verifiers, Opus): 3 confirmed defects FIXED — **HIGH** ignore-at-delivery (the
notification fan-out now drops ignorers, so ignoring after joining / being force-added stops the sender),
**MEDIUM** invite recipient-cap TOCTOU (lock + re-count inside the txn), **LOW** unread same-second miss
(ms-precision watermark); 2 LOWs accepted + documented (ignore-graph inference; 403-vs-404 = app-wide norm).
**DECISIONS.md** records the design (anonymisable-author vs cascade-FK split; `string`-not-`ENUM`;
participant-only Policy; deferred full multi-table AccountDeletionService).
- **Scope fence — NOT in this milestone:** the FOLLOW half of `user_relationships` (table built; wired in
  M3), reputation/points/badges/staff notes (Should-tier — HELD), the full multi-table account-deletion
  service + confirmation UI, a PM moderation queue (M4). No second permission or render path.
- **Known (pre-existing, not from M2B):** the react/poll/prefix/tag/draft + installer-wizard Dusk journeys
  are flaky (timeouts) in the LOCAL docker dusk env; the PM journey + editor/theme/admin journeys pass.
  Validate the flaky ones in clean CI.

### P2 account deletion (ADR-0025) — MERGED (2026-06-12, commit `b006163`)
Built per [`docs/product/archive/p2-account-deletion-code-kickoff.md`](docs/product/archive/p2-account-deletion-code-kickoff.md).
The M1-deferred forced-cascade integration tests, now that PMs have landed. Closes ADR-0025 end-to-end:
- **`App\Account\AccountDeletionService`** — ONE audited cascade in a single `DB::transaction` for both paths:
  capture reacted-post/voted-option ids → pseudonymise authored content (`withTrashed`, attribution → NULL,
  bodies kept) → hard-delete participation + **authoritative recount** (`post_reaction_counts` /
  `poll_options.vote_count` via new `ReactionService::recomputeForPosts` / `PollService::recomputeForOptions`
  batch seams) → purge PII (notifications/sessions/registration_checks/acl+role holders) → **delegate the PM
  slice to `PmAccountCascade`** (not re-implemented) → delete the users row LAST → audit. `summary()` powers
  both confirm screens; `canForceDelete()` is the single admin gate (bans.manage + rank + no-equal/higher-admin
  + no-self); `isSoleAdmin()` blocks deleting the last admin on both paths.
- **Voluntary UI** — a new **Account** settings tab → `⚡delete-account` SFC (own-only; password re-auth +
  explicit confirm; deletes, flushes the session — NOT `Auth::logout()`, which would re-INSERT the just-deleted
  user — and redirects home). **Admin-forced UI** — `BanController::confirmDelete`/`forceDelete`
  (`GET /users/{user}/delete` + `DELETE /users/{user}`, gated) with the same summary + an explicit confirm,
  surfaced as a **Staff tools** trigger on the profile (visible only when `canForceDelete`).
- **`[Deleted]` render** — null author → `[Deleted]` name (`:fallback`) + a neutral guest avatar (opt-in
  `:guest` silhouette, generic null default unchanged) at post + PM author sites; `/users/{id}` 404s.
- **Gates:** Pint · Larastan **L5 clean** · composer audit clean · assets-fresh (no new utility classes) ·
  **+21 dedicated tests** (10 service/cascade incl. single-transaction rollback + recount correctness; 9
  confirm-flow/route/guard incl. wrong-password & sole-admin; 2 Dusk) + queued-job-no-op + profile-404 +
  `[Deleted]`-render. Dusk voluntary journey + `p2-acct-delete-*` light·dark × mobile·desktop screenshots.
  **DECISIONS.md** records the FLAGged calls (audit actor → NULL; email_suppressions deleted) + the
  withTrashed / logout-re-insert / sole-admin / forced-gate reasoning.
- **Scope fence — NOT here:** a full ACP member list/detail page (only the minimal forced-delete trigger),
  GDPR data-export, any soft-delete/grace-period/undo (this is hard, immediate, confirmed deletion).

### P2-M3 — Activity feed & community-feel pack (Core) — MERGED (2026-06-12, commit `ae9bba3`)
Built per [`docs/product/archive/p2-m3-activity-code-kickoff.md`](docs/product/archive/p2-m3-activity-code-kickoff.md):
- **`VisibleForumIds`** (⚙) — query-level `forum.view` filter; `null` = sees-all sentinel, `[]` = sees-none.
  **`ActivityFeed`** (⚙) — version-keyed global primitive-row cache (`ActivityVersion`, mirrors `AclVersion`),
  per-viewer filter + batch rehydrate AFTER the boundary; `[Deleted]`-actor + removed-subject tombstones.
- **Verb logging** — auto-discovered listeners on `TopicCreated`/`PostCreated` (post-commit, **approved-only**)
  and `Reacted`; **PMs log nothing**. **ADR-0025 addendum** — one line pseudonymises `activities.actor_id` in
  the cascade (same txn, before the users row drops).
- **Community pack** — `ThrottledLastActive` (≤1 raw write/user/5min) + `User::isOnline()` (15-min) +
  `x-ui.online-badge`; **throttled** `topics.view_count` (Cache::add, 1/viewer/topic/hr, replaces the
  unconditional increment); forum `topic_count`/`post_count` already maintained + displayed (added tests).
- **Gates:** Pint · Larastan **L5 clean** · composer audit clean · assets-fresh (no new utility classes) ·
  forum-index query budget **15 → 20** (amendment #6, documented) · full suite **831 passed / 1 skipped** ·
  **+20 tests** (18 feature: resolver/logging/feed cache-HIT/community/addendum + 2 Dusk `p2m3-feed-*`
  screenshots). **DECISIONS.md** records the cache-key design, the `null` sentinel, the
  `scope_forum_id` nullOnDelete edge-case, the cache-window limitation, and the approved-only/PM-exclusion gate.
- **Scope fence / HELD:** follow-half of `user_relationships`, reputation/points, badges, staff notes, a 2nd
  theme; `VisibleForumIds` is the M4 search-facet seam but is NOT wired to search here.

### P2-M4 — Moderation depth, search facets & preferences (Core) — MERGED (2026-06-12, PR #19, commit `c56126e`)
Built per [`docs/product/archive/p2-m4-moderation-code-kickoff.md`](docs/product/archive/p2-m4-moderation-code-kickoff.md):
- **Merge / split (⚙)** — `MergeTopicsService` / `SplitTopicService` move posts with a single raw `UPDATE`
  (bypassing `Post::syncAggregates`; merge offsets positions to APPEND after the target's OP), then re-derive
  topic + forum counters **authoritatively** (`TopicCounters`, COUNT/MAX not ±delta, overwriting observer
  deltas) in ONE transaction (rollback-proven). Merged source soft-deletes to a `moved_to_topic_id` **301
  redirect shell** over a `withTrashed` `topics.show` binding — resolved transitively to the chain terminus
  and 404-gated on target `forum.view`. OP can't be split away; per-post rank gate refuses the whole split.
- **Cross-page bulk select (◐)** — `BulkModerationService` (delete posts; lock/unlock/move/delete topics) with
  the **rank guard**: every item gated by `canActOn` + the forum permission, ineligible items **silently
  skipped**, applied+skipped sets audited; the SERVICE is the trust boundary (client ids never trusted). UI:
  an Alpine `bulkSelect` store (survives `wire:navigate`) + per-row checkboxes + a `⚡bulk-actions` floating bar.
- **Search facets (◐)** — `SearchService::search(SearchQuery)` adds author/forum/date/tag/type as a direct
  Eloquent query joined to the topic; **every path threads `VisibleForumIds`** (reused, not rebuilt) so a
  restricted viewer can't reach an invisible forum via any facet. Baseline = DB driver (tested + forced-absence);
  `meiliFilter()` translates to Meili native filters (unit-tested, unwired). `toSearchableArray` adds facet
  fields only on `['meilisearch','typesense']`. Bookmarkable GET facet form.
- **Consolidated preferences (◻)** — `posts_per_page` + `thread_sort` (two nullable `users` columns; null →
  site default 15/oldest) written by the own-account `⚡user-preferences` SFC (validated, out of `#[Fillable]`),
  honoured in `TopicController`. A new **Preferences** settings tab.
- **Gates:** Pint · Larastan **L5 clean** · composer audit clean · no dependency drift · assets-fresh (no new
  utility classes) · budgets search **≤25** / moderator-thread **≤35** · full suite **868 passed / 1 skipped
  (2821 assertions)** · **+37 feature tests** + a Dusk moderation journey (`p2m4-*` screenshots). **Post-build
  adversarial review (26 agents, 6 dimensions): 9 confirmed (1 MEDIUM bulk-move destination gate + 8 LOW) all
  fixed/accepted, 11 refuted** — see DECISIONS.md P2-M4.
- **Scope fence / HELD:** staff notes (`staff_notes`/`StaffNote`), a full ACP member-management page, GDPR
  data-export, bulk hide/unhide (no post-level hide status — recorded). `VisibleForumIds` used, not extended.

### P2-M5 — Beta polish, full regression & the social pack (Core) → 🚩 Public Beta (2026-06-12, branch `claude/p2-m5-beta-social`)
Built per [`docs/product/archive/p2-m5-beta-social-code-kickoff.md`](docs/product/archive/p2-m5-beta-social-code-kickoff.md)
(ADR-0028 pulled follow + reputation/points + badges from HELD into M5 Core):
- **Follow (◐)** — the follow half of `user_relationships` wired: idempotent `FollowService` (DB-UNIQUE
  `insertOrIgnore`; `Followed` fires only on a real insert; **self-follow = hard service refuse no ACL lifts**);
  `follow.create` TL0-**soft**-gated via the poll.create pattern (withheld from the member preset, `$trusted`
  from TL1, staff exempt, admin-liftable) + per-TL `FollowRateLimiter`; `follow.delete` ungated (a demoted user
  can always unfollow). The REAL `follow` notification emitter lands on the M2-A vocab (queued, ignore-graph
  honoured at delivery, **one unread notification per follower** — cycling can't flood). Following feed =
  `ActivityFeed::forFollowing`, **still threaded through `VisibleForumIds`**, RH-9 window keyed on a hash of
  the sorted followed-id set + activity version (self-invalidating); empty follow set → global feed + hint.
  Profile follow button + follower/following counts.
- **Reputation (⚙)** — the `reputation_events` ledger (UNIQUE(source) idempotency; signed points;
  `users.reputation_points` flipped signed in a reversible migration) + `ReputationService` (insertOrIgnore
  award with atomic increment; stored-points revoke gated on the actual delete; `syncSourceAward` for the
  single-choice type-change path; authoritative `recomputeFor` with drift-only writes). **Amendment #4 lights
  up:** queued `Reacted`/`ReactionRemoved` listeners award/revoke the config score weights — react action
  pinned **≤15** steady-state; self-reaction awards nothing; optional creation awards ship OFF (env, default 0).
  **The extended ADR-0025 cascade:** affected third-party authors captured with a locking current read AFTER
  the reaction delete, sourced + own ledger rows pruned, authors recomputed authoritatively in the same
  transaction — headline test proves each author drops by exactly the revoked weight, zero orphans.
- **Badges (⚙/◻)** — `badges` + `user_badges` (UNIQUE(user,badge)); a **closed-set** criteria engine
  (join | post_count | reputation — matched, never evaluated; APPROVED posts only); awards idempotent +
  **permanent**; queued triggers (Registered, PostCreated + TopicCreated, the new `ReputationAwarded` signal);
  ACP `⚡badges` manager (mirrors `⚡prefixes`; `badge.manage`, walk-test auto-covered); profile chips
  (palette-validated tokens); the badges **migration seeds the starter set into an empty catalog** so an
  UPGRADED board matches a fresh install (RH-10 rehearsal finding); starter set: Welcome / First Post /
  Conversationalist / Well-Regarded.
- **Crons** — `nevo:reputation:recompute` (hourly) + `nevo:badges:recompute` (daily, catalog loaded once),
  both idempotent, bounded, short-mutexed (SchedulerTest-pinned); plus a daily `novfora-cache-prune` for the
  DB cache store (version-keyed entries never self-evict). The `nevo:` names are the Phase-5 rename surface #8.
- **Beta polish** — DemoSeeder demonstrates the whole beta through the real write paths (reactions, a poll,
  PMs, follows, banked reputation, swept badges; idempotent, with a permanent regression test);
  getting-started cron block + `.env.example` refreshed; prebuilt assets rebuilt.
- **Regression (the beta gate)** — **RH-10 EXECUTED**: scratch board migrated → the 3 M5 migrations rolled
  BACK (down() reversibility proven, incl. the unsigned-restore clamp) → pre-M5 data + a queued backlog →
  `novfora:upgrade` (backup-first) → 49 migrations, data intact, backlog drained post-migration, both new
  crons green, starter badges present, negative rep round-trips. **RH-11 EXECUTED**: backup → vandalise
  (rename a user, delete a badge) → `novfora:restore` → round-trip verified (pre-restore safety snapshot
  taken). Budgets HOLD: react ≤15 (new pin) · forum index ≤20 · search ≤25 · moderator thread ≤35 ·
  **profile ≤20 (new documented ceiling)**. Truth tables extended (follow.create soft gate, follow.delete,
  badge.manage); cascade truth tables extended (follow both directions, reputation third-party recompute,
  user_badges). Dusk `SocialPackJourneyTest` (follow → following feed → react → points rise → badge on
  profile) **executed green** with 8 `p2m5-*` screenshots.
- **Gates:** Pint · Larastan **L5 clean** · composer + npm audit clean · assets-fresh (rebuilt + committed) ·
  full suite **955 passed / 1 skipped (3,116 assertions)** · **+87 feature tests** + the 5-test Dusk journey.
  **Post-build adversarial review (62 agents, 6 dimensions, verify-then-refute): 2 HIGH (orphan-ledger
  TOCTOU; moved-topic feed leak) + 4 MEDIUM + 4 LOW fixed; accepted races + 2 pre-existing fast-follows
  recorded; refuted findings discarded** — see DECISIONS.md P2-M5.
- **Scope fence / HELD (fast-follows):** staff notes · reputation leaderboard / top-members · TL
  auto-promotion by reputation · the 2nd example theme (the one Should item carried, recorded) · GDPR export ·
  ACP member management. `isSoleAdmin` TOCTOU + `ActivityVersion` lost-bump (both pre-existing) flagged.

---

## Milestones moved from PROJECT-STATE.md on 2026-06-22 (R1 doc-trim) — newest-first

## ✅ Unattended batch 2026-06-21 — demo-shakeout fixes (EXECUTED: 5 branches → 5 PRs; none merged by Code)

**Executed by an unattended Code session** from master spec `docs/product/archive/batch-2026-06-21-kickoff.md`. All 5
branches built off `main`, each gated green (`pest` ~1.9k passed / 1 Dusk-skip, `pint`, `phpstan`), one PR each —
**none merged by Code** (Tommy / Cowork reviews + merges; the apex seams got an in-session adversarial review).

| Branch | PR | Status |
|---|---|---|
| `claude/admin-perm-mgmt` (B1 — admin/perm **clone**, apex) | **#43** | **landed** — discoverability links + `GroupManager::clone()`/`RoleManager::clone()` (ADR-0090) + groups UX. Adversarial review caught + fixed **2 HIGH** clone escalations (role re-expansion resurrected card-stripped keys; admin-tier fence was blind to assigned roles). |
| `claude/post-approval-promotion` (B2 — "Dan", trust) | **#44** | **landed** — eager trust recompute + `PostCreated` on `approvePost()`; `novfora:trust:recompute --user` diagnostic; queue hold reason. **Warning-freeze flagged for owner decision** (NOT changed). |
| `claude/activity-feed-fixes` (B3 — feed visibility, apex-adjacent) | **#45** | **landed** — null-scope leak + restricted-viewer underflow + profile limit (ADR-0091). Adversarial review confirmed leak closed; fixed a slice-before-filter underflow; 2 security-safe over-hiding trade-offs documented. |
| `claude/oauth-sfs-hardening` (B4 — xhigh nits) | **#46** | **landed** — SFS live-API toggle now authoritative via `ExternalSignalPolicy::apiEnabled()` (fail-safe preserved). Items 1 (redirect throttle) & 3 (Discord provider) were already present; pinned with guard tests. |
| `claude/release-tooling` (B5 — release/CI) | **this PR** | **landed** — `verify-release.sh` PASS→`rc=0` re-verified end-to-end; exec bits `100755`; `.gitignore /*.zip`; public/build drift fixed (the existing CI `assets` job IS the RH-5 drift guard). |

**Note:** OAuth/social login **and** StopForumSpam screening were already fully implemented on `main` — B4 only hardened edges. The 5 PRs are independent; merge in any order.

**Merged + deployed (2026-06-22).** All 5 merged to `main` by Cowork (HEAD `936e500`; the only conflict was `DECISIONS.md` — resolved keeping ADR-0090/0091/0092). Merged main re-gated green together (`pest` **1959 / 1 skip**, `pint`, `phpstan`, release build + `verify-release rc=0`). Deployed to **demo.novfora.com** — code-only (no migrations in this batch), `schema.pending:false`, queue draining. Verified live: add-admin links present; **"Dan" diagnosed as a `status=pending` false-flag** (activated manually — not a code bug; the systemic exit-ramp fix is spec'd at `docs/product/pending-member-review-kickoff.md` for the next cycle).

**Follow-ups (deferred):**
- **Group clone button not rendering on the live demo** despite PR #43 merged + `GroupCloneTest` green — the code is correct on `main` (`⚡groups.blade.php` renders Clone for `type='custom'` only; `GroupManager::clone()` per ADR-0090). Suspect a stale compiled-Blade / opcache on the Hostinger demo, or the checked group not being `type='custom'`. Next demo cycle: confirm the new `⚡groups.blade.php` actually deployed, `php artisan view:clear` + opcache reset, and verify the group's `type` column = `custom`.
- **`novfora:trust:recompute --user` prints the generic summary, not the per-user reason** the spec intended (engine behaviour is correct; the diagnostic print is just terser). Small polish.

---

## 🎉 ACP v3 · v3-g — staff flair + "The Team" roster on `claude/acp-v3-g` — 2026-06-21 (off `main` · **completes the ACP v3 program**)

**Unattended, owner-authorized session.** Built the FINAL ACP v3 slice **v3-g** (staff flair + roster, ADR-0088) — the
**display capstone**: surface who's staff at a glance + a curated public "The Team" page. Deliberately the only
**DISPLAY-ONLY** slice — **no `acl_entries` touch, no resolver, no `AclVersion` bump, no apex seam** (Sonnet-forward).
Off `main`. Conventional DCO-signed `Tommy Huynh` commits, one per step, each gated green.

**What shipped.** (1) `User::staffRole(): ?string` — a canonical role KEY (`co_owner` / `administrator` / `moderator` /
`forum_moderator` / null), memoized; reuses `isAdmin()`/`isStaff()` + `AdminCoOwnerService::isCoOwner` (the only extra DB
touch, admins-only) + `moderator_assignments`; companions `staffTitle()` / `showsStaffIcon()` read the loaded groups. (2)
`<x-ui.staff-flair :user>` (badge + optional icon, gated by `members.staff_flair_show_badge`) slotted into the post
author block (replacing the old inline role ternary), profile hero, and members-directory card (online list deferred —
spec-optional). (3) One additive/reversible migration: 3 display-only `groups` columns (`show_on_staff_page` seeded true
on admins+moderators, `show_staff_icon`, `staff_title`) + 2 `members.*` settings. (4) `/staff` → `members.staff` →
`⚡community.staff-roster` (gated 404 when off; active members of flagged groups ∪ per-user forum-mods, bucketed by role
Co-owners→Administrators→Moderators→Forum moderators; no non-flagged leak). (5) `⚡admin.settings.staff-flair` ACP
toggles + Members nav sub-page + `admin.nav.staff_flair`.

**The one perf seam (not apex).** The `forum_moderator` check would N+1 the topic hot path, so `User::moderatorAssignments()`
is eager-loadable and `TopicController` (+ the members-directory SFC) eager-loads it — ONE board-wide IN query; the
`HotPathQuery` topic ceiling moved `<41`→`<42` for that single constant (gate verified, 16 distinct authors).

**Gates.** `pest` full suite **1864 pass / 1 skip** (StaffFlairTest + StaffRosterTest = 19 cases) · `pint` · `phpstan`
L max (app/) · `migrate` apply+rollback+re-apply. **ACP v3 program (ADR-0080) is COMPLETE** — v3-0, v3-h, v3-c, v3-e,
v3-d, v3-b, v3-a, v3-f, v3-g all shipped. No remaining v3 slices.

---

## ⏳ ACP v3 · v3-f — temporary-access delegation on `claude/acp-v3-f` — 2026-06-21 (off `main`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-f** (temporary-access delegation, ADR-0087) — a
co-owner hands an individual ONE capability for a bounded window (≤ 30 days), riding the v3-0 `expires_at` seam:
**no new eval path (G1), no resolver change, no new cron, no `acl_entries` schema change.** Off `main`, independent of
the unmerged v3-c/d/e stack. Conventional DCO-signed `Tommy Huynh` commits, one per step, each gated green; the apex
ceiling/no-clobber mapping had a 5-reason adversarial verify pass (caught the no-clobber + the real cascade trigger).

**What shipped.** (1) An additive/reversible `delegations` provenance table (G10) + `App\Models\Delegation` (a `live()`
scope). (2) `App\Admin\DelegationService` (apex) — `grant()` fences {co-owner only · non-delegable admin/security keys ·
**ceiling reused** via `assertWithinCeiling` at the target scope · 30-day clamp · **no-clobber**}, projecting ONE
time-boxed user-holder ALLOW row; `revoke()` (key-scoped delete + `AclVersion::bump`, G9); `cascadeForActor()` (the
current-mask re-check). (3) The **Active delegations** Security SFC (`⚡active-delegations`, the 2FA co-owner gate in
mount + every action) + `@extends` wrapper + route `security.delegations` + nav `[active_delegations, …, clock]` +
`admin.security.delegations.*` lang. (4) Cascade hooks (one line, post-commit) in `GroupManager::removeMember` (the real
delegable-mask reduction) + `AdminCoOwnerService::revoke` + `AdminBundleService::revoke` (spec-named, defensive).

**Apex correctness.** The recipient never exceeds the delegator's mask: the grant-time ceiling reuses the engine fence;
the **current-mask cascade** revokes a delegation once the delegator loses the key (a co-owner's delegable keys flow
from the `admins` group → `GroupManager::removeMember` is the path that matters). **No-clobber:** `acl_entries` has no
unique index and a forum-mod can hold a permanent row at the same cell, so grant refuses a live/NEVER cell and revoke
deletes only the `whereNotNull(expires_at)` row — never a permanent grant. Auto-expiry is the v3-0 seam (a test pins the
`can()` flip with **no prune run**, then the prune sweeps the dead row). **Documented bounded gap:** a co-owner's group
later losing a key via `GroupPermissionEditor` is not cascaded (a member-set fan-out), capped by the ≤ 30-day expiry.

**Gates.** `pest` (DelegationTest: 16 cases / 55 assertions; full Admin+Permissions regression 331 green) · `pint` ·
`phpstan` L max (app/) · `migrate` apply+rollback+re-apply · asset budget (no drift). **Next: v3-g** per ADR-0080.

---

## 🛡️ ACP v3 · v3-a — co-owners + Admin Manager + per-section bundles on `claude/acp-v3-a` — 2026-06-20 (off `main`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-a** (co-owners + Admin Manager + the per-section
`admin.<section>.access` gating, ADR-0086) — the top admin tier, additive over the existing engine (G1, no new eval
path; G3 reversible). **Base note:** off `main`, independent of the unmerged v3-c/d/e stack (reuses the shared engine +
the v3-d role model). Conventional DCO-signed `Tommy Huynh` commits, one per step, each gated green; the two apex
services each had an adversarial verify-then-refute review before commit.

**What shipped.** (1) Ten `admin.<section>.access` catalog keys (Administration cluster); the `administrator` preset
gains the nine non-security ones additively (`PermissionSync` reaches existing installs). (2) An additive/reversible
`is_co_owner` `group_user` pivot col + the installer crowning the first admin (flag + a per-user `admin.security.access`
grant). (3) `AdminCoOwnerService` — grant/revoke with the **last-owner guard** (`assertNotSoleCoOwnerLocked`, a
`lockForUpdate` re-read mirroring `AccountDeletionService::assertNotSoleAdminLocked`). (4) `AdminBundleService` +
`AdminBundleSeeder` (6 `is_preset` bundles) — a **restricted admin** is NOT in `admins`; they hold `admin.access` + a
bundle's section keys as PER-USER grants (disjoint rows; G10). (5) Two Security SFCs (Co-owners + Admin Manager),
routes, wrapper views (`@extends` envelope), nav. (6) Per-section rail + landing gating
(`AdminNavigation::canAccessSection`, `SectionController`, the Analytics SFC).

**Apex correctness.** The last-owner guard is enforced SYSTEM-WIDE — the apex review found two OTHER doors that could
strand the owner tier (`AccountDeletionService` deleting the sole co-owner; `GroupManager::removeMember` detaching them)
and BOTH now run the same locked guard. The G10 escalation fence holds (`isAdmin()` is group-based → a restricted admin
can't mint admin-tier keys; `EnsureSystemPanelAccess` still admits them key-based). Security-by-default: 2FA is now
required for any panel-reacher (`isStaff()` OR `canDo('admin.access')`), not just staff groups — **flagged** in the ADR.

**Apex reviews.** Two adversarial verify-then-refute passes: co-owner service (21 candidates → 2 HIGH cross-path strand
bugs fixed + pinned), bundle service (16 candidates → the destructive-path actor backstop fixed + pinned). All other
candidates refuted.

**Gate.** `pest` **1814 pass / 1 skip / 1 pre-existing fail** (+~40 new tests; the 1 fail is the pre-existing
`HotPathQueryTest` topic budget 42 vs 41 that polish-R2 / PR #35 fixes — v3-a touches no topic-render path) · `pint` ·
`phpstan` 0 · `migrate` apply+rollback+re-apply. Bundles are seed-only (mirrors v3-b); the Admin Manager degrades via
per-key toggles on an upgrade where a preset is absent.

**Next: v3-f** (temporary-access delegation — the `expires_at` TTL), then v3-g per ADR-0080.

---

## 🛡️ ACP v3 · v3-b — per-forum moderators on `claude/acp-v3-b-moderators` — 2026-06-19 (off `main`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-b** (per-forum moderator assignment, ADR-0085) — a
CONSUMER of the v3-d role model, as a **projector slice** that adds NO new evaluation path (G1). **Base note:**
branched off `main` — v3-b only reuses the shared permission engine + the v3-d role model, so it is independent of the
unmerged v3-c/d/e stack (the owner merges all). Conventional, DCO-signed, `Tommy Huynh`-authored commits; each step
gated green.

**What shipped.** A `moderator_assignments` table (holder + `forum_id` + `role_id` XOR `bundle` slug, unique per
holder+forum; additive/reversible) + `App\Permissions\ForumModeratorProjector` (`assign()`/`revoke()`, mirrors
`ClubRoleProjector`) expanding into FORUM-scope `acl_entries` via `RoleExpander::assign`. Three seeded preset bundles
(`forum-mod-full` / `-content` / `-queue`) as `is_preset` roles, NOT group-expanded — only the projector expands them,
at forum scope. Custom path = any v3-d `is_preset=false` role. Surfaces: a per-forum **Moderators** tab
(`admin.forums.moderators`, a 3rd structure-tree button) + a global **Moderation → Moderators** pane.

**Apex fences (projector = actor-independent backstop; the SFCs self-guard admin.access + permissions.manage +
staff-2FA).** Grant-only (a mod role may carry no NEVER — the review's finding), admin-tier refusal (admin.access can
never be a mod power), the **ceiling reused at forum scope** (`RoleManager::assertWithinCeiling` is now
`?Scope`-parameterized — default global keeps the v3-d callers byte-identical), and the `ActorRank` rank guard (user
holders). Key-scoped deletes only + drop the forum-scope `RoleAssignment` on revoke/re-assign (G10 — a later role
edit's `reexpand` can't re-grant a revoked holder). `bans.manage` rides in the full bundle but is global-kind, so its
forum-scope row is inert (flagged).

**Apex review.** Adversarial verify-then-refute (security / integrity / concurrency lenses) before commit: **1
finding** — a NEVER-valued custom role would mint a forum-scope hard-deny (ceiling-exempt + `reexpand`-amplified).
Fixed with the grant-only fence + pinned by oracle case 8; no other finding survived refutation.

**Gate.** `pest` **1775/1777** (+29 new tests; the 1 fail is the pre-existing v3-e `HotPathQueryTest` query budget,
42 vs 41 — unrelated: v3-b touches no topic-render path) · `pint` · `phpstan` 0 · `migrate` apply+rollback+re-apply.
**Deferred follow-up:** the per-user "Moderation" tab on the member-edit screen (spec §4) — noted, not built.

**Next: v3-a** (admin bundles), then v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 · v3-d — custom role builder on `claude/acp-v3-d-roles` — 2026-06-18 (stacked on `claude/acp-v3-e-groups`, which stacks on `claude/acp-v3-foundations`)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-d** (custom role builder) on the existing engine,
per ADR-0080 slice order. **Base note (STACKING):** branched off `claude/acp-v3-e-groups` HEAD (NOT `main`) — v3-e
is not yet merged; the whole stack is the owner's to merge, **foundations → v3-e → v3-d**. Conventional, DCO-signed,
`Tommy Huynh`-authored commits, gated green in `forum-dev`. **NOTHING IS PUSHED** — branch is **local-only**.

**The builder (Groups → Roles, `/admin/groups/roles`, `<livewire:admin.roles>`).** CRUD `is_preset=false` roles as
a name + a **Yes / No / Never** grid over the permission catalog, grouped into clusters by the catalog `group`
field. The four seeded system presets (administrator / moderator / member / guest, `is_preset=true`) are READ-ONLY.
A built role applies as a **custom group's baseline** via `RoleManager::assignToGroup` → `RoleExpander::assignToGroup`
(expands at global scope; system groups refused). **No migration** — `is_preset` already distinguishes custom from
preset; permission keys carry dots so the grid uses `setValue(key,state)` actions, not a dotted `wire:model`.

**Apex fence — convergent re-expansion (the v3-d correctness seam).** `RoleExpander::reexpand()` previously only
UPSERTED, so a key DROPPED from a role lingered on every holder as a stale grant. It now also DELETES a
caller-supplied `droppedKeys` set at each assignment's scope (and `retract()` removes a role's whole footprint on
delete). `RoleManager::save` captures the pre-edit keys, computes `dropped = old − new`, and converges every
assigned holder in one transaction; `delete` retracts everywhere. Deletion is KEY-SCOPED (only named keys), so a
co-grant on a different key survives. Query-builder deletes skip the `AclEntry` event (G9) → the policy layer bumps
`AclVersion` once per op. **Provenance caveat (review MEDIUM, scoped):** `acl_entries` has no `role_id`, so a key
that is BOTH in a role AND set by the card editor on the same (group,scope) is one row — removing the role removes
it (last-writer-wins; a group is managed by a role baseline OR the card editor on a given key, not both — ADR-0084).

**Escalation + self-lockout fences (mirror v3-c; service backstop + SFC 403 pre-check).** Only a FULL admin
(`isAdmin()`) may put / assign / tear-down an **Administration-cluster** key (catalog `group=='Administration'`);
no ALLOW may exceed the actor's own ceiling (`canDo(key,global)`); NEVER is ceiling-exempt but still admin-fenced.
The admins group can never be stripped of its recovery keys (`admin.access` + `permissions.manage`). **The apex
review found 1 HIGH** — the self-lockout + admin-tier guard were missing on the destructive `delete`/`unassign`
paths — **fixed and pinned by tests before commit** (the dangerous precondition is UI-unreachable, but the guards
are now actor-independent backstops). It also surfaced the MEDIUM provenance note above (scoped, not a regression).

**Gate at HEAD (all green):** `php artisan test --parallel` · Pint · PHPStan L5 · migrate **apply + rollback +
re-apply** (no new migration — the existing reversible chain). Inspector-oracle tests (G4) are the correctness
proof: a DROPPED key disappears from holders AND its row is gone (+ version bumped); ADD appears; co-grant survives;
swap converges; the fences hold on every create/assign/unassign/delete path. ADR **0084** lifted into `DECISIONS.md`.

**Next: v3-a** (admin bundles) — **v3-b ✅ shipped 2026-06-19 (ADR-0085, `claude/acp-v3-b-moderators`)** — then
v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 · v3-e — group system on `claude/acp-v3-e-groups` — 2026-06-18 (stacked on `claude/acp-v3-foundations`, which carries v3-0/v3-h/v3-c)

**Unattended, owner-authorized session.** Built ACP v3 slice **v3-e** (group system) on the existing engine, per
ADR-0080 slice order. **Base note:** branched off the tip that carries the merged v3 cycle — `main` does NOT yet
carry it (v3-0/v3-h/v3-c are still on `claude/acp-v3-foundations`, pending the owner's push/PR), so the faithful
base for v3-e was that branch's HEAD, not `main`. Conventional, DCO-signed, `Tommy Huynh`-authored commits, gated
green in `forum-dev`. **NOTHING IS PUSHED** — the branch is **local-only**; the owner pushes + opens the PR.

**Membership models (`groups.membership_model`).** admin (unchanged default) / request (a moderated
`group_join_requests` approval queue, ACP Groups → Join requests) / open (a public Join button). `GroupMembershipService`
mirrors `ClubMembershipService` (request → approve/deny, open join, leave). Every self-service join passes
`GroupJoinGate` (verified + active + not-banned) so a banned/suspended/unverified/restricted account can't bypass
new-user limits to join; system + trust groups are never self-joinable.

**AND/OR auto-promotion (`GroupAutoPromoter`).** Generalises Stage-A A3's flat trust floor to an arbitrary
`{op:AND|OR, rules:[{criterion:posts|tenure_days|trust|reputation, gte:N} | nested]}` tree. **Promotion-only**,
**idempotent**, **custom groups only** (trust stays with `TrustLevelManager`). Legacy flat `{min_*}` still
evaluates (normalised as one AND node). Evaluated by the new hourly `novfora:groups:auto-promote` cron (authoritative
catch-up + the only path crossing the time-based `tenure_days` bar) + queued listeners on `PostCreated`/`TopicCreated`
(post count) and `ReputationAwarded` (rep), mirroring the badge-award wiring. Fail-closed on malformed nodes. A
single-level AND/OR builder lives in the group editor (`⚡groups`); the engine also evaluates nested trees.

**Public Groups directory + primary chooser.** `GET /groups` lists ONLY `is_public` groups (per-group flag, OFF by
default → page + nav link empty/hidden until opted in); exposes name/description/member-count only — never a roster
or a hidden group. The primary-group chooser lets a user pick their primary; an admin override sets + LOCKS it
(`group_user.is_primary_locked`); primary is cosmetic (resolution reads all memberships) so it needs no invalidation.

**Apex fence — the membership-cache seam (`MembershipCache`, G9's sibling).** A group is a permission HOLDER, so a
pivot join/leave/promote/approve/admin-assign changes effective permissions WITHOUT an `acl_entries` write — and
pivot writes fire no model events. `MembershipCache::flushFor($user)` (1) reloads the user's `groups` relation so
the next `groupSignature()` re-keys that user's cross-request verdict cache, (2) flushes the per-request
`PermissionResolver` memo, (3) flushes `VisibleForumIds`. The additive hot paths (join/approve/auto-promote) do NOT
bump `AclVersion` (the per-user signature scopes it; a global bump on every auto-promotion sweep would cold-start
every viewer's cache). The rare REDUCTION/SWAP paths (leave/remove/delete-reassign/trust-demote) pass
`bumpVersion: true` — defence-in-depth from the adversarial review: a signature is a pure function of the id-set, so
a reduction can round-trip to a previously-cached signature, and the bump dominates it (harmless on the
membership+ACL axes, but robust on orthogonal axes like a cached ban verdict). Routed `TrustLevelManager` (had only
the inline memo flush) + `GroupManager` through the same helper.

**Gate at HEAD (all green):** `php artisan test --parallel` · Pint · PHPStan L5 · migrate **apply + rollback +
re-apply** (down() drops the columns/table cleanly). Inspector-oracle tests are the correctness proof for the
seam (a raw attach WITHOUT the seam is shown stale; every real path flips the inspector verdict immediately + the
cached `can()` path). ADR **0083** lifted into `DECISIONS.md`.

**Next: v3-d** (custom role builder), then v3-b / v3-a / v3-f / v3-g per ADR-0080.

---

## 🛡️ ACP v3 — admin & permission management on `claude/acp-v3-foundations` — 2026-06-18 (off `main` carrying the PWA + i18n merge)

**Unattended, owner-authorized session.** Built the first three ACP v3 slices on the existing permission engine,
per the owner-approved program (foundations §3/§5 + kickoff refresh; **ADR-0080** parent, **0081**/**0082**
children). Conventional, DCO-signed, `Tommy Huynh`-authored commits, each gated green in `forum-dev`.
**NOTHING IS PUSHED** — the branch is **local-only**; the owner pushes + opens the PR.

**v3-0 — the engine seam (apex · ADR-0080) — `feat(perms)` commit.** Additive, reversible nullable+indexed
`acl_entries.expires_at`; a single **authoritative** resolver filter (`expires_at IS NULL OR > now`) on the
acl_entries read; the cached `can()` horizon capped to the earliest contributing TTL so the Gate path stays
authoritative even if the prune cron lags (an apex-review **MEDIUM**, fixed + pinned); the
`novfora:acl:prune-expired` cron (every 5 min, short overlap mutex, restore-skipped, one AclVersion bump on the
builder-delete). NULL rows resolve **byte-identically** — the whole pre-v3 suite is the regression guard.
Truth-table / inspector + prune + cache-boundary tests. 4-lens adversarial review before commit.

**v3-h — the Invision-style IA (UI · ADR-0081) — `feat(acp)` commit.** Icon rail of 11 sections → per-section
sidebar → per-section dashboard landing (one `SectionController` + a shared `admin.section` view) + a global ACP
search (`admin.search`: pages / settings / members). `AdminNavigation` is the single source (rail + sidebars +
active-section + search index). Old admin URLs **301** to their new section homes via bare `Route::redirect`
(excluded from the authz-walk; a dedicated 301 test); route **NAMES kept stable** so call-sites are unaffected;
the Permission Inspector moved System → **Security** and was renamed
`admin.system.permissions` → `admin.security.permissions` (5 call-sites). One `admin.*` i18n group (G8-checked).
Keyboard-navigable rail. **No new permission keys** (per-section gating arrives in v3-a; the rail is
`admin.access`-gated for now).

**v3-c — the headline card-per-group editor (apex · ADR-0082) — `feat(acp)` commit.** `GroupPermissionEditor`
writes a group's OWN entries directly (Yes = ALLOW · No = delete-the-row → inherit · Never = NEVER), **not** via
RoleExpander. One Livewire SFC (`permissions.group-editor`, `#[Locked]` scope) at all three homes: GLOBAL
(Groups → Group permissions), FORUM (Forums → forum → Permissions, linked from the structure tree), CLUB (the club
manage screen). Category bulk-apply copies a forum's overrides onto every **non-club** forum under its category in
one transaction, audited. Fences (**two HIGH** from the apex review, fixed + pinned): the manage-permissions
capability gate + a rank guard; an **admin-only fence on Administration-tier keys** (else a non-admin permission
manager could escalate); a **self-lockout guard** on the admins group's `admin.access` / `permissions.manage` at
global (service throws + SFC 403s — the interim last-owner guard until v3-a's co-owners). Inspector-oracle tests
across all three scopes + the bulk + every fence.

**Gate at HEAD (all green):** `php artisan test --parallel` → **1650 pass / 1 skip (13267 assertions)** · Pint
(840 files) · PHPStan L5 · migrate **apply + rollback + re-apply**. ADRs **0080 / 0081 / 0082** are lifted into
`DECISIONS.md`; the planning set is `docs/product/acp-v3-{foundations,kickoff-refresh,adr-0080}.md`.

**Next: v3-e** (group system — membership models + AND/OR auto-promotion), then **v3-d / v3-b / v3-a / v3-f / v3-g**
per ADR-0080. Their homes (Custom Role Builder, Group priority, Co-owners/Active Delegations under Security) slot
into the v3-h sections already in place; v3-f's TTL delegation rides the v3-0 `expires_at` seam.

---

## 🧩 PWA + i18n polish on `claude/pwa-i18n-polish` — 2026-06-17 (off a freshly-integrated `main`)

**Unattended, owner-authorized session.** **Step 0** first integrated the outstanding UI/UX branch into `main`
(`git merge --no-ff claude/ui-ux-nav-login-infocenter` → `da4c460`, gated green: 1596 pass / PHPStan L5 / Pint /
migrate) and cut this branch off it. **NOTHING IS PUSHED** — pushing `main` + reconciling origin is interactive-only;
the owner does it. Conventional, DCO-signed, `Tommy Huynh`-authored commits, gated in `forum-dev` at each green
boundary. ADRs: **0078** (PWA), **0079** (i18n).

**Unit A — PWA subpath-aware + raster icons (ADR-0078):** the manifest `start_url`/`scope` + icon srcs, the SW
registration scope, `Service-Worker-Allowed`, and the SW's own `SCOPE` (read from `registration.scope`) all derive
from the mount base, so the PWA installs + the service worker registers/caches under a `/community/` subdirectory
mount as well as a domain root (a byte-identical no-op at a root). Added 192/512 `any` + a full-bleed `maskable-512`
PNG (rasterized from `novfora.svg`). **Resolves the ADR-0070 PWA-under-a-subpath deferral.**
`tests/Feature/Pwa/PwaTest.php` → 14 cases green.
  - **⚠ OWNER VALIDATION (real device/host, not machine-verifiable here):** install the app under a `/community/`
    mount and confirm (1) the install prompt appears, (2) the SW registers — DevTools → Application → Service Workers,
    scope `/community/`, and (3) the install/home-screen icon shows the blue "N" PNG, not a blank square.

**Unit B — i18n view-string sweep, wave 1 (ADR-0079; extends ADR-0043/0073):** externalized the **forum**,
**members**, and **profiles** domains into `lang/en/forum.php` + `profiles.php` (joining auth/common/errors/search),
plus `common.edit` and the **members labels in `common.*`** (members/directory/top_members — NOT a `members.php`
group; see the collision lesson). Each is a gated, DCO-signed `i18n(<domain>)` commit with a
per-domain guard test (`tests/Feature/I18n/*LangKeysTest.php`: keys resolve + a page renders English with no raw
`"<domain>."` token). **English output is byte-for-byte unchanged** (curly punctuation preserved; count suffixes kept
as static keys — trans_choice would change the n=1 text). A residue scan verified the three swept domains carry no
remaining bare literals.
  - **Residue (recorded, community-contributable — same pattern):** clubs (~25), settings (~20), notifications (~20),
    tags (~15), pm (~12), the ACP `admin/*` (~150+), and the Livewire ⚡ components under `resources/views/components/**`
    (~370+, the largest pool). Highest-value next: components → admin → clubs + settings.
  - **Lesson logged:** the forum sweep was delegated to a Sonnet sub-agent which introduced **smart-quote
    delimiters** (`__(‘forum.x’)`) in two empty-state blocks → a 500 the per-domain guard missed (it didn't hit the
    empty path) but the full suite caught. Fixed (perl byte-replace); members/profiles were then done in-loop with
    straight quotes. Takeaway: gate agent-edited Blade with the FULL suite, not just the domain guard.
  - **Lesson logged (case-collision):** a `lang/en/members.php` group made `__('Members')` (the live string-key in
    ForumStatsWidget / clubs / nav) return the whole array on the case-insensitive bind-mount → 500. Fix: members
    labels live in `common.*`, no `members.php`. **Rule (ADR-0079):** never name a group file after a word used as a
    bare `__('Capitalized')` key. `forum`/`profiles` groups are safe only because grep confirmed no `__('Forum')` /
    `__('Profiles')` caller. The full suite (not the domain guard) caught this too.

**Branch commits (local, unpushed):** `da4c460` merge → then `518bb18` feat(pwa), `af6b430` chore(pwa icons),
`d6d8405` i18n(forum), `13e70ff` i18n(members), `1e75f74` i18n(profiles), + the ADR-0079 docs commit.

## 🎨 UI/UX polish on `claude/ui-ux-nav-login-infocenter` — 2026-06-17 (MERGED → `main` via `da4c460`)

Three independent, conventional, DCO-signed, `Tommy Huynh`-authored commits, cut off `main` after `claude/phase-5-ga`
(PR #30) and RH-4 landed. **NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner pushes + opens
the PR. Sonnet-class per CLAUDE.md routing (view boilerplate / tiny CRUD — none hit the apex). Each gated in
`forum-dev` at a green boundary (`test --parallel` · PHPStan L5 · Pint · `migrate`).

- **Fix 2 — login i18n (`fix(i18n)`):** `lang/en/auth.php` (shipped on phase-5-ga) overrides Laravel's `auth.*`
  namespace but omitted the framework scaffolding strings, so a failed/throttled login rendered the raw `auth.failed`
  token. Added `failed` + `throttle`. The third default — the `password` string — is **intentionally NOT added**:
  `auth.password` is already the forgot-password UI group, and the only `current_password` check
  (`App\Actions\Fortify\UpdateUserPassword`) supplies its own message, so nothing reads the framework string (a
  duplicate key would just be shadowed). Guard: `tests/Feature/Auth/AuthLangKeysTest.php`.
  ⚠ **DEPLOY GAP — owner action (no repo fix possible):** the live `dev.novfora.com/login` raw `auth.login.*` render
  is a host build that shipped the keyed Blade views **without** the `lang/` directory. Redeploy **including `lang/`**
  then run `php artisan optimize:clear` on the host (verify `ls -l lang/en/auth.php` there first).
- **Fix 1 — responsive header (`fix(ui)`, CSS-only):** the single-breakpoint header wrapped the wordmark at mid
  widths. Brand link `shrink-0 whitespace-nowrap` (+ small-screen truncate guard); search is the one flexible child
  (`min-w-0`, deferred to `md`); nav `md:gap-1`; auth cluster `shrink-0` + `ml-auto md:ml-1`. Deliberate trade-off:
  search leaves the bar in the 640–767px band (the hamburger owns it there). Brand-markup guard added to the
  public-routes smoke test.
- **Fix 3 — classic Info Center (`feat(forum)`, ADR-0077):** Statistics + opt-in Who's-Online panels above the
  activity feed. `App\Forum\InfoCenter` read-model caches primitives only (RH-9) under `novfora:infocenter:stats`,
  rehydrates the newest member after the boundary; aggregate-only (no hidden-forum leak); **no migration**.
  `tests/Feature/Forum/InfoCenterTest.php` (6 cases).

---


<!-- Moved from PROJECT-STATE.md 2026-07-17 (v1.4 Phase 0 reconcile) — the nine session reports covering 2026-06-22 → 2026-07-03 (Design-Polish through v1.3 Phase 3B), newest first. -->

## 🌅 Morning report — v1.3 Phase 3B (front-of-site redesign · UX-1/3/4/5/6) — `nov-3b-frontsite` off nov-96, gated GREEN; NOTHING merged/pushed (owner reviews) (2026-07-03)

Ran [`docs/product/BUILD-PROMPTS-2026-07-02.md`](docs/product/BUILD-PROMPTS-2026-07-02.md) **Prompt 2** (v1.3 Phase 3B ·
Front-of-site redesign) after owner plan approval (base fork resolved: **stack on nov-96**, the 3A contract branch).
Built the view-layer redesign in the existing token system — **no new JS deps**, every action affordance through the
3A `<x-action>` contract — on **`nov-3b-frontsite` (off `nov-96` `72956ac`)**. Committed as `Tommy Huynh` (DCO `-s`, no
AI trailers). **Nothing merged/pushed — `main` untouched at `6724a9a`.** The owner said "proceed with everything," so I
continued past the demo checkpoint through UX-4/5/6 (the backup-first demo deploy remains the owner's to run).

### Branch (one cohesive branch off nov-96, per-slice commits)
```
main 6724a9a → nov-96 72956ac (3A contract) → nov-3b-frontsite:
  c1d532e  UX-6 · x-ui.timestamp (semantic <time>, the shared primitive)
  d5baa3b  UX-1 · card-based board index (category cards, refined rows, timestamps)
  5734c92  UX-3 · sm–md search discoverability entry
  ── demo checkpoint (owner deploys backup-first when ready) ──
  efc788d  UX-4 · board + thread (signed-out new-topic CTA via <x-action>, timestamps)
  4dd2089  UX-5 · focused centered auth header (+ rebuilt public/build)
  43c69be  test · 390px front-of-site Dusk journeys (CI-pending)
```

### Slices
- **UX-6 (NOV-95):** `<x-ui.timestamp>` — a real `<time datetime>` (machine-readable ISO) + full-date title tooltip,
  relative text, tabular-nums — adopted on the redesigned surfaces, replacing bare `diffForHumans()` spans. (A
  `<x-forum.topic-badges>` extraction was considered but DEFERRED — the three badge clusters differ deliberately: the
  thread uses `warn`/larger to emphasize the *current* locked thread vs the lists' `neutral`/calm; flattening would be a
  design regression, not a DRY win.)
- **UX-1 (NOV-90) — the headline change:** the forum index is now a **card-based board** — each category is a distinct
  card (its name is the card header) inside a labelled `<section>`; forums are refined rows with a larger icon and
  `<x-ui.timestamp>`. Reuses the existing F6 last-post/author data — **no new queries** (index budget ≤20 holds);
  permission-scoping + theme regions intact.
- **UX-3 (NOV-92):** the header was already premium (sticky+backdrop, NavigationManager-driven, NOV-86 responsive brand,
  canDo-gated admin/mod links), so the one clear safe win: a **compact search entry for the sm–md range (640–767px)** —
  where the hamburger (with its search) is gone (≥sm) and the full search bar isn't shown yet (<md), search was
  unreachable.
- **UX-4 (NOV-93):** the board's new-topic control routes through **`<x-action>`** — the live button when you can post, a
  **"Sign in to post" CTA for guests**, hidden for a member without the capability. Board + thread times adopt
  `<x-ui.timestamp>`. (The board table + thread post-cards were already scannable/info-rich.)
- **UX-5 (NOV-94):** a **focused, centered auth header** (brand + serif title, calmer rhythm) over the existing card;
  login/register/reset forms unchanged (already clean — `x-ui.input`, social providers).

### Gates (GREEN, modulo documented env)
- **a11y:** `WcagAuditTest` green — it already audits the board index, a topic page, `forums.show`, login, and register
  (all my redesigned surfaces), and the nav on every page; stays green.
- **HotPath:** the forum-index budget (≤20) holds (UX-1 added no queries). Two `QueryBudgetTest` **thread** budgets read
  +1 in *this* drvfs env — I confirmed they **fail identically on `main`** (pre-existing warm-up variance; pass at 34/36
  on CI's clean ext4). A query dump verified **no N+1** (all batched `IN(...)` reads). Not from this work.
- **Assets:** UX-1/3/4 introduced **zero** new utilities; UX-5 added `py-8`/`sm:py-12` → `public/build` rebuilt. The
  build is **byte-deterministic** here (verified — a clean rebuild reproduces the committed bundle with the exact locked
  font versions), so the committed bundle matches CI's and `assets-fresh` holds. No new JS deps.
- **Render/logic:** `Forum` + `Auth` suites green (197 passed; the only 3 errors are the pre-existing root-owned
  `storage/framework/testing/disks/.../attachments` dir — environmental, green on CI's ext4).
- **390px Dusk:** new `MobileFrontSiteJourneyTest` (card index + board list fit 390px no-scroll; sm–md search reachable)
  — **CI-pending** (no Chrome here; `php -l` clean). It must be added to the dusk-job spec list, which lives on the
  **nov-122** branch — a Prompt-4 merge reconciliation note.

### Design posture (for your eval)
Deliberately conservative on the already-polished surfaces (nav, board table, thread post-cards, auth forms — refined by
prior passes). The **card index (UX-1)** is the main visible modernization; the rest is high-value, low-risk polish
(timestamps, the sm–md search gap, the guest CTA, the auth header). The demo/beta round is where nav + board feedback is
most valuable.

### ☀️ What the owner does next
1. **Review `nov-3b-frontsite`** (`git diff nov-96..nov-3b-frontsite`) — 6 commits, all view-layer, gated green. Rides
   nov-96 (the 3A contract); **main untouched**.
2. **Deploy UX-1+UX-3 to demo backup-first** — the checkpoint deploy is still yours to run (I continued past it on your
   "proceed with everything"); beta feedback on the card index + nav is most useful.
3. **Linear:** move **NOV-90/92/93/94/95 → In Progress** (no Linear write path this session).
4. **Merge order (Prompt 4):** nov-96 → nov-3b (nov-3b rides it); the 390px Dusk spec joins the dusk-job list when
   nov-122 merges.
5. Merge/tag is **Prompt 4's** v1.3.0 release run.

---

## 🌅 Morning report — v1.3 Phase 3A (NOV-96 · NOV-121 · NOV-122) — three branches off `main`, gated GREEN, apex-reviewed; NOTHING merged/pushed (owner reviews) (2026-07-03)

Ran [`docs/product/BUILD-PROMPTS-2026-07-02.md`](docs/product/BUILD-PROMPTS-2026-07-02.md) **Prompt 1** (v1.3 Phase 3A ·
Foundation & hygiene) end-to-end after owner plan approval (incl. the one plan fork: **wire** the ADR-0087 fan-out).
Built the three slices as **independent branches off `main` (`6724a9a`)**, gated green at each boundary, ran the apex
verify-then-refute review on the two ◆ slices, committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Nothing merged,
nothing pushed — `main` untouched at `6724a9a`; the v1.3.0 merge/tag is Prompt 4's job.**

### Branch topology (3 independent slices off `main` `6724a9a`)
```
main 6724a9a (untouched)
├─ nov-121-engine-hygiene     52343d9  ◆ last-plain-admin removal guard + delegation fan-out (ADR-0086/0087 amend)
├─ nov-96-permission-aware-ui 72956ac  ◆ Affordance + <x-action> + route-level friendly-403 (ADR-0109)
└─ nov-122-ci-completion      0b126bc     Dusk specs wired + route:clear + guzzle audit bump + assets verified
```
Disjoint (engine vs. view-layer vs. CI config) — no stacking, no cross-branch code conflict expected. **Suggested merge
order (Prompt 4): nov-121 → nov-96 → nov-122** (the only overlap is the DECISIONS.md append tail — keep 0086/0087
amendments AND 0109 — and PROJECT-STATE.md, this report).

### NOV-121 — Engine hygiene ◆ (`nov-121-engine-hygiene` `52343d9`; ADR-0086 + ADR-0087 amended)
- **Door #1 (ADR-0086 gap closed):** `GroupManager::removeMember` guarded only the co-owner tier — the sole PLAIN admin
  could be detached into a zero-reachable-admin strand. Now asserts `OwnerStrandGuard::wouldStrandAdminTierLocked()` as
  the FIRST act inside the transaction (id-based, so a missing User model can't skip it), the SAME `group_user→users→bans`
  lock order + ban-aware reachable-owner semantics as the ban/delete/demote doors, throwing `GroupException` (the ACP
  Groups SFC already flashes it).
- **Door #2 (ADR-0087 gap closed — owner chose WIRE):** a group losing a key via `GroupPermissionEditor` was not
  cascaded, so a co-owner's delegation could exceed their reduced CURRENT mask for ≤30 days. Wired
  `DelegationService::onGroupMaskChanged()` (both editor SFCs + the category bulk-apply, after a reduction) → the bounded
  `CascadeDelegationsJob` re-checks members-with-live-delegations via the proven `cascadeForActor()`. Bounded to actual
  delegators (a group with none is a no-op), chunked, cron-drainable (ADR-0097); an `exists()` pre-check keeps routine
  delegation-free saves off the queue. "Never exceeds the delegator's current mask" now holds across every reduction door.
- **Gates:** GroupManagerTest + DelegationFanoutTest **20/20**; blast-radius regression (Admin/Permissions/Groups/Account/
  Moderation) **551/551**; Pint clean; PHPStan **0**; **no migration**.
- **Apex review (verify-then-refute, inline):** **GO, 0 confirmed HIGH/MEDIUM** across 24 hostile hypotheses (TOCTOU
  serialization, ban-race reachability, over/under-revocation, runtime-only DelegationService↔Job cycle, retry
  idempotency, reduction-predicate completeness). One proactive hardening applied (the id-based admin guard).

### NOV-96 — Permission-aware UI contract ◆ (`nov-96-permission-aware-ui` `72956ac`; ADR-0109)
- The five affordance outcomes behind ONE contract (kills the ghost-UI bug class — BETA-4/ADR-0105 — by construction):
  `App\Permissions\Affordance` (enum + `resolve()`, the pure map → Allow/DisabledWithReason/SignInCta/Hidden; caller
  declares intent, both default to safe `hide`); the `<x-action>` Blade component renders each in-view state (`:can` takes
  the authorization VERDICT — the server policy stays the enforcement authority, UI only); `App\Exceptions\
  FriendlyDenialException` (`::deny`) is the route-level friendly-403 — a full-page denial → an auth-aware explainer
  (sign-in CTA for guests) carrying only a curated i18n reason (no leak), still a real 403 for middleware/status/JSON;
  the catastrophe-safe `errors/403` page is untouched. i18n in `lang/en/permissions.php`.
- **Adopted this pass** (Phase 3B does the wide view-layer sweep "through the 3A contract"): topic per-post edit/delete +
  the profile staff account-tools via `<x-action>`; the user-delete confirm GET via friendly-403.
- **Gates:** ActionAffordanceTest + FriendlyDenialTest **13/13**; blast-radius regression (Account incl.
  `AccountDeletionUiTest`, Forum incl. `TopicModerationVisibilityTest`, + Moderation/Permissions/Community/I18n/
  Accessibility/Follow/Ignore) **450/453** — the 3 errors are the root-owned `storage/framework/testing/disks/.../
  attachments` dir (environmental, unrelated; green on CI's clean ext4 checkout). Pint clean; PHPStan **0**; **no migration**.
- **Apex review (verify-then-refute, inline):** **GO, 0 confirmed HIGH/MEDIUM** — no widening (`:can` is the policy
  verdict; a forged request to any hidden control still 403s), no friendly-403 leak (curated reasons only), reason
  `{{ }}`-escaped. One RENDERING defect found + fixed: a Blade `@if`-in-attributes on `<x-ui.button>` corrupted the
  compiled component (caught by the render tests).
- **Asset-freshness (verified build-independently):** the two NEW views introduce **zero** new Tailwind utilities — every
  class is already generated in the committed bundle (`friendly-denial` was swapped `rounded-2xl`/`p-8` → the present
  `rounded-lg`/`p-6` to keep it so). So `public/build` stays fresh and CI's `assets-fresh` guard passes with NO rebuild.
  (A clean `npm run build` was not possible here — this env's `node_modules` is missing `@fontsource/inter`; freshness
  was proven by class-presence inspection against the committed CSS, which needs no build.)

### NOV-122 — CI completion (`nov-122-ci-completion` `0b126bc`)
- **Dusk wired:** all 13 accumulated CI-pending Browser specs added to the dusk job's app pass (InstallerWizardTest stays
  PASS 1, enforce-ON); each self-seeds. All `php -l` clean. **No Chrome in this env → CI is the green-verifier** (the
  standing Dusk-CI-pending rule; Phase-3A's "Dusk green in CI" exit criterion closes on the CI run).
- **route:clear** added to the test job + the dusk serve loop — kills the stale `bootstrap/cache/routes-*.php`
  SubdirInstall/PWA false-fails (the 2026-06-22 env finding).
- **composer audit:** bumped guzzle 7.12.1→7.13.1 + psr7 2.12.1→2.12.3 (CVE-2026-55568 HTTPS-proxy downgrade;
  CVE-2026-55766 CRLF injection) — `composer audit --no-dev --locked` clean; lock-only (composer.json untouched).
- **Asset budget:** verified — committed `public/build` fresh + within budget (main JS ~2 KB gz, CSS ~12.5 KB gz vs the
  50 KB gz ceilings); no re-baseline needed. (Not apex — no adversarial review.)

### Environment notes (this build env — same class as prior sessions, per MEMORY)
Native WSL2 on a `/mnt/d` drvfs mount, **PHP 8.5.7** (CI is 8.3), **no Docker `forum-dev`, no Chrome**, several
**root-owned dirs** from a prior root run (`vendor/pestphp/pest/.temp`, `storage/framework/views/livewire`,
`storage/framework/testing/disks/.../attachments`) + an **incomplete `node_modules`** (`@fontsource/inter` missing).
Worked around: pointed `VIEW_COMPILED_PATH` at a fresh ext4 dir (fixed intermittent Livewire `tempnam` failures on the
root-owned compiled dir + sped up SFC tests); gates ran on the native toolchain (sqlite `:memory:`). **CI (clean ext4,
PHP 8.3) closes the two env-gated items: Dusk green + the 3 attachment-storage tests.**

### Parked decisions — RECOMMENDATIONS (Prompt 1: surface with a recommendation; do NOT implement without owner answer)
- **U8 imported-username revert (ADR-0106).** A legacy/imported handle that violates the modern `alpha_dash/min:3/max:30`
  rule can't be reverted (revert re-validates and fails). **Recommend: let a revert bypass the format rule for a value
  that genuinely exists in `username_history`** — it WAS a real handle, uniqueness is still enforced, the admin explicitly
  chose it; the format rule should gate NEW handles, not restoration of a historical one. Low risk, closes the gap.
  (Alt: keep strict — some imported handles stay un-revertable.)
- **U18 Turnstile posture (ADR-0107).** The shipped Turnstile driver stays fail-OPEN; the two new drivers
  (hCaptcha/reCAPTCHA) are fail-CLOSED. **Recommend: make posture a per-driver config with fail-CLOSED the default,
  keeping Turnstile's fail-open only as an explicit operator opt-in** — a CAPTCHA failing open under a provider outage
  lets spam through; fail-closed is the safer default. (Alt: keep Turnstile's documented fail-open; the posture
  inconsistency stays.)

### Linear (team NovFora) — pending moves (no Linear tool this session; owner applies)
**NOV-96 / NOV-121 / NOV-122 → In Progress** (or In Review) at run start; the owner flips to **Done** on merge (prior-
session convention). The harness had no Linear write path this session.

### ☀️ What the owner does next
1. **Review the 3 branches** (`git diff main..<branch>`): `nov-121-engine-hygiene` (52343d9), `nov-96-permission-aware-ui`
   (72956ac), `nov-122-ci-completion` (0b126bc). All gated green locally; apex **GO** on both ◆ slices. **Nothing
   merged/pushed — `main` untouched at `6724a9a`.**
2. **Decide the two parked items** (U8 revert rule, U18 Turnstile posture) — recommendations above; Phase-3A gate
   decisions, not implemented pending your answer.
3. **Run CI** to close the two env-gated items: **Dusk green** (all specs wired; needs Chrome/CI) + the 3
   attachment-storage tests (root-owned dir here; pass on ext4).
4. **This report** lives on `claude/v13-phase3a-report` (off `main`) — merge it alongside the slices.
5. Merge/tag is **Prompt 4's** v1.3.0 release run, not this one.

---

## 🌅 Morning report — FABLE session: Populate private plugin (E6a engine + E6b Studio) BUILT FIRST against v1.2.x — plugin repo complete + gated GREEN; one small core seams branch; owner reviews (2026-07-02)

Ran [`docs/product/BUILD-PROMPTS-2026-07-02.md`](docs/product/BUILD-PROMPTS-2026-07-02.md) **Prompt 0** end-to-end,
unattended, after owner plan approval ([`docs/product/POPULATE-E6-PLAN-2026-07-02.md`](docs/product/POPULATE-E6-PLAN-2026-07-02.md)).
Built the **Populate private plugin** (NOV-140 E6a ◆ APEX + NOV-143 E6b) — content seed-import + drip engine +
the four-pane Studio — as a **separate private repo `D:\novfora-populate`** (never committed to this repo, never in
the release zip / public Registry). Committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Nothing pushed.**

### Two-repo topology
- **`D:\novfora-populate`** (PRIVATE, its own git repo — 11 commits, branch `main`): the plugin package
  (`plugin/` = `novfora/populate`, `api_version ^1.2`), the updated ForumGen script (`script/generate_forum.py`),
  schema docs, an example plan fixture, the signed-zip build recipe. The plugin's working tree lives at
  `modules/novfora/populate` in THIS repo via an NTFS junction, git-excluded here (`.git/info/exclude`), so
  `forum-dev` sees live files — but it is **not** part of this repo.
- **`D:\Forum` branch `claude/populate-generic-seams`** (2 commits ahead of `main`; **`main` untouched at
  `e04d071`**): the ONE anticipated core change — generic module seams the plugin rides (ADR-0120). Flagged per
  the plan; the plugin depends on it (`api_version ^1.2`), so this branch merges FIRST.

### Core seams branch (`claude/populate-generic-seams`, ADR-0120, Module API 1.1→1.2)
Two additive, generic filter hooks (any module can use them — an Akismet scorer wants the first; any
account-scoping module wants the second):
- **`moderation.verdict`** (escalate-only) at the end of `ContentModerator::review()` — a module may only RAISE
  the tri-state outcome (allow→hold→reject), never lower it. Powers the plugin's HOLD-into-modqueue fence.
- **`stats.users.query`** at every member aggregate-count site (`ForumStatsWidget`, `AnalyticsService`) —
  counts-only. Powers the stats-exclusion fence.
- **`GroupAutoPromoter::promote()` now exempts `trust_locked` accounts** — an apex-review finding (see below).
Zero behavior change with no hooks registered. Gates: AntiSpam+Analytics+Modules+Groups+Unit **278 passed**;
Pint + PHPStan(app/) clean. ADR-0120 lifted into `DECISIONS.md` (next-free after 0108; the roadmap's 0109–0116
reservations kept, so 0120 as the spec's §5 sketch suggested).

### The plugin (E6a engine + E6b Studio) — spec §4, all fences default-on
- **E6a engine ◆:** strict plan validator (hostile-JSON fence — key allowlists make identity/authority
  smuggling structurally impossible; entity/size caps; sim-domain enforced; time-paradox rejection). Backfill
  through the **ADR-0034 ImportRunner** (`PlanSourceDriver`) + a **counter-finalize pass** (the runner has none —
  a recon-surfaced gap). Sim-account hardening (unusable hash, members+tl1 only, `trust_locked`, suppression row
  per address, `populate_users` provenance map — no core column). **Drip runner** on the digest transactional-claim
  discipline (CAS claim, per-plan daily caps, atomic publish, stale-claim self-heal). **Pacing engine** ported
  from ForumGen (diurnal/weekday/ratio/decay/jitter + curve presets/quiet-periods/spikes; deterministic). **Purge**
  = byte-equal reversal. **Fences:** login refusal (Fortify `Login` listener + unusable hash), HOLD mode, mail
  suppression, bot flair (`topic.post.aside` slot), stats exclusion, never-staff/never-login — all proven.
- **E6b Studio:** four server-rendered ACP panes (module routes behind `auth`+`verified`, with admin.access +
  staff-2FA + `novfora.populate.manage` re-asserted in every controller action) — Persona Studio (ForumGen's 10
  archetypes seeded, CRUD, pack import/export), Timeline & Pacing Designer (**live preview from the REAL pacing
  engine** — `x-ui.sparkline` + sample 48h schedule), Content Controls (versioned genconfig, primary group fixed
  to members), Run Console (dashboard, kill switch, pause/resume, dry-run, co-owner-gated purge with typed
  confirm). No new JS deps. (Livewire-4 SFCs can't be registered from a module — server-rendered per the plan's
  sanctioned fallback; module named routes resolve via a `RouteCollection::refreshNameLookups()` after the group.)
- **Script:** `generate_forum.py --config genconfig.json` → plan schema v1; keys are **env-only** (the plaintext
  Gemini key in the `F:\ForumGen` copy is dropped — **owner should revoke that key**); sim domain from config.

### E6a apex adversarial review (verify-then-refute, 6 lenses × per-finding refutation) — **3 confirmed HIGH, ALL FIXED, re-gated**
Ran on **Opus 4.8** (the apex fallback — Fable errors for spawned workflow agents here, per MEMORY). Every finding
was a real defect the green suite missed:
- **HIGH — drip mutex race:** the 120s engine-lock TTL could expire under an unbounded beat, letting a purge race
  a live publish (orphan post, non-byte-equal). **Fixed:** 450s TTL (10× the new wall-clock beat budget) + hard
  `tick_batch` clamp bound the beat inside the lock; the publish state-flip is now a **CAS** (`WHERE state='claimed'`)
  so a purge-flipped event rolls its post back — no orphan.
- **HIGH — purge activity-feed ghost:** drip publishes fire Topic/PostCreated → synchronous activity listeners
  write `activities` rows purge never swept (a "[Deleted]" feed ghost). **Fixed:** purge sweeps `activities` for
  every purged topic + post id.
- **HIGH — sim-account escalation via `GroupAutoPromoter`:** `trust_locked` fences `TrustLevelManager` but NOT the
  separate custom-group auto-promoter, so a sim account could be auto-promoted into a capability-bearing custom
  group. **Fixed in core** (seams branch): `promote()` exempts locked accounts.
Plus 1 LOW fixed (real members' follows of purged sim topics now swept). 0 findings survived unrefuted.

### Gates (all GREEN)
- **Plugin suite:** `pest modules/novfora/populate/tests` → **83 passed / 467 assertions** (validator, importer,
  fences, drip, pacing, purge, Studio, generator round-trip, compressed-clock E2E, U17 dogfood). Pint clean;
  **PHPStan L5 (project larastan config) 0 errors** over the plugin src.
- **Compressed-clock E2E (spec §5):** import a ForumGen plan → 48h of simulated cron beats → purge byte-equal on a
  lived-in two-forum board → module uninstall drops plugin schema only.
- **U17 dogfood:** the signed zip (built via `script/build-package.sh`, ed25519 `module.sig`) installs through the
  **real `installFromZip` pipeline** (ArchiveGuard → PackageSignature → trust registry → ModuleManager) against a
  throwaway modules root — safe extraction (no `tests/` shipped), signature verified against the trusted key +
  rejected for an untrusted key + on tamper, then enable → import → purge → uninstall clean.
- **Core stays clean:** the plugin is inert unless installed+enabled; the core suite (278) passes with the seams
  branch and no plugin present.

### Linear (team NovFora)
Both moved to **In Progress** at start. Work is complete + gated; left In Progress for owner review/merge (they
flip to Done on merge, matching the prior-session convention). Completion comments attempted — see ☀️ if any write
was blocked.

### ☀️ What the owner does next
1. **Review two repos:** the private plugin `D:\novfora-populate` (11 commits on `main`) and the core seams branch
   `claude/populate-generic-seams` (2 commits). **Merge the seams branch to `main` FIRST** (the plugin's
   `api_version ^1.2` depends on it), then push `origin main` (blocked here — protected branch).
2. **Revoke the Gemini API key** committed in the old `F:\ForumGen\generate_forum.py` (the new script is env-only).
3. **Distribute the plugin:** generate the owner release keypair (`php artisan novfora:module:sign --keygen`),
   build the signed zip (`script/build-package.sh`), add the public key to a target instance's ACP trusted keys,
   and install via ACP → Plugins on instances you personally grant it to. Keep `NOVFORA_MODULES_ALLOW_UNSIGNED=false`.
4. **Run Dusk in CI** for the new ACP Studio panes (no Chrome here — server-render + auth-gated in the plugin suite).
5. Set **NOV-140 / NOV-143 → Done** on merge.

---

## 🌅 Morning report — FABLE session: v1.2.0 (UI-audit reconcile + 4 BETA fixes + U8/U18/U20) — MERGED to local `main`, gated GREEN, TAGGED; owner pushes (2026-07-02)

Ran [`docs/product/FABLE-V1.2-KICKOFF.md`](docs/product/FABLE-V1.2-KICKOFF.md) end-to-end, unattended. Built the four
live-beta fixes + the three Phase-6 quick wins as independent branches off `main`, verified each against `main` first,
gated green at every boundary, ran the apex verify-then-refute review over all six seam-touching diffs, merged the
seven green slices into `main`, re-gated the union green, bumped to **1.2.0**, cut + verified the release artifact, and
**tagged `v1.2.0`**. Committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Everything is local — the `git push
origin main` + `git push origin v1.2.0` was BLOCKED by the harness protected-branch guard and is the owner's one
remaining git step** (see ☀️ below). Pre-merge backup: tag **`backup/pre-v12` (`0fa01a6`)**.

**`v1.2.0` annotated tag on `4ef4d24`** (the release commit — fast-forward-safe over `origin/main` `ab013c8`); this
morning-report doc commit sits on top. The owner pushes both `main` and the tag (see ☀️).

### Step 0 — baseline reconcile (before any feature work)
The consolidated `main` (`ab013c8`) was NOT green as-received: **two leftover `=======` merge-conflict markers** from
the owner's U7/U17 consolidation (in `.env.example` and `DECISIONS.md`) — the `.env.example` one broke
`EnvWriterSecurityTest` and would corrupt any real install's generated `.env` (dotenv parse error). Fixed (`a8eabb7`).
Also re-baselined **`HotPathQueryTest`**: on this VPS the tree carries `storage/installed`, so the first request pays
`SchemaState`'s one-time pending-migrations probe (+2 queries) that the markerless `forum-dev` never pays — the
topic-page budget read 42 here vs 40 in the container. Primed the cached flag in `beforeEach` so every host measures
the same steady state (`8a6d230`). Rebuilt assets for the U7+U17 views (`0fa01a6`). Baseline then **2189/0** green.

### Branch topology (7 independent slices off `main` `0fa01a6`)
```
main 0fa01a6 (baseline-green)
├─ nov-86 BETA-2  4a936b8   mobile nav spillover (header brand min-w-0)
├─ nov-85 BETA-1  63e8033   notification read-state (open-route + un-latch) [+harden]
├─ nov-87 BETA-3  b1695b5   DM 403 → friendly explainer (◆ apex, no widening)
├─ nov-88 BETA-4  11816b1   ghost-UI mod controls + bulk-lock semantics (◆ apex, ADR-0105) [+harden]
├─ nov-97 U8      7b18646   username history + admin revert (ADR-0106) [+harden]
├─ nov-98 U18     98d7555   hCaptcha/reCAPTCHA fail-closed + Gravatar (◆ apex, ADR-0107)
└─ nov-99 U20     3de26c3   SEO polish + pending-topic leak fence (◆-lite apex, ADR-0108)
```
Merged in order BETA-2 → BETA-1 → BETA-3 → BETA-4 → U8 → U18 → U20 (7 `--no-ff` merges + version bump + asset
rebuild). Conflicts were **DECISIONS.md tail** (append-only ADR stacking — kept 0105→0106→0107→0108 in order) and a
clean **auto-merge** of `TopicController`/`layouts`/`topic.blade` where BETA-4 and U20 both edit the topic-show path
(verified coherent by hand: U20's fence hoists `$user/$scope/$canModerate`, BETA-4's `$canMergeTopic` + `author.groups`
land after — no duplicate, no lost change). The `build-release.sh` allowlist was reconciled to drop the now-deleted
static `public/robots.txt` (U20 serves it dynamically).

### Union gate (merged `main`, all GREEN)
- `php artisan test --parallel` → **2251 passed / 1 skipped** (the Dusk CI-only spec) · **15 532 assertions**
- `pint --test` clean · `phpstan` **0** (app/) · migrate **apply + rollback(all) + re-apply** clean on a throwaway
  SQLite (2 new reversible migrations land this release: `module_trust_keys` from U17, `username_history` from U8)
- `npm run build` OK → assets committed (`6d8932d`); ViteManifest / asset-budget gate green
- **Release artifact:** `scripts/build-release.sh` → `novfora-release.zip` (16 MB), `scripts/verify-release.sh` →
  **RELEASE_VERIFY=PASS** (cold-boot 302→/install, i18n resolves, `packages.php` ships, no env caches)
- **Dusk = CI-only** (no Chrome on the VPS) — 3 new specs added CI-pending (`MobileHeaderJourneyTest` at 390/360/768px)

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| BETA-2 | `nov-86-...` | `4a936b8` | Pest✓ Pint✓ | brand `min-w-0`; markup + Dusk-CI 390px specs |
| BETA-1 | `nov-85-...` | `63e8033` | Pest✓ PHPStan0 | `notifications.open` click-through; dropdown un-latched; +open-redirect harden |
| BETA-3 | `nov-87-...` | `b1695b5` | Pest✓ PHPStan0 | ◆ apex GO/0-open; explainer not 403; service enforcement untouched |
| BETA-4 | `nov-88-...` | `11816b1` | Pest✓ PHPStan0 | ◆ apex GO/0-confirmed; ADR-0105; +eager-load & flash harden |
| U8 | `nov-97-...` | `7b18646` | Pest✓ PHPStan0 migrate✓ | ADR-0106; +uniqueness-race→field-error harden |
| U18 | `nov-98-...` | `98d7555` | Pest✓ PHPStan0 | ◆ apex GO/0-confirmed; ADR-0107; fixed latent Turnstile CSP gap |
| U20 | `nov-99-...` | `3de26c3` | Pest✓ PHPStan0 | ◆-lite apex GO/0; ADR-0108; +pre-existing pending-topic leak fence |

### Apex adversarial review (verify-then-refute, 6 reviewers × per-finding refutation) — **GO, 0 confirmed HIGH/MEDIUM**
Ran one apex reviewer per seam-touching diff (BETA-1/3/4, U8/U18/U20), then an independent refuter on every HIGH/MEDIUM.
**Both HIGH/MEDIUM candidates were REFUTED** on verification (not exploitable in the committed code): the BETA-1
open-redirect (all notification URLs are `route()`-generated — no injection path) and the U18 hCaptcha `connect-src`
(the checkbox widget doesn't touch the parent page's `connect-src`). I still applied **three cheap LOW hardenings** on
my own new code because they're correct regardless:
- **BETA-1** — the same-origin redirect check was a bare prefix match (`str_starts_with($url, url('/'))` admits
  `host.evil`, `host@evil`, `hostevil.com`); replaced with a host-boundary match (`base+'/'`) rejecting `//host`/`/\host`.
- **BETA-4** — `$canMergeTopic` lazy-loaded `topic.author->groups` (+1 query on the boundary-flaky moderator budget) →
  eager-loaded; the bulk-skip flash cited "rank" for lock, which no longer rank-gates → reworded.
- **U8** — a rare uniqueness race surfaced as a 500 → caught the integrity violation (SQLSTATE 23000/23505) → field error.

Accepted-and-noted LOWs (not fixed — reasoned): BETA-1 GET-side-effect (idempotent, owner-scoped); BETA-3 TL0 reaching
the read-only username autocomplete (usernames are already public via the members directory); U18 global CSP host
allowlist (fail-safe); U8 imported usernames that violate `alpha_dash/min:3/max:30` are un-revertable (a product call —
should revert bypass the format rule for a historical value? — **parked** for the owner).

### Verified-and-skipped (Track UA — the whole 21-finding UI-audit spec)
**Every Track-UA bug was already fixed on `main` before this session** — by **PR #41** (`b437462`, "UI-audit reconcile",
merged 2026-06-27), NOT PR #49. A spot-check confirmed all 21 findings intact at HEAD and all 12 named test files green
(40/40). So BUG-001/002/003/004/005/007/008/009/010/011/012/013/014/016/017/018/019/020/021 are **shipped, not
rebuilt**; BUG-006 stays reclassified (not a defect). **PR-#49 overlap explained:** PR #49 (`011cf6c`) was the ACP
members-directory PII/gating change (A1) — it does **not** touch BUG-007/008/015 or BUG-019; the kickoff's "likely PR
#49" hint was mistaken (those are PR #41's `d9b7e79` and `d09e657`). **BETA-5** (scheduled-reply) was already fixed by
**NOV-89 / PR #51** (`b83c1db`) → skipped. The v1.2 release therefore carries the UA fixes by virtue of PR #41 already
being in `main`.

### Parked (nothing shipped broken; owner follow-ups)
- **U8 imported-username revert** — a legacy/imported handle violating the modern `alpha_dash/min:3/max:30` rule can't
  be reverted (fails validation). ADR-0106 flags it; needs a product decision on relaxing the rule for historical values.
- **U18 Turnstile fail-open** — the shipped Turnstile driver stays fail-**open** (its reasoned prior decision); the two
  new drivers are fail-**closed** per the kickoff. Whether to unify posture is an owner call (ADR-0107).
- **U20 out-of-scope (ADR-0108):** tag/club OpenGraph, a purpose-built 1200×630 social-card asset, JSON-LD beyond the
  existing topic block, and the `<title>`/PWA-manifest reading `config('app.name')` instead of the ACP `general.site_name`.

### New ADRs (0105–0108, lifted into DECISIONS.md; next-free was 0105 after 0104)
0105 permission-aware moderation UI + bulk-lock semantics (BETA-4, apex); 0106 username history + admin revert (U8);
0107 CAPTCHA driver completion fail-closed + opt-in Gravatar (U18); 0108 SEO polish + pending-topic fence (U20).

### Linear (team NovFora) — all moves succeeded
**→ Done:** NOV-85/86/87/88 (BETA-1..4), NOV-97/98/99 (U8/U18/U20), and the UA cluster NOV-77..NOV-84 (verified-shipped
via PR #41). NOV-89 (BETA-5) + NOV-76 (BUG-001) were already Done; NOV-106/115 (U7/U17) already Done. No blocked writes
this session.

### ☀️ What the owner does next
1. **Push (the one blocked git step):** `git push origin main` (fast-forwards `origin/main` `ab013c8` → `4ef4d24`) and
   `git push origin v1.2.0`. The harness auto-mode classifier denied the direct push to the protected default branch;
   nothing is wrong with the commits (tree clean, tag on HEAD, ff-safe). Add a Bash permission rule or push by hand.
2. **Go live (backup-first):** upgrade **demo.novfora.com** via the **cron auto-upgrade** path — this batch carries
   **new migrations** (`module_trust_keys`, `username_history`) + new capability keys, so it is NOT assets-only. The
   verified `novfora-release.zip` (gitignored at repo root; SHA in `scripts/verify-release.sh` output) is ready, or
   rebuild with `scripts/build-release.sh` on a Node host.
3. **Run Dusk in CI** (no Chrome on the VPS) — 3 new mobile-header specs are CI-pending.
4. **Decide the parked items** (U8 imported-username revert; U18 Turnstile posture) when convenient.

---

## 🌅 Morning report — FABLE session: Phase-0 clear-decks + U7 + U17 (2026-07-01) — 4 branches off `main`, NONE pushed/merged

Ran [`docs/product/FABLE-U7-U17-KICKOFF.md`](docs/product/FABLE-U7-U17-KICKOFF.md) end-to-end, unattended. Built the two
◆ APEX features (**U7** embeds, **U17** plugin install-from-zip) after clearing the Phase-0 decks. Each feature is an
independent branch; both **share a small prerequisite base** (see topology). Gated green in `forum-dev` at every
boundary; committed as `Tommy Huynh` (DCO `-s`, no AI trailers). **Nothing pushed, nothing merged** — the owner reviews.

### Branch topology (all off `main` `658508d`)
```
main 658508d
└─ claude/baseline-green 04e3ec6        3 commits: green-gate prerequisites (see below)
   └─ nov-120 bed6f96                    +1: the trapped BelongsToMany fix (NOV-120)
      ├─ nov-106 U7  b193699             +2: U7 embeds build + review fixes  (ADR-0103)
      └─ nov-115 U17 0d1eb7b             +1: U17 zip-install + trust gate    (ADR-0104)
```
Both feature branches contain `baseline-green` + `nov-120`, so they gate green standalone. **Suggested merge order:**
`baseline-green` → `nov-120` → `U7` → `U17`. Cross-branch overlap is trivial + additive: `DECISIONS.md` (U7 appends
ADR-0103, U17 appends ADR-0104 — both after 0102; keep both), `config/novfora.php` (U7 adds a top-level `embeds` block,
U17 adds a `zip` block inside `modules` — different regions), `.env.example` (both append after the oEmbed block). No
shared code file conflicts.

### Phase 0 — clear the decks
- **`claude/baseline-green` (`04e3ec6`)** — the working tree carried **21 pre-existing reds** unrelated to this work;
  fixed so the gate is trustworthy: **(1)** `forum-dev` image lacked **ext-gd** → 12 attachment specs LogicException'd
  (added GD to `docker/dev/Dockerfile`; hot-installed in the running container). **(2)** `NavigationManager::tree()`
  ran an **uncached `navigation_items` read per surface on every page** (+2 queries/page from PR #50) → 4 query budgets
  blew; cached the enabled rows (plain arrays, RH-9) with model-event busting, and re-baselined two topic budgets in
  the canonical `forum-dev` gate (prior ceilings were VPS-measured). **(3)** `MemberDirectoryTest` asserted 403 for an
  admins-without-2FA user where the designed route behavior is a **302 bounce to 2FA** (the hard 403 is the
  Livewire-internal guard, asserted separately). Full suite **2140 passed / 0 failed** at `04e3ec6`.
- **NOV-120 — REFUTED, not purged (`bed6f96`).** The 45 `⚡`-prefixed files under `components/admin/` are **NOT a dead
  shadow copy — they are the LIVE ACP** (Livewire 4 single-file components; each maps 1:1 to a `<livewire:admin.*>` tag
  in the wrapper views; there is no `app/Livewire/` class twin). Evidence posted to the issue. The "trapped"
  `BelongsToMany` type-hint fix was **already in the right file** (`⚡members.blade.php` IS the live members directory) —
  committed in place. The proposed non-ASCII-blade CI guard was **rejected** (it would fail every live Livewire SFC). The
  ⚡ files are load-bearing: without this fix, `MembersTableTest` TypeErrors, which is why both feature branches are based
  on `bed6f96`.
- **NOV-76 — already fixed on `main`.** `admin/section.blade.php` already carries the `@extends` envelope the issue
  prescribes (shipped by the ACP v3 program after the UI-audit spec was written); verified by `PerSectionRailTest` +
  `AcpSectionNavTest` (14 tests). No code change; the issue was simply never closed.

### Phase 1 — U7 · Embed API / SSI / web components (`NOV-106`, ◆ APEX, ADR-0103) — GO
Spike memo `docs/product/spike-u7-memo.md` (GO). Server-rendered iframe/SSI widgets (`/embed/v1/w/{widget}`) + versioned
JSON (`/embed/v1/d/{widget}.json`) + a dependency-free `<novfora-*>` web-component bundle (`public/embed/embed.js`), all
**guest-visible content only**, **OFF by default** (`embeds.enabled`). Stateless route group OUTSIDE `web` (no
session/cookies/CSRF), per-IP rate limiter, 60s fragment cache. Per-embed allowlist (`embed_sites`, audited
`EmbedManager`): a public rotatable site key bound to an exact origin; the HTML response owns its
`frame-ancestors <origin>` CSP and the JSON response grants CORS to exactly that origin (no `*`, no credentials, no
global CORS layer). No-leak fence = `User::guest()` + `VisibleForumIds` + club gates, **404 on every denial** (feature
off / bad key / unknown widget / invisible forum indistinguishable). ACP Plugins→Embeds SFC (admin.access + staff-2FA).
**Tests:** 25 (incl. adversarial: forged origins, oversized/malformed params, cross-origin JSON, private forum + private
club probes, rotated keys, escaping, statelessness, 429) + 2 WCAG surfaces. One additive reversible migration
(`embed_sites`).

**U7 adversarial review (verify-then-refute, 5 lenses × per-finding verify) — GO, 0 open HIGH/MEDIUM after fixes
(`b193699`).** One substantive finding (rated up to MEDIUM), fixed: the *board-wide* topics widget resolved the
guest-visible forum set INSIDE the cache closure and keyed on a constant, so a just-restricted forum's topic titles
served for the rest of the 60s TTL — inconsistent with the live-fenced scoped path. Fixed: resolve `VisibleForumIds`
live and fold a signature of the visible set into the cache key (regression test proves the drop happens within the TTL
without flushing the cache). Two LOW hardening items applied (`normalizeOrigin` strict host charset rejecting `;`/`,`/
control/raw-UTF-8 bytes; `embed` limiter IPv6 /64 bucketing). 5 candidates refuted (array-param 400 not 500;
control-char origins neutralized by `parse_url`; IDN/trailing-dot fail closed; web-component href is `route()`-generated;
the `Cache-Control: public` window caches only guest-public bytes). Full suite **2186 / 0**.

### Phase 2 — U17 · Plugin install-from-zip + signature/trust gate (`NOV-115`, ◆ APEX, ADR-0104) — GO
Spike memo `docs/product/spike-u17-memo.md` (GO). Closes the ADR-0031/H3 flag that a package signature + distribution
channel were "not built". Upload a signed module `.zip` in ACP → Plugins; installs **disabled** only if safe to extract
AND authentic. **`ArchiveGuard`** never `extractTo`s an untrusted zip — it iterates entries and writes each via a
bounded streamed copy to a path proven inside staging (traversal/absolute/backslash/null rejected; **symlink escape
structurally impossible** — only regular files written; entry-count/per-file/total/ratio caps enforced on the ACTUAL
streamed bytes so a lying header can't slip a bomb; extension allowlist). **`PackageSignature`** = detached **ed25519**
(bundled `ext-sodium`, constant-time) over the package's canonical digest, verified against the admin-managed trusted-key
registry (`module_trust_keys`); a present-but-invalid signature is ALWAYS rejected, unsigned rejected unless the loud dev
`allow_unsigned` policy, fail-closed if `ext-sodium` absent. **`ModuleInstaller`** = stage → validate manifest (slug from
`module.json`, never the filename) → verify sig → atomic rename into `modules/<slug>` → `ModuleManager::install/upgrade`;
any failure rolls back + quarantines the archive with an audited reason. ACP module-install SFC (admin.access +
staff-2FA); `novfora:module:sign` is the author recipe. **Tests:** 19 adversarial specs (traversal, absolute, symlink,
bomb, bad sig, untrusted key, too-many-entries, disallowed type, invalid/absent manifest, install-over-existing, ACP
authz + end-to-end signed upload). One additive reversible migration (`module_trust_keys`). Full suite **2159 / 0**.

**U17 adversarial review (verify-then-refute, 5 lenses × per-finding verify) — GO, 0 HIGH, 0 MEDIUM.** Every
security-class hypothesis was REFUTED against the code: no signature/trust bypass, no write-outside-staging, no bomb
bypass, no 2FA bypass. The 10 confirmed items were all LOW/robustness; the cheap ones were applied so the "reversible"
claim is honest: the upgrade swap is now fully inside the try (restore + `finally` backup cleanup, no orphaned dir);
`commit()` runs under a per-slug `Cache::lock` (closes the `is_dir` TOCTOU); `fwrite` drains the full buffer; a fresh
install resets inherited consent; a >1 MiB manifest is rejected before decode; the ACP banner reflects the real
post-upgrade enabled state; and the ratio-cap docblock is corrected (the byte caps are the load-bearing fence). Full
suite after fixes: **2159 / 0** (see below); 2 new regression tests (atomic upgrade + oversized manifest).

### Environment note (per MEMORY)
`claude-fable-5` errored in this env — the two APEX reviews ran on **Opus 4.8** (the apex fallback). GD was hot-installed
into the running `forum-dev` container AND added to `docker/dev/Dockerfile` (rebuild picks it up permanently).

### ☀️ What the owner does next
1. **Review + merge** in order `baseline-green → nov-120 → U7 → U17` (apply the trivial additive overlaps above). Push is
   the owner's — Code pushed/merged nothing; `main` is untouched at `658508d`.
2. **Linear:** the auto-mode classifier blocked some issue writes from this session. Still to set: **NOV-120 → Done** on
   merge (currently In Progress, refutation comment posted); **NOV-76 → Done** (already fixed; a close comment was
   blocked); **NOV-106 / NOV-115 → In Progress → Done** on merge.
3. **Run Dusk in CI** (no Chrome here) for the new ACP surfaces (Embeds, module-install).
4. **Enabling U7** is a deliberate act (`embeds.enabled` / `NOVFORA_EMBEDS`); **U17 keeps `allow_unsigned=false`** in
   production (dev-only override).

---

## 🌅 Morning report — v1.x Polish-2 + a11y + targeted fixes — EXECUTED, MERGED, PUSHED (2026-06-22)

Ran [`docs/product/v1x-polish2-and-fixes-kickoff.md`](docs/product/v1x-polish2-and-fixes-kickoff.md) end-to-end,
unattended: built **Track F** (F1·F3·F5·F6·F4·F2) then **Track P** (P1·P2) — each an independent branch off
`main`, verified against `main` first, gated green at every boundary, committed as `Tommy Huynh` (DCO `-s`, no
AI trailers) — then **merged in the kickoff order, re-gated the union, lifted the F2 ADR, and pushed `origin
main`**. The release-zip **deploy is the owner's only remaining step** (below).

**Merged `main` HEAD: `37c9cb8`** (this report sits on top). 8 `--no-ff` merges in dependency order
(`F1·F3·F5·F6·F4·F2 → P1·P2`) + 3 integration commits (union test reconcile, asset rebuild, ADR-0101 lift).
Whole batch vs pre-merge `main` (`backup/pre-polish2` = `6b8a011`): **42 files, +1181 / −175**.

**Union gate (merged `main`, all GREEN):**
- `php artisan test --parallel` → **2128 passed / 1 skipped** (the Dusk CI-only spec) · **14 971 assertions**
- `pint --test` clean · `phpstan` **L5 → 0** (app/) · migrate **apply + rollback(all 91) + re-apply** clean
  (1 new reversible migration: `users.trust_locked`)
- `npm run build` OK → assets committed (`e2e470d`); ViteManifest / asset-budget gate green (app.css ≈ 61.5 KB,
  gz ≈ 11.6 KB; JS unchanged)
- **a11y page gate grown 27 → 30** surfaces (mod CP, per-member view, ACP analytics) — green; `route:clear` first
- **Dusk = CI-only** (no Chrome on the VPS) — specs unchanged, CI-pending

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| F1 remove ACP recents | `claude/fix-acp-recents` | `895d0f1` | Pint✓ AcpPolish 5✓ a11y 29✓ Admin 281✓ | view+lang+test only; no DB/route/JS backed it |
| F3 mod-CP width | `claude/fix-mod-width` | `3c24430` | Pint✓ ModTools 8✓ a11y 29✓ | dashboard/queue/reports `md→lg`; confirm-delete stays narrow |
| F5 Info Center | `claude/fix-infocenter` | `b4a599c` | Pint✓ PHPStan0 InfoCenter 15✓ perf✓ a11y✓ | collapsible (no-flash localStorage) + coloured who's-online; `recent()`→Eloquent coll + eager groups |
| F6 latest-activity ⚠perf | `claude/fix-latest-activity` | `ae7db33` | Pint✓ PHPStan0 HotPath✓ BoardTable✓ | last topic+author, bounded +3 const queries; HotPathQueryTest holds <25 |
| F4 report-review ⚠apex-adj | `claude/fix-report-review` | `634c4b1` | Pint✓ PHPStan0 ReportReview 4✓ leak✓ | post excerpt + permalink + gated mod actions; no private-club leak; Message reports stay bare |
| F2 trust/rep ⚠APEX | `claude/fix-manual-trust-rep` | `424cc6d` | Pint✓ PHPStan0 apex 6✓ trust/rep/members 187✓ migrate✓ | **review GO, 0 HIGH** (see below) |
| P1 design-critique | `claude/polish2-critique` | `a30f7d8` | Pint✓ ACP 281✓ Analytics+Mail 63✓ a11y✓ | email-editor dark-mode tokens; action-weighting; empty states |
| P2 a11y to AA | `claude/polish2-a11y` | `3838acb` | Pint✓ a11y 30✓ Admin 281✓ | gate 27→30; fixed the ACP-shell double-`main` (1.3.1) + 2.4.7 focus rings |

### F2 apex review (mandatory verify-then-refute, 15 agents) — **GO, 0 open HIGH**
The review confirmed **1 MEDIUM + 3 LOW** (3 candidates refuted), all **fixed before the F2 merge and
re-verified** (`424cc6d`):
- **MEDIUM** — the trust-group swap was non-transactional → a manual set racing the cron (or a 2nd admin) could
  transiently leave a member in TWO trust groups (resolver = permission UNION). Fixed with a shared
  `swapTrustGroup()` wrapping detach+attach+flush+denorm in a `DB::transaction` + `lockForUpdate` (closes the
  inherited `setLevel()` race too); a test asserts exactly one trust group after a set.
- **LOW ×3** — reputation audit `from→to` now read under the lock (truthful under concurrency; denorm+ledger were
  already exact); trust audit `actor_id` from the explicit `$actor`; `trustReason` server-side `max:500` cap.

### Conflict resolutions
**All merges auto-resolved — no manual hand-merge needed.** Git's `ort` strategy handled the one anticipated
overlap (**F2 × P1** on the per-member view `⚡manage.blade.php`): F2's new trust/reputation cards + P1's
action-weighting tweaks (lift-ban → neutral, warn → `danger-soft`) both landed (verified: 6 F2 markers + 2 P1
markers present). F5/F6/F4/P2 touched disjoint files. **One union-integration test fix** (`5abe586`): F6's
latest-activity column legitimately surfaces the LIVE last-post author on the index, so the activity-feed
index test's over-broad `assertDontSee('OrigPoster')` was dropped — the feed's no-leak is proven in isolation
by its sibling test ([Deleted] tombstone, `actor` null).

### New ADR
**ADR-0101** (F2 manual trust + reputation editing, apex) lifted into `DECISIONS.md` (`37c9cb8`); next-free was
0101 as expected (0095–0100 taken).

### ☀️ What the owner does next
1. **Pushed:** `origin main` was fast-forwarded to `37c9cb8`+ (this report). Nothing held back — F2 had 0 open HIGH.
2. **Go live (the only remaining step):** build the portable release per
   [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md) (`scripts/build-release.sh` →
   `scripts/verify-release.sh`) and upgrade **demo.novfora.com backup-first**. This batch carries a **new
   migration** (`users.trust_locked`) + the F2 trust/reputation capability keys + audit actions, so it's the
   **cron auto-upgrade** path, not assets-only.
3. **Run Dusk in CI** (no Chrome on the VPS) — the existing specs cover the editor/journey paths; the new F2/F4/F5
   interactions are server-render + a11y-gated here.

---

## 🟢 v1.x batch + S5 owner-strand guard — MERGED to local `main`, gated GREEN, awaiting owner push (2026-06-22)

Ran [`docs/product/v1x-merge-and-owner-guard-kickoff.md`](docs/product/v1x-merge-and-owner-guard-kickoff.md): built the
apex **S5 owner-strand guard**, then merged the **full v1.x batch + S5** into `main` and re-gated the union. **Local
only — nothing pushed to `origin` yet.** Backup of pre-merge `main` at **`backup/pre-v1x` (`6862775`)**.

**Merged `main` HEAD: `4b4e64b`** (this report sits on top). 15 `--no-ff` merges in the kickoff's dependency order
(`R1→S3→R2 ; A1→A2→A3→S5 ; S1 ; S2 ; M1→M2→M3 ; T1→T3→T2`) + asset rebuild (`2413152`) + ADR lift (`4b4e64b`).
Whole batch vs pre-merge `main` (`8677b4b`): **120 files, +5828 / −1344**.

**Union gate (merged `main`, all GREEN):**
- `php artisan test --parallel` → **2111 passed / 1 skipped** (the Dusk CI-only spec) · **14 880 assertions**
- `pint --test` clean · `phpstan` **L max → 0** (app/) · migrate **apply + rollback(all) + re-apply** clean (2 new
  reversible migrations: `content_subscriptions`, `canned_replies`)
- `npm run build` OK → **assets committed** (`2413152`); ViteManifest / asset-budget gate green
- a11y page gate + Accessibility + PWA + SubdirInstall gates green (67) — `route:clear` first (the stale
  `routes-v7.php` subdir/PWA false-fail per MEMORY)
- **Dusk = CI-only** (no Chrome on the VPS) — specs present, CI-pending (unchanged from prior batches)

**Conflict resolutions (all additive — kept both sides unless noted):**
1. **R2** `design-polish-adrs-DRAFT.md` modify/delete → **deleted** (honoured R2's prune; the draft's ADRs are now
   lifted as 0093/0094). DECISIONS.md auto-merged with **no duplicate** 0093/0094.
2. **A3** `AdminAccessWalkTest` sentinel → kept A1 `/admin/members/all` **+** A3 `/admin/moderation/warning-types`.
3. **S5 × A2** `BanController` → routed user bans through A2's shared **`UserBanService`** **and** moved S5's locking
   owner-strand guard **into `UserBanService::ban()`** (the chokepoint both the bans page and the ACP member view
   share); BanController catches `OwnerStrandException`; S5's `spamClean` catch kept.
4. **T1 × M1/A3** reply-composer SFC (merged M1 quote + T1 canned: imports, `mount(?quote,?canned)`, both method
   sets); `topic.blade` (one `<livewire:forum.reply-composer>` with both `?quote` + `?canned`); AdminNavigation /
   `lang/en/admin` / `routes/web` (kept A3 warning-types **+** T1 canned-replies); sentinel got T1's line (all three).

**S5 apex review (mandatory verify-then-refute) — outcome:** the first pass confirmed **3 HIGH** the green suite
missed; **all fixed before commit and re-verified GO (0 open HIGH):**
- **H1** the predicate counted `group_user` rows, blind to a ban (which touches only `users`/`bans`) → counted
  already-banned owners as live (sequential *and* concurrent strand). **Fix:** count REACHABLE owners (exclude
  `status='banned'` OR a live global user-ban row).
- **H2** the sibling DELETE (`AccountDeletionService`) / DEMOTE (`AdminCoOwnerService`) guards shared the blind count
  (ban a co-owner, then remove the healthy peer). **Fix:** those guards now **delegate** to the shared ban-aware
  authority.
- **H3** `(int) is_co_owner === 1` mis-read PostgreSQL's `"t"`/`"f"` → co-owner protection silently off on a
  supported tier. **Fix:** SQL-side `where is_co_owner = true`, like the siblings.
Re-verification (4 lenses + adjudicator): **GO**, no new HIGH/MED; uniform `group_user → users → bans` lock order,
no deadlock.

**ADRs lifted into DECISIONS.md (0095–0100):** 0095 v1.x Feature Program (parent); 0096 ACP v4 member management
(apex); 0097 subscriptions — bounded/queued fan-out (apex); 0098 canned replies; 0099 admin email templates —
render-sandbox (apex); 0100 owner-strand ban/warn guard (apex).

**Flagged (pre-existing, NOT fixed — owner follow-ups, out of S5's ban scope):** `GroupManager::removeMember` guards
the co-owner tier but **not** the last PLAIN admin (an ADR-0086 removal-door gap); the now-unused
`AccountDeletionService::coOwnerIds(locked: true)` branch (LOW cosmetic).

**→ What the owner does next:** (1) `git push origin main` (fast-forwards `origin/main` from `6862775` → `4b4e64b`+).
(2) **Go live:** build the portable release per [`docs/product/live-deploy-kickoff.md`](docs/product/live-deploy-kickoff.md)
(`scripts/build-release.sh` → `scripts/verify-release.sh`) and upgrade **demo.novfora.com backup-first** — this bundle
carries **new migrations** (subscriptions, canned replies + the S5 guard), so it's the **cron auto-upgrade** path, not
assets-only.

---

## 🌅 Morning report — v1.x Feature Program EXECUTED (2026-06-22) — 14 branches off `main`, none pushed/merged

Ran [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) cold, unattended,
in order **R → S → A → M → T**. Each slice is an **independent branch off `main`** (two intentional stacks, noted
below), committed only at a fully-green gate boundary (`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing
pushed, nothing merged** — all 14 branches are local for owner review; `main` is untouched at `3b70653`. Gates run
on the **VPS native toolchain** (PHP 8.3 + MySQL; sqlite `:memory:` for the suite, isolated-sqlite for the migrate
gate). **Dusk could not run here** (no CI Chrome) — Dusk specs are written/extended + CI-pending.

| Slice | Branch | Head | Gate | Notes |
|---|---|---|---|---|
| R1 doc-trim | `claude/v1x-r1-doc-trim` | `be89224` | links✓ Pint✓ | PROJECT-STATE 1181→148 lines; milestone blocks → PROJECT-HISTORY |
| S3 lift ADRs | `claude/v1x-s3-adrs` | `27f7a50` | docs✓ | ADR-0093/0094 lifted into DECISIONS.md (detail blocks; index table is unmaintained past 0035) |
| R2 prune | `claude/v1x-r2-prune` | `a638a69` | suite 2005✓ migrate✓ links✓ | **stacked on R1+S3**; archived 45 kickoffs + index, deleted lifted draft, import-seed already gone |
| S1 attach harden | `claude/v1x-s1-attach-hardening` | `ddcb136` | suite 2007✓ Pint✓ PHPStan0 | prune reserves scheduled/draft refs (the ADR-0094 LOW) + storage-outage graceful-degrade |
| S2 Dusk CI | `claude/v1x-s2-dusk-ci` | `4730ad4` | php -l✓ Pint✓ | attach-zone + M0 no-scroll-trap specs added; **CI-pending (no Chrome here)** |
| S4 groups icons | — (none) | — | verify | **already merged to `main`** (`75d463a`/`8677b4b`) → gate-and-skip |
| A1 member table ⚠apex | `claude/v1x-a1-member-table` | `32d20c8` | 18✓ walk✓ suite 2022✓ | **review GO** — 1 MEDIUM (hidden-group leak) fixed in-session |
| A2 per-member view ⚠apex | `claude/v1x-a2-member-admin` | `7b6d2f6` | 13✓ suite 2038✓ | **stacked on A1**; **review GO** — fixed liftBan rank gap + added last-owner guard |
| A3 warnings ACP | `claude/v1x-a3-warnings-acp` | `a610c6b` | 8✓ walk✓ suite 2015✓ | warning-type CRUD + read-only thresholds |
| M1 quote-reply | `claude/v1x-m1-quote-reply` | `a51a02f` | 9✓ suite 2011✓ | blockquote+attribution+parent linkage; no JS rebuild |
| M2 subscriptions ⚠apex | `claude/v1x-m2-subscriptions` | `a62e283` | 9✓ migrate✓ suite 2016✓ | **review GO** — fail-closed hardening on the bounded+queued fan-out |
| M3 unread/excerpt/slug | `claude/v1x-m3-list-finish` | `2857bbd` | 6✓ HotPath✓ suite 2013✓ | excerpt + slug 301 (unread/dropdown already shipped → skipped) |
| T1 canned replies | `claude/v1x-t1-canned-replies` | `d18e9f8` | 10✓ walk✓ migrate✓ suite 2017✓ | ACP CRUD + composer ?canned insert (no JS rebuild) |
| T3 analytics charts | `claude/v1x-t3-analytics-charts` | `40f9506` | 5✓ suite 2012✓ | hand-authored inline-SVG sparklines; no asset rebuild |
| T2 email templates ⚠apex | `claude/v1x-t2-email-templates` | `517cd41` | 8✓ suite 2015✓ | **review GO** — reuses the SiteTemplate sandbox; no confirmed finding |

### Apex adversarial reviews (mandated; verify-then-refute, ~6 agents each) — all GO, no unresolved HIGH
- **A1** (member directory): 4 vectors refuted (authz, email-PII, SQL/orderBy, DoS); **1 MEDIUM fixed in-session** —
  the group filter leaked hidden-group names → gated behind the same `users.manage` ceiling as email (+ regression test).
- **A2** (per-member view): 5 vectors reviewed; **NEW MEDIUM fixed** (liftBan lacked the rank guard) + the kickoff's
  **last-owner guard added** (can't ban/warn the sole admin/co-owner). **FLAGGED, pre-existing (NOT new to A2):** the
  FRONT-END ban/warn paths (`BanController` + `WarningService` auto-ban consequence) still lack the last-owner guard —
  the strand is reachable there independently; fix belongs in `UserBanService::ban` + `WarningService` (mirror
  `AccountDeletionService`'s locked sole-admin/co-owner guards). **Engine fast-follow.**
- **M2** (subscription fan-out): 5 vectors; the soft-deleted-forum HIGH was **refuted as structurally unreachable**
  (`StructureService::delete` reparents all topics to a live board first) — applied the **fail-closed hardening anyway**
  (job returns when the forum can't be loaded) + a regression test.
- **T2** (email render-injection): 5 vectors, **no confirmed finding** — the SiteTemplate sandbox (escape + char-allowlist
  + helper registry + skeleton lint) holds; subject is code-controlled + CRLF-stripped. Non-blocking nit: the global
  context also merges `site.description`/`user.*`/`stats.*` (escaped, harmless) — left undeclared as not email-meaningful.

### ⚠ Environment finding (corrects the prior "2 inherited reds")
The `SubdirInstall` + `Pwa` subpath test failures were a **stale route-cache artifact** (`bootstrap/cache/routes-v7.php`),
not a code bug: `php artisan route:clear` makes the suite fully green (2008+ pass / **0 fail** / 1 skip). **The CI/gate
must `route:clear` (or not ship a cached route table) before testing.** Every per-slice suite count above is post-clear.

### ADRs (PROPOSED — referenced in commits, NOT lifted beyond S3's 0093/0094)
**0095** v1.x Feature Program (parent) · **0096** ACP v4 member-management (A1/A2/A3) · **0097** subscriptions (M2) ·
**0098** canned replies (T1) · **0099** email templates (T2). Lift into `DECISIONS.md` on ratification (S3 already lifted
0093/0094 as detail blocks — the index table stopped at 0035, so recent ADRs are detail-block-only).

### Cross-branch merge notes (all additive; hand-merge where flagged)
- **R-track stacking:** R2 contains R1+S3 (the kickoff makes R2 depend on S3's lift; R1's history move is needed for link
  integrity). Merge R1, S3, then R2 — or just R2 (it carries all three).
- **A-track:** A2 is stacked on A1 (A2 "rides A1's row" — the Manage link is live end-to-end). Merge A1 then A2; A3 off `main`.
- **`reply-composer` / `topic.blade.php`:** M1 (?quote), T1 (?canned), M2 (subscribe button) each extend the composer mount
  + the topic embed → a small hand-merge (all additive; keep all params + one `#reply-composer` anchor).
- **`AdminAccessWalkTest` sentinel:** A1, A3, T1 each add a `toContain('/admin/...')` after the co-owners line → trivial both-add.
- **`AdminNavigation` / `lang/en/admin.php` / `routes/web.php`:** A1/A3/T1 add adjacent nav items + lang keys + routes → adjacent, conflict-free or trivial.

### ☀️ What the owner does next
1. **Review + merge** the 14 branches (`git diff main..<branch>`). Suggested order: **R1 → S3 → R2** (hygiene), then
   **A1 → A2 → A3**, **M1 → M2 → M3**, **T1 → T3 → T2** — apply the cross-branch merge notes above.
2. **Lift ADRs 0095–0099** into `DECISIONS.md` on ratification (S3's 0093/0094 are already in).
3. **Run Dusk in CI** (S2's attach-zone + scroll-trap specs + the slug-tolerant moderation path; M1/M2 interaction).
4. **Make the gate `route:clear`** (the env finding above) so the subdir/PWA cases pass in CI.
5. **Engine fast-follow (flagged HIGH-class, pre-existing):** add the sole-admin/co-owner guard to the BAN/WARN engine
   (`UserBanService::ban` + `WarningService::applyConsequence`) so the front-end mod CP can't strand the owner tier either.
6. **Push/merge are the owner's** — Code pushed/merged nothing; `main` is untouched.

---

## ▶ ACTIVE TASK (EXECUTED 2026-06-22 — see the morning report above) — v1.x Feature Program

Executed [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) end-to-end in
order (**R → S → A → M → T**). Independent branch per slice off `main`, gated, committed at a green boundary,
**nothing pushed/merged** (owner reviews). Verified each slice against current `main` first and preferred the codebase.
Apex slices (A1/A2 member data + ban/warn, M2 subscription fan-out, T2 email-template render) each got an in-session
adversarial verify-then-refute review — all GO (no unresolved HIGH).

> Provenance: `docs/product/audit-ips-gap-analysis-2026-06-22.md` (gap map + code anchors) +
> `docs/product/archive/design-polish-kickoff.md` (which deferred these as functional). ADR allocation: parent **0095**
> (v1.x Feature Program); children **0096** ACP v4 member-management (A1/A2/A3), **0097** subscriptions (M2),
> **0098** canned replies (T1), **0099** email templates (T2). Confirm next-free before lifting.

---

## 🌙 Unattended session 2026-06-22 — Design-Polish Program (EXECUTED: 6 branches off `main`; none pushed/merged)

**Executed cold** by an unattended Code run from `docs/product/archive/design-polish-kickoff.md` (order **0 → 1 → 3 → 4 → 5 → 2**,
the apex last). Each slice is an **independent branch off `main`**, committed only at a fully-green gate boundary
(`Tommy Huynh` identity, DCO `-s`, no AI trailers). **Nothing pushed, nothing merged** — all six branches are local
for owner review. Gates run on the **VPS native toolchain** (PHP 8.3 + MySQL; no Docker). **Dusk could not run here**
(no CI Chrome — per the standing note); Dusk specs were written/extended and are **CI-pending**.

| # | Slice | Branch | Head | Gates (local) |
|---|---|---|---|---|
| 0 | M0 scroll-trap hotfix | `claude/polish-m0-prose-scope` | `cc6e8e5` | Pint ✓ · Pest 1959 ✓ · drift ✓ · Dusk contract preserved (CI) |
| 1 | Design-system foundation (Pillar 1) | `claude/polish-p1-design-system` | `4741d36` | Pint ✓ · PHPStan 0 ✓ · Pest 1974 ✓ · drift ✓ |
| 3 | Editor toolbar + schema (Pillar 4) | `claude/polish-p4-toolbar` | `0eb5b53` | Pint ✓ · PHPStan 0 ✓ · Pest 1973 ✓ · drift ✓ · Dusk extended (CI) |
| 4 | ACP navigability + polish (Pillar 2) | `claude/polish-p2-acp` | `bd8bf45` | Pint ✓ · PHPStan 0 ✓ · Pest 1964 ✓ · drift ✓ |
| 5 | Member-experience polish (Pillar 3) | `claude/polish-p3-member` | `7865f91` | Pint ✓ · PHPStan 0 ✓ · Pest 1963 ✓ · drift ✓ · Dusk (CI) |
| 2 | Editor attachments — **APEX** | `claude/polish-p4-attachments` | `6d7f636` | Pint ✓ · PHPStan 0 ✓ · Pest 1969 ✓ · drift ✓ · no migration · **adversarial review on record** |

All six end **runnable + green on the Baseline tier**; all gz asset budgets hold (editor island ≈131 KB, CSS ≈10.4 KB).

### Slice-2 apex review (mandated per-finding verify-then-refute — 11 vectors, independent refutation)
**0 HIGH ⇒ no halt.** The pre-existing backend was already serve-gate-hardened (P5.1 IDOR/club/trashed/orphan gate,
MIME allowlist, off-webroot random-name storage, nosniff); this slice closed the remaining gaps and the review
probed MIME/extension confusion, SVG/script, path-traversal, IDOR, private-club leak, unauth, rate-limit bypass,
association hijack, and re-encode bypass — **all refuted**. Two residuals:
- **1 MEDIUM — FIXED in-session + regression-tested.** The decompression-bomb fence was **per-side only**, so a
  square bomb (11999×11999 ≈144 MP, 446 KB → ~549 MB GD alloc *outside* `memory_limit`) slipped it → worker OOM.
  Fixed with a pre-decode **total-pixel** budget (`max_source_pixels`, ≈25 MP); test rejects a square that passes
  the per-side fence.
- **1 LOW — owner follow-up (NOT fixed; out of apex scope).** The orphan-prune cron can delete a **scheduled**
  reply's attachment, because the scheduled-post subsystem (`PostScheduler`/`ScheduledPost`) doesn't reserve
  attachments — so a reply scheduled beyond `orphan_prune_hours` loses its image at publish. **→ closed by v1.x
  S1.**

### ADRs (PROPOSED — lifted by v1.x S3 into `DECISIONS.md` as 0093/0094)
**ADR-0093 = Design-Polish Program** (parent) · **ADR-0094 = the attachment subsystem** (apex). The draft
`docs/product/design-polish-adrs-DRAFT.md` (numbered 0092/0093) is corrected on lift and then deleted by v1.x R2.

### Cross-branch overlaps to expect on merge (independent branches, all additive)
Slices **0/2/3** touch the `app.css` editor region; Slices **2/3** both touch `novfora-editor.js`, `island.js`,
and `content-editor.blade.php` (toolbar vs file-node/attach-zone — will conflict, need a hand-merge). Slices
**1/4/5** touch different `app.css` regions.

### What the owner does next
1. **Review each branch** (`git log`/`git diff main..<branch>`); independent — **merge in any order**. Slice 0/1
   are the safest first merges; Slice 1 unblocks the `x-ui.*` reuse.
2. **Expect a hand-merge between Slices 2 and 3** (shared editor files).
3. **Lift ADR-0093/0094** into `DECISIONS.md` — **done by v1.x S3** (renumbered from the draft's 0092/0093).
4. **Run Dusk in CI** for Slices 0/2/3/5 (editor journey + attach interaction + roving-tabindex).

---
## 🚀 Phase 5 — HARDENING → GA on `claude/phase-5-ga` — 2026-06-16 (MERGED → `main`, PR #30)

**Unattended, owner-authorized GA-readiness run off `main` (Phase 4 fully merged: ADR-0060 + ADR-0069 present).
17 conventional, DCO-signed, `Tommy Huynh`-authored commits on `claude/phase-5-ga`. NOTHING IS PUSHED** — push
is interactive-only in the sandbox; the owner pushes + opens the PR. No new product features (hardening/polish/
docs/tests only). Every ADR (0072–0076) is **"Accepted — owner-authorized GA run; flagged for review."**

**Model-routing note (recorded, ADR-0072):** CLAUDE.md routes security work to **Fable @ max**, but
`claude-fable-5` was **unavailable** in this build env, so the apex rung was taken at **Opus 4.8 (1M)** — a
conservative, security-preserving fallback (a stronger model finds only more).

**Final gate (branch HEAD, in `forum-dev`):** `php artisan test --parallel` **1560 passed / 1 skipped / 0
failed** (12779 assertions; baseline 1525 → **+35** Phase-5 tests) · PHPStan (level 5) **0 errors** · Pint clean
(813 files) · `migrate` clean. Every unit committed only at a green boundary.

### Per-unit status (ADR)
- **P5.1 security ✅ (ADR-0072)** — 2nd adversarial verify-then-refute over the whole Phase 3/4 surface (11
  domain reviewers + per-finding refuter panels). **No HIGH.** 8 MEDIUM + 3 LOW + 2 INFO fixed (each + test); 6
  refuted. Full writeup: `docs/architecture/security-review-phase5.md`.
- **P5.2 WCAG 2.1 AA ✅ (ADR-0044)** — automated page gate grown **14 → 27 surfaces** (clubs/PMs/memberships/
  notifications/preferences/trending/whats-new/saved/tags/home/leaderboard); 3 accessible-name failures fixed.
  Manual residue recorded in `docs/architecture/accessibility.md`.
- **P5.3 i18n ✅ (ADR-0073, extends ADR-0043)** — framework/RTL/switch/fallback (already shipped + tested)
  completed with a complete **`es` proof locale**, the **auth + error** surfaces externalised
  (`lang/en/{auth,errors}.php`), and a per-key `en`-fallback test. Coverage + residue below.
- **P5.4 perf ✅ (ADR-0074, extends ADR-0045)** — `HotPathQueryTest` proves the hot paths are **N+1-free** in
  steady state; baseline + enhanced-tier procedure/SLOs documented in `docs/architecture/load-testing.md`.
- **P5.5 release ✅ (ADR-0075)** — the `nevo→novfora` rename **completed** (command prefix, editor JS island +
  rebuilt assets, dev/CI infra names) + **enforced by a CI brand gate**; version → **1.0.0**; new `CHANGELOG.md`
  + `docs/product/release-checklist-1.0.md`; removed a stray committed `.env.root-stale`.
- **P5.6 fresh-install ✅ (ADR-0076)** — `FreshInstallSmokeTest` drives the redeploy path on an EMPTY DB green
  (schema + seeded roles/permissions/system groups + a capable first admin + lock); `build-release.sh` produces
  a clean `novfora-release.zip` and the cold artifact boots **`GET / → 302 /install`** (both verified directly).

### 🔐 SECURITY findings (P5.1 — all HIGH/MEDIUM fixed; full table in `docs/architecture/security-review-phase5.md`)
| Sev | Finding | Fix |
|---|---|---|
| Med | Search forum-facet leaked private-club names to logged-in non-members | facet now applies `clubContentVisibleTo` |
| Med | SSO (OAuth/SAML) skipped mandatory **staff 2FA** | `ChallengesStaffTwoFactor` defers staff to Fortify's TOTP challenge |
| Med | OAuth signup bypassed registration toggle + anti-spam + **email/IP bans** | `resolveForLogin` mirrors `CreateNewUser` (refuse / flag→pending) |
| Med | REST `createPost` ignored the **locked-topic** gate | shared `Topic::isReplyable()` |
| Med | Installer **DB-test SSRF** bypassed the setup token | re-assert the token at the sink |
| Med | Stripe webhook granted without **`payment_status`** proof | require paid/no-payment-required |
| Med | **Unbounded `@mention` fan-out** (mass-notify + DoS) | cap at `antispam.mention_fanout_cap` (10) |
| Med | Importer **legacy-attachment path traversal** | reject `..`/scheme at the read site |
| Low | Stripe webhook idempotency had no DB UNIQUE | `UNIQUE(provider, provider_ref)` + violation catch |
| Low | `/api/v1` ran without install/upgrade maintenance gates | applied ahead of token auth |
| Low | Attachment on a soft-deleted post still downloadable | mirror the trashed gate (uploader/moderator only) |
| Info | manifest reserved-namespace case-sensitive; OAuth profile strings verbatim | case-insensitive guard; clamp + strip control/bidi |
**Refuted (recorded):** sole-owner club orphan (data-integrity, an ADR-0047 fast-follow, not security) · API
trust-rate-limiter (bounded by throttle:api + the pipeline) · `acl_entries` no-UNIQUE (resolver dup-tolerant) ·
2FA-mutation password re-confirm (documented Phase-2 deferral) · OAuth IP-only throttle · sandbox quoted-URL
scheme (admin-trust-gated).

### ♿ Residual MANUAL a11y items (not machine-verifiable — owner/QA before go-live)
Contrast (1.4.3, incl. admin-set custom theme tokens) · keyboard nav + no focus traps (2.1.1/2.1.2) · visible
focus (2.4.7) · reduced-motion (2.3.1) · live-region status messages (4.1.3) · a screen-reader pass + the RTL
visual pass on the newly-covered clubs/PMs/memberships flows. (`docs/architecture/accessibility.md`.)

### 🌐 i18n coverage (P5.3)
**Externalised + `en`-complete + `es`-translated:** the framework strings, `common`, `search`/saved-search,
**all auth screens**, **all error pages**. Framework (allowlist, `SetLocale` precedence, validated switch, RTL
`<html dir>`, per-key `en` fallback) is shipped + tested (LocalizationTest, 11). **Residual (documented,
mechanical, community-contributable — NOT a 100% sweep):** the authenticated front-end (`forum/clubs/pm/
profiles/settings/members/…`, the ~92 `components/`) + the staff `admin/` ACP stay on literal English; partial
externalisation + partial locales are always correct (literal English shows; missing keys fall back to `en`).

### ⚡ Baseline load results + enhanced procedure (P5.4)
**Baseline query-shape (sqlite gate; engine-independent):** board index **13 warm**, forum listing/topic/
search/clubs all **< 40–45** — **no steady-state N+1**; hot-path columns indexed. The board index's cold build
(~69) is the 60s fragment-cache build, amortised. **Enhanced tier NOT run against a real host** — procedure +
suggested SLOs (baseline reads p95 < 600ms / search < 1.5s; enhanced reads < 250ms / search < 300ms) + capacity
guidance + the at-scale `EXPLAIN` step are in `docs/architecture/load-testing.md`.

### ✅ VALIDATE-BEFORE-GO-LIVE (consolidated — carried from Phase 4 + new)
Scaffolded/disabled-by-default; unit-tested against fakes only. Enable + validate per the named ADR /
`docs/product/release-checklist-1.0.md`:
1. **Meilisearch** (ADR-0060) — index + confirm no private-club leak.
2. **Reverb realtime** (ADR-0061/0062) — websocket round-trip + the channel-authz no-leak.
3. **Live Stripe** (ADR-0065 + P5.1) — real keys/webhook; grant only on `payment_status=paid`; add `invoice.*`/
   cancellation before auto-renewal.
4. **OAuth / SAML** (ADR-0053–0056) — real apps; the no-merge rule + the **staff-2FA step-up** (P5.1) end to end.
5. **Web Push** (ADR-0058) — VAPID; live push-service round-trip.
6. **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in.
7. **Load test at scale** (ADR-0045/0074) — k6/artillery on the real baseline + enhanced host; capture p50/p95/
   p99 vs the SLOs; `EXPLAIN` the forum-listing sort.
8. **Manual a11y** (ADR-0044) — the residual checklist above.
9. **`verify-release.sh`** — runs clean in a normal container/CI (its checks were verified directly here; the
   script doesn't cleanly *return* under `docker exec` because the backgrounded `php -S` isn't reaped — env, not
   a defect).

#### Enhanced-tier live validation — 2026-06-19 (build VPS, against live backends)
Ran `docs/product/archive/enhanced-tier-validation-kickoff.md`. First time the scaffolded Enhanced tier was exercised
against real backends (everything was unit-tested against fakes only). **Items 1–2 above are now PROVEN; the
Redis cache/queue path too.**
- **Prereqs discovered + fixed (the box was only half-bootstrapped):** the app was **not installed** (no
  `storage/installed` marker) and the DB was **migrated-but-empty**, so `AppServiceProvider::prepareForInstaller`
  force-hardened cache/session/queue to file/file/sync — masking the Enhanced `.env` entirely. Ran
  `php artisan novfora:install --demo` (preserves the Enhanced `.env`; APP_ENV→production; admin
  `admin@novfora.test`). Two **Enhanced client libs were missing from composer** (scaffolded but never added,
  since tests fake them) → added on `chore/enable-reverb`: `meilisearch/meilisearch-php`, `laravel/reverb` +
  `pusher/pusher-php-server`.
- **Redis (cache/session/queue):** cache round-trips via Redis (DB 1, key `novfora-database-novfora-cache-vt`);
  a real `RegenerateUserPostHtml` job was drained by the `novfora-queue` worker in ~0.25 s (effect applied);
  `queue:failed` = 0; `ServiceTierFallbackTest` green.
- **Meilisearch (#1):** 14 approved posts indexed (`numberOfDocuments:14`); live keyword search served by the
  engine (proved via typo-tolerance a DB `LIKE` can't do); **private-club no-leak HELD** over the live index —
  a non-member got 0 for a term that IS in the index, the member got 1 (the index is never the sole gate;
  `SearchService` re-gates via `clubContentVisibleTo`); on a dead engine `search()`/`posts()` degrade to the DB
  with no error and the no-leak still holds. *(First validated against a dev-run `meilisearch` instance; then
  re-verified over the now-fixed system `meilisearch.service` — see findings.)*
- **Reverb (#2):** authorized subscriber on `private-thread.{id}` received the **id-only** payload
  `{post_id,topic_id,user_id}` over a live socket (no body crosses the wire); an unauthorized subscriber was
  **rejected 403 at `/broadcasting/auth`** (`ChannelAuthorizer::canViewThread`). Enablement committed on
  `chore/enable-reverb`; gates green *(1746/1748 Pest — the 1 failure is the pre-existing v3-e
  `HotPathQueryTest` query-budget, 42 vs 41, unrelated to these deps which are inert under the test env's
  sqlite/`scout=database`/`broadcast=null`; PHPStan 0, Pint clean)*.
- **Findings / follow-ups (NOT blockers for #1–#2 correctness, but for box hygiene):**
  - `scout.queue=false` → with the `meilisearch` driver a Meili **outage makes searchable writes throw inline**
    (post creation breaks). Recommend `SCOUT_QUEUE=true` on Enhanced so a transient engine outage degrades
    gracefully on writes too.
  - System `meilisearch.service` **crashed → FIXED**. Root cause was NOT a version mismatch (the DB `VERSION`
    matched binary 1.47.0): the unit had **no `WorkingDirectory`**, so CWD defaulted to `/`, which the
    `meilisearch` user (uid 999) cannot write — Meili exits `Permission denied (os error 13)` at startup. Added
    `WorkingDirectory=/var/lib/meilisearch`; the service is now active + enabled and the 16 posts re-imported.
    *(If a provisioning script generates this unit, apply the same fix there.)*
  - Port **8080 is held by nginx** (CloudPanel's web server), so the `novfora-reverb` unit (which hardcoded
    `--port=8080`) was **repointed to 8090 → FIXED**: unit `--port=8090` + `.env REVERB_PORT=8090`, `enable
    --now`; now active + boot-persistent and the round-trip was re-verified over the systemd-managed server.
    Production WSS still needs an nginx proxy from the public origin to `127.0.0.1:8090` (CloudPanel config).
  - `composer audit`: 3 **medium** advisories in transitive `guzzlehttp/guzzle` (<7.12.1) + `guzzlehttp/psr7`
    (<2.12.1), disclosed 2026-06-18 — recommend bumping both (separate maintenance commit).
- **Still deferred (need external accounts/creds):** #3 live Stripe, #4 OAuth/SAML, #5 Web Push, #6
  StopForumSpam, #7 load-at-scale, #8 manual a11y.

### Is the build 1.0-tag-ready?
**Yes — code-wise.** The 1.0 brand gate passes + is CI-enforced, version is 1.0.0, the gate is green
(1560/0-fail · PHPStan 0 · Pint), the fresh-install + release-artifact paths are proven, and no HIGH/MEDIUM
security finding is open. **The tag should be cut only AFTER** the owner (a) reviews + pushes this branch +
merges, and (b) works the **VALIDATE-BEFORE-GO-LIVE** list for any integration they will actually rely on (a
default baseline deploy uses none of them — they ship inert). Cut per `docs/product/release-checklist-1.0.md`.

### ☀️ Morning report — what the owner does next
1. **Review** the 17 commits on `claude/phase-5-ga` (ADRs 0072–0076, flagged-for-review), then **push** + open
   the PR. A freshly-built `novfora-release.zip` (gitignored) sits in the repo root from the P5.6 proof.
2. One harmless **zombie `php -S`** lingers in `forum-dev` from the P5.6 verify probes (unused port) — a
   container restart clears it; it does not affect the gate.
3. New docs: `docs/architecture/security-review-phase5.md`, `CHANGELOG.md`,
   `docs/product/release-checklist-1.0.md`; updated `accessibility.md` / `i18n-and-rtl.md` / `load-testing.md`.

---

## 🛠 RH-4 — First-class subdirectory install on `claude/rh4-subdir-install` — 2026-06-16 (merged into `main`)

**Unattended, owner-authorized build off `main`. 9 conventional, DCO-signed, `Tommy Huynh`-authored commits on
branch `claude/rh4-subdir-install`. NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner
pushes + opens the PR. **ADR-0070** (subdirectory install) + **ADR-0071** (canonical home at the mount root) are
"Accepted — owner-authorized build; flagged for review."

**Final gate (branch HEAD, `forum-dev` container, PHP 8.3.6):** `php artisan test --parallel` **1550 passed /
1 skipped / 0 failed** (12723 assertions; baseline 1525 → **+25** RH-4 tests) · `pint` clean (812 files) ·
`phpstan` (level 5) **0 errors** · `php artisan migrate` clean. Each unit committed only at a green boundary.
(Run via `docker.exe exec forum-dev` from WSL — the WSL distro's own PHP lacks mbstring/xml + composer, so
`forum-dev` is the canonical gate; Docker isn't reachable from inside WSL but `docker.exe` is via interop.)

### What shipped (commit · unit)
- `941485f` **RH4.1** docs — ADR-0070/0071 accepted; the spike's stale **"ADR-0038" renumbered → 0070/0071**
  (0038 was consumed by the mega-build; highest existing was 0069 — resolves the brief's "confirm the next ADR
  number doesn't collide").
- `126b020` **RH4.1b** (ADR-0071) — the forum **index IS the home AT the mount root**: the `forums.index` route
  NAME moved to `/` (so every `route('forums.index')` link generates the mount root), `/forums` is a permanent
  **301 → the root**. Uninstalled `/` still 302s to `/install`. RootRouteTest/ExampleTest + the cache/maintenance/
  smoke suites updated.
- `f3ad4c6` **RH4.2 (APEX)** — `App\Support\Http\BasePathDetector` (in `AppServiceProvider::boot`): forces the
  URL/asset root from the request **only when APP_URL is unset/localhost**, derived from Symfony `getBasePath()`
  (SCRIPT_NAME/RewriteBase). Strict no-op at the root layout (G4) + never overrides a real APP_URL; the forced
  root == the request root, so Livewire's update URI keeps a **single** prefix (no `/community/community/`). 7 tests.
- `6b8c84b` **RH4.3** — `config/app.php` `asset_url`; `App\Install\SubdirectoryScaffold` +
  `php artisan novfora:subdir:scaffold` (Option B: generated stub `index.php` + `.htaccess` + single-canonical
  build/storage links); `.env.example` ASSET_URL + NOVFORA_PUBLIC_LINK notes. 8 tests.
- `b0a587e` **RH4.4 (APEX)** — installer subpath awareness: the wizard pre-fills the Site URL with the detected
  subpath; InstallRunner writes APP_URL + ASSET_URL; RedirectIfNotInstalled allowlist confirmed prefix-agnostic
  (`Request::is()` matches base-stripped path-info — spike open-question #3).
- `5165955` **RH4.5** — `SubdirInstallTest` (8): subdir wizard 200 + `/community`-prefixed Livewire endpoint;
  allowlist prefix-agnostic; post-install `/community/` serves the index; `/community/forums` 301; avatar under
  `/community/storage`; **G4 root-layout regression guard**; **G2 rebuild-drift guard**.
- `b634409` **RH4.6** — `docs/REAL-HOST-VALIDATION.md` §3b rewritten (Option A symlink default / B scaffold /
  C copy last-resort) + a concrete **Hostinger `novfora.com/community/` walkthrough**; getting-started forward
  ref; real-host-findings §RH-4 → RESOLVED.
- `612368f` **fix (apex review)** — `EnvWriter` now escapes `$` for ANY written value (see review below).

### APEX adversarial review (verify-then-refute, 17 agents)
A 4-lens security review of the detector + installer surface: **13 candidates → 12 refuted, 1 MEDIUM confirmed +
FIXED**. `EnvWriter::format()` wrote a bare value containing `${VAR}` unquoted, so dotenv would **interpolate it
on load** — an operator-supplied Site Name like `${APP_KEY}` / `X${DB_PASSWORD}` (wizard rule `string|max:60`)
could leak a secret via MAIL_FROM_NAME / APP_NAME on the unauthenticated pre-install surface. Pre-existing root
cause; RH-4.4 extended `writeEnv` through the same path and the mandated review caught it. Fixed in `612368f`
(+ 3 tests with a real phpdotenv-parse proof). The 12 refuted candidates (Host-header trust, allowlist bypass,
redirect loops, scaffold path-traversal, …) were verified non-exploitable.

### Recorded assumptions / honest notes (also in ADR-0070 + the spike)
- **The detector is a conservative confirmation/pin, not the load-bearing mechanism.** Empirically (a bootstrap
  probe), Laravel ALREADY carries the subpath on every URL surface (route / @vite / Livewire) via the **request
  base path** when SCRIPT_NAME is correct — which Options A/B/C all ensure (symlink / stub+RewriteBase /
  copy+RewriteBase). The detector forces the same root only when APP_URL is unset/localhost and is otherwise a
  no-op; it never forces a root inconsistent with the request base (which would double-prefix Livewire). The real
  levers are: canonical home at root + a correct SCRIPT_NAME (RewriteBase) + the installer writing APP_URL/
  ASSET_URL with the subpath.
- **PWA under a subpath is DEFERRED (documented limitation, recorded not built).** PwaController + the service
  worker still emit root-relative paths (`start_url`/`scope`/`/icons/`/`/build/`/`/offline`). Under a subpath the
  SW simply fails to register (a caught no-op) → offline caching off; core forum + install are unaffected. Not in
  any RH-4 unit/acceptance test; tracked as a fast-follow (noted in ADR-0070).

### ⚠ NOT MINE — concurrent foreign WIP left in the working tree (owner: review/remove before merge)
During this session the tree gained **uncommitted/untracked** changes that are **not part of RH-4** and were left
untouched: `routes/web.php` (+`/forums/import-seed` GET/POST routes + a `use ImportForumSeedController`), and new
untracked `app/Http/Controllers/ImportForumSeedController.php` + `app/Console/Commands/ImportForumSeedCommand.php`
(a separate import/seed experiment, likely from another session). **⚠ Those `/forums/import-seed` routes carry NO
auth middleware — review as a possible unauthenticated upload endpoint and remove or gate before merging from this
tree.** None of it is in my commits.

### ☀️ Morning report — what the owner does next
1. **Review** the 9 commits on `claude/rh4-subdir-install` (ADR-0070/0071, flagged-for-review), then **push** +
   open the PR.
2. **Deploy a subdirectory install** per `docs/REAL-HOST-VALIDATION.md` §3b — for Hostinger
   `novfora.com/community/`, prefer **Option A** (`ln -s ~/novfora/public ~/public_html/community`); on a
   no-symlink plan use **Option B** (`php artisan novfora:subdir:scaffold ~/public_html/community --base=/community`).
   Set the Site URL to the full subpath (the wizard pre-fills it); the index serves at `/community/`.
3. **Triage the foreign import-seed WIP** above (not mine).

---

## 🌙 Phase 4 ENHANCED build (M4 Search/Realtime · M5 Paid memberships · M6 Anti-spam) on `claude/phase-4-enhanced` — 2026-06-15 (merged to `main` via PR #28)

**Unattended, owner-authorized autonomous build off `main` (with M1–M3 already merged). 11 conventional,
DCO-signed, `Tommy Huynh`-authored commits on branch `claude/phase-4-enhanced` (10 feature + 1 wrap-docs).
NOTHING IS PUSHED** — push is interactive-only in the sandbox; the owner pushes + opens the PR. Built M4 → M5 →
M6 in order; each unit is its own gated commit. Every ADR (0060–0069) is **"Accepted — owner-authorized
overnight build; flagged for review."**

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1525 passed / 1 skipped / 0 failed**
(baseline 1428 → **+97** Phase-4-enhanced tests) · `phpstan` (level 5) **0 errors** · `pint` clean ·
`php artisan migrate` clean. Every unit was committed only at a green boundary; APEX units (broadcast authz,
money/Stripe webhook, spam intelligence, external-signal privacy) got dedicated security tests.

### Per-unit status (commit · ADR)
- **M4 Enhanced tier** — `aa42e0c` 4.1 Meilisearch via Scout behind service-detection, DB-driver fallback, the
  **no-leak re-gate** (the index is never the sole privacy boundary), in-admin setup/health (ADR-0060) ·
  `87e259b` 4.2 **(APEX)** Reverb broadcasting + **channel-authorization no-leak fence** (private club / PM /
  hidden thread can never leak over a socket) + polling fallback (ADR-0061) · `95c528f` 4.3 opt-in presence /
  online-member list + presence-channel no-leak (ADR-0062).
- **M5 Paid memberships** — `9b81022` 5.1 tier model + **perk gating through the engine** (TierProjector →
  acl_entries, fixed perk universe) + admin/member surfaces (ADR-0063) · `5695399` 5.2 PaymentProvider contract
  + **offline/manual provider — the only live-granting path** (ADR-0064) · `88c7455` 5.3 **(APEX)** Stripe
  hosted checkout **charging DISABLED** + hardened webhook (HMAC + replay + SSRF posture) (ADR-0065) · `fcdf247`
  5.4 money-fenced paid-clubs hook (ADR-0066).
- **M6 Advanced anti-spam** — `ea896ba` 6.1 **(APEX)** HOLD-only spam intelligence (similarity/burst/reputation)
  + false-positive guards (ADR-0067) · `17426c9` 6.2 staff-gated review surface (scores/signals/actions)
  (ADR-0068) · `d0d3ddc` 6.3 **(APEX)** external-signal tuning + **content-privacy fence** (no post content to a
  third party without an explicit opt-in) (ADR-0069).
- **Wrap docs** — `docs/architecture/phase-4/{search-meilisearch,realtime-reverb,memberships,anti-spam-intelligence}.md`,
  ROADMAP, this handoff.

### ⚠ SCAFFOLDED — NOT VALIDATED against a live service (validate before relying on)
No external service / paid account exists in the build env, so these are proven only against
faked/mocked clients. **Exact enable + validate steps:**

1. **Meilisearch (M4.1).** Run a Meilisearch instance; set `SCOUT_DRIVER=meilisearch` + `MEILISEARCH_HOST` +
   `MEILISEARCH_KEY` (or Admin → Settings → Search); `php artisan scout:sync-index-settings`; `php artisan
   scout:import 'App\Models\Post'`; confirm relevance + that a private-club post never appears for a non-member.
2. **Reverb realtime (M4.2/M4.3).** `composer require laravel/reverb pusher/pusher-php-server`; `php artisan
   reverb:install`; set `BROADCAST_CONNECTION=reverb` + `REVERB_*`; `npm install laravel-echo pusher-js`,
   configure `window.Echo`, `npm run build`; run `php artisan reverb:start` under a supervisor. The
   **channel-authorization logic is fully tested**; the websocket round-trip + thread-page live-append are not.
3. **Live Stripe payments (M5.3).** Create a Stripe account + products; Admin → Settings → Payments: paste
   secret/publishable keys + toggle on; add a Stripe webhook → `https://<site>/webhooks/stripe` for
   `checkout.session.completed` and paste its signing secret; run a **test-mode** checkout and confirm the grant.
   Add `invoice.*` / `customer.subscription.deleted` handling before relying on auto-renewal. **Until enabled,
   the offline/manual provider is the live-granting path; no charge can be initiated.**
4. **StopForumSpam submission (M6.3).** Optional. Set the SFS submission key + enable the live API in Admin →
   Settings → Anti-spam to enable opt-in spammer reporting. Leave "send post content to external services" OFF
   unless your community consents. The scoring/holding pipeline (M6.1) + the review surface (M6.2) are fully real.

### Recorded assumptions (also inline + in DECISIONS.md)
- **Search (M4.1):** engine path taken only for keyword queries with no tag/type facet (those stay on DB to
  remain correct); the visibility filter is applied natively AND re-gated in PHP (ADR-0060).
- **Realtime (M4.2):** events broadcast with **id-only payloads** (no bodies/PII; client refetches); broadcast
  gated on the enhanced tier so baseline pays nothing. No `laravel/reverb`/`pusher-php-server` installed (added
  at enable time) — channel authz tested on the null driver (ADR-0061).
- **Presence (M4.3):** `users.show_online_status` **default FALSE** (opt-in / security-by-default) — the
  "who's online" list is sparse until members opt in; this also closed a prior gap where the theme widget showed
  every active member (ADR-0062).
- **Memberships (M5.1):** perks are a **fixed `TierPerks` universe** (a tier can never grant an arbitrary
  capability); each perk's *effect* is wired per-feature — M5.1 delivers the gating. Tier expiry is an hourly
  cron `novfora:tiers:expire`. No card data stored (ADR-0063).
- **Payments (M5.2/M5.3):** **manual provider is the only live-granting path**; Stripe is **disabled by default**
  (needs the enable flag AND a secret key) — no charge possible. Stripe is hosted-checkout (card data never
  touches the server); no `stripe/stripe-php` dependency (hand-rolled). Webhook handles `checkout.session.completed`
  only (renewal events are a documented follow-up) (ADR-0064/0065).
- **Paid clubs (M5.4):** `clubs.require_membership` **default FALSE**; when on, creation needs the
  `tier.create_clubs` perk — **no new money path** (the perk comes from the membership system) (ADR-0066).
- **Spam intelligence (M6.1):** **HOLD-only** (never deletes); trusted members exempt (staff / `trusted_floor` 3 /
  `established_posts` 50); thresholds in `config/novfora.php → antispam.intelligence` (ADR-0067). One unrelated
  pagination fixture was retargeted to a trusted author (its 20-rapid-replies setup correctly tripped burst).
- **External signals (M6.3):** the SFS block threshold is admin-tunable (default 75 unchanged);
  `antispam.external_content_optin` **default FALSE** is the privacy fence — only metadata is ever sent unless an
  admin opts in (ADR-0069).

### What remains for Phase 4 / toward 1.0 (NOT built this run — record only)
This **completes Phase 4's planned surface (M1–M6).** Remaining is **validation against live
services/providers** (the four items above) + the standing Phase-5 items (full i18n string externalisation,
captured load-test numbers on both tiers, docs → 1.0). No new feature work is queued for Phase 4.

### Pre-existing uncommitted WIP — STASHED again (not mine)
On session start, `main`'s working tree again carried the **prior `claude/mega-build` upgrade WIP** (idempotent
`Schema::hasTable` migration guards + an `UpgradeCommand` restore-path fix — the stash had been popped back since
the last session). To keep this branch clean it was **`git stash`ed** with a backup patch at
`storage/handoff/preexisting-upgrade-wip-13afedd.patch` (the working-tree diff matched the patch exactly).
**Owner: review + `git stash pop` (or apply the patch) on `main` if that work should land.**

### ☀️ Morning report — what the owner does next
1. **Review** the 11 commits on `claude/phase-4-enhanced` (ADRs 0060–0069, all flagged-for-review), then
   **push** + open the PR from your terminal.
2. **Restore the stashed upgrade WIP** on `main` if wanted (see above).
3. **Before relying on the enhanced tier in production:** follow the four "SCAFFOLDED — NOT VALIDATED" enable
   steps above (Meilisearch, Reverb, live Stripe, SFS submission).
4. New docs to skim: `docs/architecture/phase-4/{search-meilisearch,realtime-reverb,memberships,anti-spam-intelligence}.md`.

---

## 🌙 Phase 4 build (M1 Clubs · M2 SSO · M3 PWA+Push) on `claude/phase-4-features` — 2026-06-15 (REVIEW + PUSH THIS)

**Unattended, owner-authorized autonomous build off `main` (the merged mega-build base). 14 conventional,
DCO-signed, `Tommy Huynh`-authored commits on branch `claude/phase-4-features`. NOTHING IS PUSHED** — push is
interactive-only in the sandbox; the owner pushes + opens the PR. Built M1 → M2 → M3 in order; each unit is its
own gated commit. Every ADR (0047–0059) is **"Accepted — owner-authorized overnight build; flagged for review."**

**Final gate (branch HEAD, run in `forum-dev`):** `pest --parallel` **1428 passed / 1 skipped / 0 failed**
(baseline 1302 → +126 Phase-4 tests) · `phpstan` (level 5) **0 errors** · `pint` clean · `php artisan migrate`
+ seed clean. Every unit was committed only at a green boundary.

### Per-unit status (commit · ADR)
- **M1 Clubs** — `d28226f` 1.1 data model + CRUD + directory/home (ADR-0047) · `7cb93c2` 1.2 **(APEX)** club-scoped
  permissions through the engine — new `club` Scope + `ClubRoleProjector`, `permissions:sync` aware (ADR-0048) ·
  `71f1e60` 1.3 membership flows (join/request/invite-token/leave/roster/transfer) + the global-staff rank
  ceiling (ADR-0049) · `eae4b6b` 1.4 discussion on the existing forum stack via `forums.club_id` (ADR-0050) ·
  `52c654f` 1.5 **(APEX)** the no-leak privacy sweep across every surface + an adversarial review that found +
  fixed **2 leaks** (reaction-notify emit, stored-notification render) (ADR-0051) · `9bb75f3` 1.6 configurable
  creation policy (ADR-0052).
- **M2 SSO** — `fc7a1fa` 2.1 **(APEX)** OAuth login (Google/GitHub/Discord), encrypted secrets, email-collision
  **no-merge** (ADR-0053) · `0e72a6d` 2.2 **(APEX)** account linking + the proven-control flow (ADR-0054) ·
  `d6100ae` 2.3 **(APEX)** PKCE + state + CSRF + the outbound-SSRF analysis (ADR-0055) · `c9a152e` 2.4 SAML
  **scaffold only** (ADR-0056).
- **M3 PWA + Push** — `3a60a8d` 3.1 installable PWA + a no-PII service worker (ADR-0057) · `a17e412` 3.2 Web Push
  (VAPID) opt-in cron-tolerant channel (ADR-0058) · `9931254` 3.3 push preferences UI (ADR-0059).

### ⚠ SCAFFOLDED — NOT VALIDATED against a live service (validate before relying on)
- **OAuth (2.1–2.3):** no real Google/GitHub/Discord apps/credentials in the build env → the end-to-end provider
  round-trip is **unproven**; the flow is tested against **mocked** Socialite. Validate with real client ids +
  the published redirect URI before enabling in production.
- **SAML (2.4):** **scaffold only** — interface + detection + mocked tests; **NO concrete provider ships** and it
  **does not work end to end**. Inert by default (every SAML route 404s until an operator binds a provider).
- **Web Push delivery (3.2):** no browser subscription / push endpoint in the build env → the encrypt-and-POST to
  a real push service is **unproven**; wiring tested with a mocked sender. The PWA service worker's offline cache
  + the push client JS are browser-only and unvalidated against a live service.

### Recorded assumptions (also inline + in DECISIONS.md)
- **Club-creation default:** `clubs.creation_policy = trust`, `clubs.creation_min_trust_level = 2` (verified
  member at TL ≥ 2). The brief's "admin-approved" option is realised as **staff-only** creation; a
  request→approval queue is deferred (ADR-0052).
- **SSO provider set:** Google + GitHub (core Socialite) + Discord (socialiteproviders/discord). All providers
  **OFF by default**; secrets stored **encrypted**. New composer deps (all MIT, Apache-2.0-compatible):
  `laravel/socialite ^5.27`, `socialiteproviders/discord ^4.2`, `minishlink/web-push ^10.1`.
- **Club privacy (APEX):** because the board is public-by-default (global guests `forum.view=ALLOW`), pure-ACL
  cannot hide a private club from a logged-in non-member — content-hiding is a **query-level gate**
  (`Forum::clubContentVisibleTo` + extended `VisibleForumIds`) consulted by every surface, with the engine
  carrying club CAPABILITIES + a guests-`NEVER` for anonymous defence-in-depth (ADR-0047/0051).
- **Sole club owner + account deletion:** deleting a sole owner's account leaves the club ownerless — an
  ownership-transfer-before-deletion guard is a documented fast-follow (ADR-0047).
- **PWA icons:** ship a maskable SVG; production should add 192/512 raster PNGs for the widest install prompt
  (ADR-0057).

### What remains for Phase 4 (NOT built this run — record only)
**M4 — Meilisearch + Reverb** (enhanced-tier search execution path + real-time, carried from prior scaffolding);
**M5 — paid memberships / subscriptions** (out of scope this run per the brief); **M6 — advanced anti-spam
intelligence.** Also: the OAuth/SAML/Web-Push validation against live services/providers (above).

### Pre-existing uncommitted WIP — STASHED (not mine)
On session start, `main`'s working tree carried **53 uncommitted files** from the prior `claude/mega-build`
session (idempotent `Schema::hasTable` guards on migrations + an `UpgradeCommand` restore-path fix — coherent
upgrade-robustness WIP, never committed). To keep the Phase-4 branch clean it was **`git stash`ed**
(`stash@{0}: "preexisting-upgrade-wip-from-mega-build …"`) with a backup patch saved at
`storage/handoff/preexisting-upgrade-wip-13afedd.patch`. **Owner: review + `git stash pop` (or apply the patch)
on `main` if that work should land.** A few pre-existing untracked artifacts (`.env.root-stale`,
`provider-symfony~var-dumper.json`, `docs/product/rh4-subdirectory-install-spike.md`, `storage/.backups-root-stale/`)
were left untouched.

### ☀️ Morning report — what the owner does next
1. **Review** the 14 commits on `claude/phase-4-features` (ADRs 0047–0059, all flagged-for-review), then **push**
   + open the PR from your terminal.
2. **Restore the stashed upgrade WIP** if wanted (see above).
3. **Before enabling SSO / Web Push in production:** create real OAuth apps + VAPID keys
   (`php artisan novfora:push:vapid`) and validate end to end — they are scaffolded, not live-validated.
4. New docs to skim: `docs/architecture/phase-4/{clubs,sso,pwa-and-push}.md`.

---

## 🌙 Overnight mega-build on `claude/mega-build` — 2026-06-14 (REVIEW + PUSH THIS)

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
[`docs/product/archive/live-deploy-kickoff.md`](docs/product/archive/live-deploy-kickoff.md)):
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
   [`docs/product/archive/live-deploy-kickoff.md`](docs/product/archive/live-deploy-kickoff.md) — back up off-host, extract
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
   - RH-4: subdirectory install — **DONE** (ADR-0070/0071).
   - Layman "simple-mode" permissions UX — **DELIVERED as ACP v3 · v3-c** (ADR-0082; see the ACP v3 block at the
     top). The ACP v3 program continues with v3-e next (ADR-0080 slice order).

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
