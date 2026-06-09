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

## Status (as of 2026-06-08)

> **▶ Phase 1 / Core MVP COMPLETE · Phase 1.5 Hardening COMPLETE · RH-6–RH-11 FIXED · Default theme MERGED ·
> ACP v1 + v1.1 MERGED · Spike P2 deliverability → GO (PR #8 merged) · ACP v2 MERGED (PR #9).**

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline (dormant), NevoBB rename (ADR-0024), **ACP v2**.

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

## Immediate next actions

1. **Phase 2 — APPROVED, ready to build (owner, 2026-06-09).** Build source is
   [`docs/product/phase-2-implementation-plan.md`](docs/product/phase-2-implementation-plan.md) (engineering
   companion to the approved [`phase-2-plan.md`](docs/product/phase-2-plan.md); 8 review amendments folded in
   — see its §0). **Kickoff scope (greenlit):** P2-M1 engagement core + deliverability light-up (M2 Half-A) +
   multi-participant PMs (M2 Half-B); **Should-tier social HELD as the descope lever** (follow, reputation,
   badges, staff notes, 2nd theme). This **supersedes the product-plan §8 "wait for private-beta-live" gate**
   — deliberate owner override, recorded. **Executed by Claude Code (Opus) when session limits reset.**
   - **Build order:** deliverability light-up → M1 content (7 PR slices) → PMs.
   - **Blocking ADR before PMs land:** account-deletion / privacy cascade for co-owned PII (impl-plan §6).
   - **Record in `DECISIONS.md` during M1:** edit-history diff source (#3), oEmbed embed-host allowlist +
     sandbox policy (#2), any query-budget ceiling change (#6).
2. **Design-first items still queued (do not build without a plan):**
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
