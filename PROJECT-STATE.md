# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (moved to keep this file lean).
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

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

## Status (as of 2026-06-11)

> **▶ Phase 1 / Core MVP COMPLETE · Phase 1.5 Hardening COMPLETE · RH-6–RH-11 FIXED · Default theme MERGED ·
> ACP v1 + v1.1 MERGED · Spike P2 deliverability → GO (PR #8 merged) · ACP v2 MERGED (PR #9) ·
> ▶ P2-M1 ENGAGEMENT & CONTENT DEPTH — COMPLETE on `main` · ▶ P2-M2 HALF-A DELIVERABILITY LIGHT-UP & RICH
> NOTIFICATIONS — COMPLETE on `main` ·
> ▶ P2-M2 HALF-B MULTI-PARTICIPANT PMs — **MERGED to `main` (PR #17, commit `535a924`)** ·
> ▶ P2 ACCOUNT DELETION (ADR-0025) — **BUILT**, the single audited `AccountDeletionService` cascade + both
> confirmation surfaces (voluntary ⚡delete-account settings SFC; admin-forced staff-tools flow); Pint · Larastan
> **L5 clean** · composer audit clean · assets-fresh (no new utility classes) · **+21 dedicated tests**
> (10 service/cascade + 9 confirm-flow/route + 2 Dusk) all green, full suite **813 passed / 1 skipped
> (2643 assertions)**; branch
> `claude/p2-account-deletion` off `main` — **push pending** (sandbox has no interactive git credentials; user
> pushes, then opens the PR).**

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline, NovFora rename (ADR-0024/0026), **ACP v2**, **P2-M1
engagement/content-depth**, and **P2-M2 Half-A deliverability light-up** (the last two landed on `main`
2026-06-11). **Committed locally, NOT yet pushed:** **P2-M2 Half-B** (`claude/p2-m2b-pms`, branched off
`main`); the in-sandbox push is blocked on interactive git credentials — **the user pushes**, then opens the PR.

**ACP v2 — MERGED to main (PR #9, commit `30bc466`):**
Pint PASS (361 files) · Larastan level-5 clean · **Pest 518 passed / 1 skipped (1930 assertions)** ·
`composer audit` + `npm audit` clean · CSS 8.54 KB gz (budget 50) · assets rebuilt.
Adversarial review (18 agents): 4 findings fixed — HIGH membership-boundary bypass on delete-with-reassign,
MEDIUM priority cap (custom groups could outrank Mods), MEDIUM AA palette (4 hex failures), MEDIUM setRole
audit gap. Dusk journey + coloured-group screenshots wired into `AdminJourneyTest` (CI produces them).

**What ACP v2 shipped:**
- PART 1: member-group manager (`Admin → Members → Groups`) — `GroupManager` service, `⚡groups` SFC,
  system-group protection, delete-safety, membership boundary, permissions via Role presets + `RoleExpander`.
- PART 2: staff/group name colours — AA-safe `GroupColor` palette, `--group-*` tokens (light + both dark),
  `<x-ui.user-name>` component at 11 name sites, `.groups` eager-loaded everywhere.
- Schema: only `groups.description` new; reused pre-existing `groups.color` M1 seam. Reversible.

**P2-M1 — Engagement & content depth (BUILT 2026-06-09, 7 stacked branches off `main`, awaiting PR review):**
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
   this PROJECT-STATE update, and a one-line fix to slice 6's modal (FQ `Carbon` — caught by the integrated suite).
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

**P2-M2 Half-A — Deliverability light-up & rich notifications (BUILT 2026-06-09, `claude/p2-m2a-deliverability`):**
A LIGHT-UP + WIRE-IN of the dormant Spike-P2 pipeline (no rebuild), per
[`p2-m2a-deliverability-code-kickoff.md`](docs/product/p2-m2a-deliverability-code-kickoff.md). Six items, small
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

## Immediate next actions

1. **P2-M1 — COMPLETE on main (2026-06-11).** All 7 slices (reactions → polls → prefixes → tags → drafts →
   edit-history → oembed) are on `origin/main` with full conventional-commit history preserved. PRs #10–16
   show as CLOSED (commits landed via direct push in a prior session, not through GitHub's merge button) —
   history is intact. Build source:
   [`docs/product/phase-2-implementation-plan.md`](docs/product/phase-2-implementation-plan.md).
2. **Deliverability light-up (M2 Half-A) — COMPLETE on main (2026-06-11).** All 8 commits on `origin/main`
   (same pattern as M1 — landed via direct push). Stale branch deleted. Origin is clean; only `main` remains.
3. **Multi-participant PMs (M2 Half-B) — MERGED to `main` (PR #17, commit `535a924`).** Built per
   [`docs/product/p2-m2b-pms-code-kickoff.md`](docs/product/p2-m2b-pms-code-kickoff.md), 10 small DCO commits:
   schema → **TL0 mass-PM NEVER pin** + PmRateLimiter + ConversationPolicy → send spine (pm.send re-check / rate
   / cap / **ignore** / single ContentRenderer path / report-on-PM) → live **`pm.received`** emitter → **ADR-0025
   deletion cascade** → inbox/conversation/composer UI + nav unread badge → DECISIONS → adversarial-review
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
4. **Account deletion (ADR-0025) — BUILT (2026-06-11), branch `claude/p2-account-deletion` off `main` — PUSH
   PENDING (user pushes + opens the PR; gh absent in-sandbox).** Built per
   [`docs/product/p2-account-deletion-code-kickoff.md`](docs/product/p2-account-deletion-code-kickoff.md). The
   M1-deferred forced-cascade integration tests, now that PMs have landed. Closes ADR-0025 end-to-end:
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
5. **Design-first items still queued (do not build without a plan):**
   - RH-4: subdirectory install (ADR needed)
   - Layman "simple-mode" permissions UX (ACP v3, separate cycle)
   - ~~Hearth/NevoBB→NovFora in-code rename~~ — **DONE** (commit `b0cc294`, 2026-06-11, ADR-0026)

## Working rules

Full rules in `CLAUDE.md`. Short form: strict clean-room · progressive enhancement · reversible migrations ·
security by default · tests with every feature · semver'd module/theme API · conventional commits + ADRs.

## Model & effort

Full routing in `CLAUDE.md §Model routing`. Short form:
- **Opus 4.8 `xhigh`:** permission/security/concurrency core, adversarial reviews, mechanism design.
- **Sonnet 4.6:** CRUD, scaffolding, view boilerplate, mechanical breadth, multi-site sweeps (sub-agents).
- **Docker gates are free** — verify with `pest`/`pint`/`larastan`, not by re-reasoning.
- Never re-read a file you just edited (the harness tracks state). Cap gate output — tail/`Select-Object -Last`.
