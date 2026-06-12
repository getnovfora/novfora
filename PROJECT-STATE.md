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
CODE-COMPLETE** — P2-M1 through P2-M4 merged to `main`; **P2-M5 (the Phase-2 closer: the ADR-0028 social
pack — follow + reputation + badges — beta polish, and the FULL regression incl. executed RH-10/RH-11
rehearsals) is BUILT and green on branch `claude/p2-m5-beta-social`**, awaiting the owner push → PR →
merge → push of the Public Beta tag (see Immediate next actions).

> Per-milestone build detail (gates, test counts, adversarial-review findings, scope fences) →
> [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).

**`main` carries:** M0–M5, P1.5 hardening, real-host fixes RH-6–RH-11, default theme + theme polish R1,
ACP v1 + v1.1 patch, Spike P2 deliverability pipeline, NovFora rename (ADR-0024/0026), **ACP v2** (PR #9,
`30bc466`), **P2-M1** engagement/content-depth, **P2-M2 Half-A** deliverability light-up, **P2-M2 Half-B**
multi-participant PMs (PR #17, `535a924`), **P2 account deletion** (ADR-0025, `b006163`), **P2-M3** activity
feed & community-feel core (`ae9bba3`), and **P2-M4** moderation depth / search facets / preferences (PR #19,
`c56126e`). **Origin `main` is the source of truth; nothing is left unpushed.**

**HELD (deferred to fast-follow / Phase 3):** staff notes · reputation leaderboard / top-members ·
trust-level auto-promotion · a 2nd example theme (the M5 Should, carried — recorded). *Follow +
reputation/points + badges shipped in M5 Core per ADR-0028.*

## Immediate next actions

1. **▶ OWNER — land P2-M5 and tag the 🚩 Public Beta.** The full milestone (4 slices, 19 commits) sits on
   **`claude/p2-m5-beta-social`** — built, adversarially reviewed (62 agents; 2 HIGH + 4 MEDIUM + 4 LOW
   fixed), all gates green, **RH-10/RH-11 rehearsals EXECUTED** (not paper-checked). Owner steps: push the
   branch → open the PR (slice boundaries are clean commit groups if four PRs are preferred) → merge →
   push the local annotated tag (`v1.0.0-beta.1`, created on the branch tip — if the merge is a squash,
   re-tag the squash commit instead). Build detail → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md) §P2-M5;
   decisions → `DECISIONS.md` (ADR-0028 implementation + review notes).

2. **Shipped on `main` — full detail in [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md).** P2-M1 engagement/content
   depth · P2-M2 Half-A deliverability light-up · P2-M2 Half-B multi-participant PMs (PR #17) · account
   deletion / ADR-0025 (`b006163`) · P2-M3 activity feed + community-feel (`ae9bba3`) · P2-M4 moderation
   depth + search facets + preferences (PR #19, `c56126e`).

3. **Fast-follows queued by M5** (post-beta, small): staff notes · reputation leaderboard / top-members ·
   TL auto-promotion by reputation · 2nd example theme (the carried Should) · `isSoleAdmin` TOCTOU +
   `ActivityVersion` lost-bump hardenings (pre-existing, flagged in the M5 review).

4. **Design-first items still queued (do not build without a plan):**
   - RH-4: subdirectory install (ADR needed)
   - Layman "simple-mode" permissions UX (ACP v3, separate cycle)
   - **Phase 3 — Extensibility** (plugin/module API, REST + webhooks, importers, theme configurator,
     analytics): its own discovery + plan-before-code gate.

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
