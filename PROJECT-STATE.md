# PROJECT-STATE.md — NovFora (session resume / handoff)

> **Purpose:** single source of truth for where this project stands right now. Read this **first**, every
> session — both Claude Code and Claude Cowork. Keep it at the repo root. Whoever is working keeps it updated.
>
> **Completed milestone history → [`PROJECT-HISTORY.md`](PROJECT-HISTORY.md)** (reference-only; do not load every
> session). This file is kept lean: the active task, the latest run, the VALIDATE-BEFORE-GO-LIVE list, and open
> follow-ups. Everything below those lives in history.
>
> **Standing detail lives in the folder — read, don't restate:** `docs/PROJECT-BRIEF.md` (full spec) ·
> `CLAUDE.md` (rules, model/effort routing) · `DECISIONS.md` (ADR log) · `ARCHITECTURE.md` ·
> `docs/architecture/`, `docs/product/`, `docs/research/` (Stage A set).

---

## ▶ ACTIVE TASK — v1.4 "The Creator Release" (kickoff 2026-07-17, IN PROGRESS)

Executing [`docs/product/FABLE-V1.4-KICKOFF-2026-07-17.md`](docs/product/FABLE-V1.4-KICKOFF-2026-07-17.md)
end-to-end, unattended (owner pre-approved the BUILD-PROMPTS 5–8 plan gates by approving that doc). Order:
**Phase 0 reconcile → 4A style engine → 4B ◆ template hooks/Diff3 → 4C ◆ export+Registry+importers (NOV-124
spike self-gates GO/NO-GO) → 4D admin-at-scale → 4E ◆◆ admin API + self-upgrade → release run** ending at a
**locally tagged `v1.4.0`** (owner pushes). One branch per slice off `main`; nothing merges until the release
run; every ◆/◆◆ slice gets the verify-then-refute apex review with 0 open HIGH/MEDIUM before merge.

---

## 🏁 Release record — v1.3.0 SHIPPED (tagged + pushed; recorded 2026-07-17)

> Written during v1.4 Phase 0: v1.3.0 shipped without a morning report — this is the missing record. The
> stranded Phase 3A/3B session reports now live in `PROJECT-HISTORY.md` (moved there with the rest).

- **Tag `v1.3.0` = `4f5585e`**, pushed; `origin/main` **in sync** at `126e5f0` (the v1.3–v1.5 roadmap +
  admin-API/populate spec + build-prompts docs commit sits on top of the tag). `config/app.php` = `1.3.0`.
- **Phase 3A — foundation & hygiene:** **NOV-121** ◆ engine hygiene (last-plain-admin removal guard +
  delegation fan-out; ADR-0086/0087 amended) · **NOV-96** ◆ permission-aware UI contract (`Affordance` +
  `<x-action>` + route-level friendly-403; **ADR-0109**) · **NOV-122** CI completion (13 Dusk journeys wired,
  `route:clear` in CI, guzzle/psr7 audit bump, asset budget verified). Both ◆ slices apex-reviewed, GO.
- **Phase 3B — front-of-site redesign:** card-based board index (**NOV-90**), sm–md search entry (**NOV-92**),
  board/thread guest CTA via `<x-action>` (**NOV-93**), centered auth header (**NOV-94**), `x-ui.timestamp`
  (**NOV-95**) — all through the 3A contract, no new JS deps.
- **Phase 3C — engagement:** multi-quote basket (**NOV-100** U1) · follow tags + Watched home-loop
  (**NOV-101** U2) · announcement topic type with dismissible targeted banners (**NOV-102** U4).
- **Phase 3D — community:** ◆-lite profile wall with fenced status posts + mod queue (**NOV-91**) · inline
  approve/reject on held replies in-thread (**NOV-104** U6) · welcome email + new-member checklist
  (**NOV-123** onboarding-lite).
- **Release run + post-tag green-ups:** version bump + onboarding hot-path gate (`f86262d`); fail-soft layout
  widgets + cold-render budget (`44581db`); **two real bugs exposed by the newly CI-gated Dusk journeys fixed**
  (`86a4fca`, NOV-122's wiring paying off); installer-wizard Dusk timeouts (`6eabc40`); thread query-budget
  cache-warmth edge (`4f5585e`).
- **Residue:** 2 harness-timing Dusk journeys returned to **CI-pending** with tracking (`91b8984`) · parked
  product calls **U8 imported-username revert** (ADR-0106) and **U18 Turnstile fail-open posture** (ADR-0107)
  still await owner decisions · the old `F:\ForumGen` **Gemini API key revocation is unconfirmed**.

---

## ✅ VALIDATE-BEFORE-GO-LIVE (consolidated — carried from Phase 4/5 + enhanced-tier validation)

Scaffolded/disabled-by-default; unit-tested against fakes only. Enable + validate per the named ADR /
`docs/product/release-checklist-1.0.md`. (Full Phase-5 narrative → `PROJECT-HISTORY.md`.)

1. **Meilisearch** (ADR-0060) — **PROVEN 2026-06-19** against a live engine (no private-club leak held; degrades to
   DB on outage). Recommend `SCOUT_QUEUE=true` on Enhanced so a transient engine outage degrades on writes too.
2. **Reverb realtime** (ADR-0061/0062) — **PROVEN 2026-06-19** (id-only payload over a live socket; unauthorized
   subscriber 403 at `/broadcasting/auth`). Production WSS needs an nginx proxy → `127.0.0.1:8090`.
3. **Live Stripe** (ADR-0065 + P5.1) — real keys/webhook; grant only on `payment_status=paid`; add `invoice.*` /
   cancellation before auto-renewal. **Still deferred** (needs a Stripe account).
4. **OAuth / SAML** (ADR-0053–0056) — real apps; the no-merge rule + the **staff-2FA step-up** end to end. **Deferred.**
5. **Web Push** (ADR-0058) — VAPID; live push-service round-trip. **Deferred.**
6. **StopForumSpam submission** (ADR-0069) — optional; key + the content-privacy opt-in. **Deferred.**
7. **Load test at scale** (ADR-0045/0074) — k6/artillery on a real baseline + enhanced host; capture p50/p95/p99
   vs the SLOs; `EXPLAIN` the forum-listing sort. **Deferred.**
8. **Manual a11y** (ADR-0044) — contrast (1.4.3, incl. admin custom theme tokens) · keyboard nav + no focus traps
   (2.1.1/2.1.2) · visible focus (2.4.7) · reduced-motion (2.3.1) · live-region status (4.1.3) · a screen-reader +
   RTL visual pass on clubs/PMs/memberships. (`docs/architecture/accessibility.md`.) **Owner/QA.**
9. **PWA under a `/community/` subpath** (ADR-0078) — install prompt + SW registration scope + the blue-N icon on a
   real device/host (not machine-verifiable here).

**Redis cache/queue** path (DB 1 + `novfora-queue` worker) was also proven live 2026-06-19.

---

## 📌 Open follow-ups (deferred, not blocking)

- **Dusk in CI:** 2 harness-timing journeys are CI-pending with tracking (`91b8984`); the 13 wired journeys are
  the green-verifier on CI (no Chrome in the build envs).
- **Parked product calls (owner):** U8 imported-username revert vs the modern format rule (ADR-0106, recommendation
  on record) · U18 Turnstile fail-open vs the fail-closed default (ADR-0107, recommendation on record).
- **Gemini API key** committed in the old `F:\ForumGen\generate_forum.py` — revocation requested 2026-07-02,
  **not confirmed revoked**. The replacement script is env-only.
- **Group clone button on the live demo** — code correct on `main` (PR #43); suspect stale compiled-Blade /
  opcache on Hostinger, or the checked group not `type='custom'`. Next demo cycle: confirm deploy, `view:clear` +
  opcache reset, verify the group's `type` column.
- **`novfora:trust:recompute --user`** prints the generic summary, not the per-user reason (engine correct; print
  is terser). Small polish.
- **Pending-member exit-ramp** — spec'd at `docs/product/pending-member-review-kickoff.md`; **being absorbed by
  v1.4 U14 (NOV-112)** this cycle.
- **demo.novfora.com** still runs pre-v1.3 — the v1.3.0 upgrade (and v1.4.0 after it) goes via the cron
  auto-upgrade path, backup-first (both carry migrations; not assets-only).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)**, then **v1.2.0** and **v1.3.0** (tagged + pushed; record above). Current work:
**v1.4 "The Creator Release"** (active task above). The private Populate plugin (E6a/E6b) lives in its own repo
`D:\novfora-populate`, junctioned at `modules/novfora/populate` — never committed here, never shipped.

**How we work:** Claude Code builds (plan-before-code per phase); Claude Cowork does knowledge work (no app code);
don't run both against the working tree at once; commit between handoffs. Two stages, gated.

**Working rules** (full in `CLAUDE.md`): strict clean-room · progressive enhancement (no Redis/queue/Reverb/Meili/S3
hard-dep — detect + degrade) · reversible migrations · security by default · tests with every feature · semver'd
module/theme API · conventional commits + ADRs · commit identity `Tommy Huynh <tommy@saturnhq.net>` + DCO `-s`, no
AI trailers · close the loop on Linear every cycle (states, comments, discoveries, blocked writes).

**Model & effort** (full in `CLAUDE.md §Model routing`): `ultracode` default — start at **Fable @ max** (apex),
downgrade as fit when work is pattern-replication. Fable @ max for permission/security/concurrency core, adversarial
reviews, spikes, API design; Opus 4.8 `xhigh`/`high` below the apex; Sonnet 4.6 for CRUD/scaffolding/breadth sweeps
(Explore sub-agents). Docker/native gates are free — verify with `pest`/`pint`/`phpstan`, not by re-reasoning. Never
re-read a file you just edited. Cap gate output (`tail`).
