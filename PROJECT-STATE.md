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

## ▶ ACTIVE TASK (unattended) — v1.x Feature Program — 2026-06-22

Execute [`docs/product/v1x-feature-program-kickoff.md`](docs/product/v1x-feature-program-kickoff.md) end-to-end in
order (**R → S → A → M → T**). Independent branch per slice off `main`, gated, committed at a green boundary,
**nothing pushed/merged** (owner reviews). Verify each slice against current `main` first and prefer the codebase.
Apex slices (A1/A2 member data + ban/warn, M2 subscription fan-out, T2 email-template render) get an in-session
adversarial verify-then-refute review — **HALT + report on any unresolved HIGH**. Write the morning report back to
`PROJECT-STATE.md` top when done.

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

- **Design-Polish:** review + merge the 6 unmerged branches (above); hand-merge Slices 2↔3; run Dusk in CI.
- **Group clone button on the live demo** — code correct on `main` (PR #43); suspect stale compiled-Blade /
  opcache on Hostinger, or the checked group not `type='custom'`. Next demo cycle: confirm deploy, `view:clear` +
  opcache reset, verify the group's `type` column.
- **`novfora:trust:recompute --user`** prints the generic summary, not the per-user reason (engine correct; print
  is terser). Small polish.
- **Pending-member exit-ramp** — the systemic fix for the `status=pending` false-flag ("Dan") is spec'd at
  `docs/product/pending-member-review-kickoff.md` for a future cycle.
- **Pre-existing inherited test reds (env-sensitive, not a regression):** `SubdirInstallTest` +
  `PwaTest` subpath-scope cases fail under the VPS native runtime (base-path routing under a simulated `/community`
  mount → 404). Untouched by v1.x slices; tracked, not chased here. Plus the standing **asset-budget drift**
  (rebuild `public/build`) + **composer-audit** transitive `guzzlehttp` advisories (bump in a maintenance commit).

---

## Orientation (short form — full detail in `CLAUDE.md` + `PROJECT-HISTORY.md`)

**NovFora** (name locked 2026-06-10, ADR-0026) — open-source (**Apache-2.0**), self-hosted forum/community platform;
**Laravel 13 + Livewire 4 + Alpine.js + Blade**, server-rendered, PHP 8.3 floor; MySQL 8 / MariaDB default,
PostgreSQL on Docker/VPS; Vite prebuilt assets (no host Node). **Two tiers from one codebase** (baseline shared PHP
host / enhanced Docker-VPS); WYSIWYG-first editor; phpBB-grade permission masks; strict clean-room.

**Status:** shipped **1.0.0 (GA)** — Phases 0–5 + the full ACP v3 program complete. Current work is **post-1.0
increments**: the Design-Polish Program (above, unmerged) and the **v1.x Feature Program** (active task, above).

**How we work:** Claude Code builds (plan-before-code per phase); Claude Cowork does knowledge work (no app code);
don't run both against the working tree at once; commit between handoffs. Two stages, gated.

**Working rules** (full in `CLAUDE.md`): strict clean-room · progressive enhancement (no Redis/queue/Reverb/Meili/S3
hard-dep — detect + degrade) · reversible migrations · security by default · tests with every feature · semver'd
module/theme API · conventional commits + ADRs · commit identity `Tommy Huynh <tommy@saturnhq.net>` + DCO `-s`, no
AI trailers.

**Model & effort** (full in `CLAUDE.md §Model routing`): `ultracode` default — start at **Fable @ max** (apex),
downgrade as fit when work is pattern-replication. Fable @ max for permission/security/concurrency core, adversarial
reviews, spikes, API design; Opus 4.8 `xhigh`/`high` below the apex; Sonnet 4.6 for CRUD/scaffolding/breadth sweeps
(Explore sub-agents). Docker/native gates are free — verify with `pest`/`pint`/`phpstan`, not by re-reasoning. Never
re-read a file you just edited. Cap gate output (`tail`).
