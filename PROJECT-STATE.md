# PROJECT-STATE.md — NevoBB (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (moved to keep this file lean).
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

## What this is

**NevoBB** (name locked 2026-06-07, ADR-0024; "Hearth" is the **retired codename** — still in code as
`config/hearth.php`, `hearth:*` commands, `HEARTH_*` env keys; rename is a **separate planned task**) —
open-source (**Apache-2.0**), self-hosted forum/community platform; modern PHP; **two tiers from one
codebase** (baseline shared PHP host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission
masks; strict clean-room.

## Current stack

**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered. PHP 8.3 floor. MySQL 8 / MariaDB default;
PostgreSQL on Docker/VPS. Vite, prebuilt assets (no host Node). Approved — ADR-0001/0002 (Accepted).

## How we work

- **Claude Code (build):** scaffolds and writes the Laravel app. Plan-before-code per phase.
- **Claude Cowork (knowledge work):** reviews plans/docs, preps gate packets, writes status summaries. No app code.
- **Don't run both against the working tree at the same time.** Commit between handoffs; git is the source of truth.
- **Two stages, gated:** Stage A (Discovery) → Phase 0 gate **passed** → Stage B phased implementation
  (plan-before-code, wait for approval per phase).

## Status (as of 2026-06-09)

> **▶ Phase 1 / Core MVP COMPLETE · Phase 1.5 Hardening COMPLETE · RH-6–RH-11 FIXED · Default theme MERGED ·
> ACP v1 + v1.1 MERGED · Spike P2 deliverability → GO (PR #8 merged) · ACP v2 MERGED (PR #9) ·
> ▶ P2-M1 ENGAGEMENT & CONTENT DEPTH — BUILT, all gates green, pushed (7 slices, awaiting PR review).**

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline (dormant), NevoBB rename (ADR-0024), **ACP v2**.
**Pushed, not yet on main:** P2-M1 (7 stacked feature branches `claude/p2-m1-*`).

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
Pint PASS (417 files) · Larastan L5 clean · **Pest 701 passed / 1 skipped (2309 assertions)** ·
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
**Carried forward to M2 (NOT in this packet):** the §6 account-deletion/privacy-cascade ADR (reactions/poll-
votes/tags hard-delete with their owner; the cascade is owner-confirmable before PMs land) and the Dusk
browser-journey screenshots for react/poll/prefix/tag/draft (wired into the dusk harness; run in CI).

## Immediate next actions

1. **P2-M1 — BUILT (2026-06-09), awaiting PR review/merge.** 7 stacked branches `claude/p2-m1-*` (reactions →
   polls → prefixes → tags → drafts → edit-history → oembed); the oembed tip is the integrated, all-gates-green
   state. Merge in stack order (each lands runnable + tested). Build source remains
   [`docs/product/phase-2-implementation-plan.md`](docs/product/phase-2-implementation-plan.md).
2. **Next in the greenlit kickoff scope (own packets, not yet built):**
   - **Deliverability light-up (M2 Half-A)** — code merged + dormant; wire `Notifier→DigestQueue`,
     `SuppressionGate` dedupe, new event types (incl. `reaction`, which already EMITS the `Reacted` event seam),
     prefs UI, memo follow-ups. Parallel-safe with M1.
   - **Multi-participant PMs (M2 Half-B)** — **BLOCKED on the §6 account-deletion / privacy-cascade ADR**
     (decide first; P2-M1 already hard-deletes reactions/poll-votes/tags with their owner per that intended
     default — confirm + add forced-cascade tests when PMs land).
   - **Dusk browser-journey screenshots** for react/poll/prefix/tag/draft — wired into the dusk harness; finish
     in the harness/CI.
   - **Should-tier social HELD** as the descope lever (follow, reputation/points, badges, staff notes, 2nd theme).
3. **Design-first items still queued (do not build without a plan):**
   - RH-4: subdirectory install (ADR needed)
   - Layman "simple-mode" permissions UX (ACP v3, separate cycle)
   - In-code Hearth→NevoBB rename (one reviewed change per ADR-0024; do not rename ad-hoc)

## Working rules

Full rules in `CLAUDE.md`. Short form: strict clean-room · progressive enhancement · reversible migrations ·
security by default · tests with every feature · semver'd module/theme API · conventional commits + ADRs.

## Model & effort

Full routing in `CLAUDE.md §Model routing`. Short form:
- **Opus 4.8 `xhigh`:** permission/security/concurrency core, adversarial reviews, mechanism design.
- **Sonnet 4.6:** CRUD, scaffolding, view boilerplate, mechanical breadth, multi-site sweeps (sub-agents).
- **Docker gates are free** — verify with `pest`/`pint`/`larastan`, not by re-reasoning.
- Never re-read a file you just edited (the harness tracks state). Cap gate output — tail/`Select-Object -Last`.
