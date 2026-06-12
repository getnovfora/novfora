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

## Status (as of 2026-06-12)

**Phase 1 / Core MVP · Phase 1.5 hardening · real-host fixes RH-6–RH-11 — all COMPLETE.** Default theme +
polish R1, ACP v1/v1.1, Spike P2 deliverability (GO), and **ACP v2** all merged. **Phase 2 (Community) is
~80% landed** — P2-M1 (engagement/content depth), P2-M2 Half-A (deliverability light-up + rich notifications)
and Half-B (multi-participant PMs), P2 account deletion (ADR-0025), P2-M3 (activity feed + community-feel), and
P2-M4 (moderation depth + search facets + preferences) are **all merged to `main`**. **Next: P2-M5 — the Phase 2
closer → 🚩 Public Beta** (see Immediate next actions).

> Per-milestone build detail (gates, test counts, adversarial-review findings, scope fences) →
> [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline, NovFora rename (ADR-0024/0026), **ACP v2** (PR #9,
`30bc466`), **P2-M1** engagement/content-depth, **P2-M2 Half-A** deliverability light-up, **P2-M2 Half-B**
multi-participant PMs (PR #17, `535a924`), **P2 account deletion** (ADR-0025, `b006163`), **P2-M3** activity
feed & community-feel core (`ae9bba3`), and **P2-M4** moderation depth / search facets / preferences (PR #19,
`c56126e`). **Origin `main` is the source of truth; nothing is left unpushed.**

**HELD (deferred to fast-follow / Phase 3):** staff notes · reputation leaderboard / top-members ·
trust-level auto-promotion · a 2nd example theme (optional M5 stretch). *Follow + reputation/points + badges
were pulled into M5 Core — ADR-0028.*

## Immediate next actions

1. **Shipped on `main` — full detail in [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).** P2-M1 engagement/content
   depth (reactions → polls → prefixes → tags → drafts → edit-history → oembed) · P2-M2 Half-A deliverability
   light-up · P2-M2 Half-B multi-participant PMs (PR #17) · account deletion / ADR-0025 (`b006163`) · P2-M3
   activity feed + community-feel (`ae9bba3`) · P2-M4 moderation depth + search facets + preferences (PR #19,
   `c56126e`). The account-deletion → M3 → M4 chain landed 2026-06-12. Build sources:
   `docs/product/*-code-kickoff.md` + [`phase-2-implementation-plan.md`](docs/product/phase-2-implementation-plan.md).

2. **▶ NEXT — P2-M5: Phase 2 closer → 🚩 Public Beta — APPROVED 2026-06-12 (ADR-0028). Build source:
   [`docs/product/p2-m5-beta-social-code-kickoff.md`](docs/product/p2-m5-beta-social-code-kickoff.md).** Scope =
   beta polish (`DemoSeeder` / `getting-started.md` / `.env.example` refresh) + the **social pack pulled from
   HELD — follow + reputation/points + badges** + the full Phase-2 regression (perf/asset/query budgets ·
   forced-absence · **RH-10 auto-upgrade + RH-11 restore rehearsal** · permission-mask + **extended
   deletion-cascade** truth tables); optional 2nd example theme stretch. **Apex (Fable@max) pieces:** idempotent
   reputation/badge award · the extended ADR-0025 cascade (revoke rep from a deleted user's reactions →
   recompute affected authors) · `follow.create` anti-spam. **4 PR slices** (follow → reputation → badges →
   closer), built by Code on the real machine (Cowork writes no app code). Owner-tunable before/at build: rep
   weights, default badge set/thresholds, empty-following-feed behaviour (defaults proposed in the kickoff).

3. **Design-first items still queued (do not build without a plan):**
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
