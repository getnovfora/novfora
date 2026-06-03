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
