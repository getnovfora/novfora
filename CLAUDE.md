# CLAUDE.md — NovFora (open-source forum platform)

> Standing instructions for Claude Code on this repo. **Read every session.** This is the distilled
> operating contract; the full rationale and complete spec live in `@docs/PROJECT-BRIEF.md` — consult
> it for detail. Place this file at the repo root.

## Project

Open-source, **self-hosted** forum/community platform on a **modern PHP** stack. It combines the
proven fundamentals of phpBB/MyBB/SMF with the polish of XenForo/Invision/ProBoards, while fixing the
pain points all of them share (spam, weak search, dated mobile UX, painful upgrades, core-edit
theming, weak real-time, install friction).

**Name: NovFora** (finalised 2026-06-10 — ADR-0026, supersedes ADR-0024). Single brand; domains
`novfora.com` and `novfora.net` registered. "Hearth" and "NevoBB" are **retired codenames**.

**Status: Stage B (active implementation).** Phase 1 / Core MVP complete; Phase 1.5 hardening complete;
real-host fixes merged; ACP v1 + Spike P2 merged. See `PROJECT-STATE.md` for the current handoff state.

## Locked decisions (do not relitigate — flag concerns, never silently change)

- **Stack:** Laravel 13 (PHP 8.3) + Livewire 4 + Alpine.js + Blade, server-rendered for SEO.
  **MySQL 8 / MariaDB by default**; PostgreSQL supported on Docker/VPS. Vite for assets; ship prebuilt
  assets so the host needs no Node runtime.
- **License:** **Apache-2.0.** Add `LICENSE` + SPDX headers from the first commit.
- **Deployment — two tiers from ONE codebase:**
  - *Baseline* = modern shared PHP host (PHP 8.3+, Composer, SSH, MySQL, cron; **no persistent
    daemons** — cron is the only reliable background mechanism).
  - *Enhanced* = Docker / VPS (Redis, queue workers, WebSockets via Reverb, Meilisearch, S3/MinIO).
- **Editor:** **WYSIWYG-first** (most end-user-friendly). Markdown input optional; **BBCode is an
  import/compatibility layer only**, not the primary authoring format.
- **Multi-tenant SaaS:** out of scope for now — but keep data access clean so it isn't precluded later.

## How we work

- **Stage A — Discovery (no code):** produce the `docs/` planning set, then **STOP for approval.**
- **Stage B — Implementation:** follow the roadmap; **plan before each phase and wait for approval.**
- Keep the repo **runnable on the Baseline tier at every milestone** (`.env.example`, seeds,
  getting-started guide stay current).

## Model routing (this account is on Claude Max)

The dividing line is **correctness load-bearing vs. pattern replication**, not hard vs. easy.

**Default mode: `ultracode`** — start every turn at the top of the stack (**Fable @ max**) and **downgrade as it
deems fit** the moment the work is clearly pattern-replication, not correctness-load-bearing. Rungs, top to
bottom: **Fable @ max** (apex) → **Opus 4.8 `xhigh`/`high`** → **Sonnet 4.6** → **Haiku 4.5**.

### Fable @ max — the apex (ultracode starts here), required for:
- Permission-mask reasoning: anything touching `acl_entries`, `PermissionResolver`, the ALLOW/NO/NEVER
  semantics, rank guards, or the inspector.
- Concurrency / idempotency on the cron-only baseline: the digest transactional claim,
  `withoutOverlapping` + short-mutex discipline, mid-kill resume reasoning.
- Untrusted-input / security boundaries: webhook HMAC, VERP forgery, DSN/ARF parsing, installer surface,
  CSP. Evidence: adversarial reviews caught HIGH/MEDIUM bugs the passing test suite missed.
- Adversarial review workflows themselves — the per-finding verify-then-refute step.
- Spike GO/NO-GO calls, clean-room judgment, plugin/theme API design decisions.

**Tells you've hit the apex (Fable @ max):** the word "NEVER" in a permission context; anything
touching `PermissionResolver/acl_entries`; a DB transaction whose correctness depends on kill-timing;
an endpoint receiving bytes from the internet.

### Opus 4.8 — the heavy rung below the apex
`xhigh` for permission/security/concurrency-adjacent work that didn't quite warrant the apex; `high` (default)
for all other Opus turns. Where ultracode lands after the first downgrade — correctness still matters, but the
apex's full muscle isn't needed.

### Sonnet 4.6 — safe for:
- CRUD/SFC scaffolding that mirrors an existing pattern once the design is locked.
- Migrations, models, factories, `.env.example`, config blocks.
- Applying a component across many sites after the map exists (e.g., 11-site name-component swap).
- Pint/Larastan fix application from a specific finding list; CSS token edits.
- Happy-path test scaffolding in the established Pest idiom; conventional commits; PROJECT-STATE prose.
- "Where is X rendered / which files touch Y" sweeps → delegate to an **Explore sub-agent** (Sonnet).

### Sub-agent pattern for breadth work
Use `Agent(subagent_type: "Explore", model: "sonnet")` for any "sweep the codebase" question.
Keep the main Opus context lean: only conclusions come back, not the full file reads.
Never load 15 files into the main window to answer a "where" question.

### Verification discipline
Docker gates (Pest/Pint/Larastan/audit) are deterministic and **free** — they are the correctness
signal, not the model. Always cap output: `Select-Object -Last N` / `tail -n N` for gate results.
Prefer "write → run gate → read tail → fix" over "reason carefully, then write perfectly."
Never re-read a file you just edited — the harness tracks state.

### Effort defaults
- **`ultracode` (DEFAULT mode):** start at the top of the stack — **Fable @ max** — and **downgrade as it deems
  fit** once a turn is clearly pattern-replication, not correctness-load-bearing. Same dividing line as model routing.
- **Fable @ max — apex rung:** permission-mask / `acl_entries` / `PermissionResolver`, concurrency-idempotency,
  untrusted-input boundaries, adversarial-review synthesis, spike GO/NO-GO, plugin/theme API design.
- **`xhigh` (Opus 4.8):** heavy Opus turns below the apex.
- **`high`:** all other Opus turns.
- **Sonnet 4.6:** CRUD / scaffolding / view boilerplate, mechanical breadth, multi-site sweeps (sub-agents).
- **Haiku 4.5:** trivial single-file edits, no reasoning required.
- `opusplan` (Opus plans, Sonnet implements): mirrors plan-then-build; optional given Max headroom.

## Hard rules (never violate)

- **Strict clean-room.** Never copy or adapt code, UI, templates, themes, branding, or docs from
  **any** reference forum — including SMF, even though its BSD license would permit it. Study schemas,
  BBCode tag semantics, and permission/role concepts, then **reimplement independently**. Read a
  reference forum's DB/output structure **only** to build importers — we copy *data*, never their
  program.
- **Progressive enhancement.** No Baseline feature may hard-depend on Redis, a WebSocket server, a
  persistent worker, or an external search engine. Detect available services and degrade gracefully
  (Laravel Scout DB driver → Meilisearch; DB queue drained by cron → Redis worker; Livewire polling →
  Reverb).
- **Reversible, non-destructive migrations.** Upgrades never require manual DB surgery; test upgrades
  on the Baseline tier.
- **Security by default.** OWASP Top 10; Eloquent parameterized queries; argon2id; CSRF; strict CSP;
  rate limiting; audit logging; sanitized rich-text rendering.
- **Tests with every feature.** Permission-mask resolution and service-tier fallbacks get dedicated
  tests. Pest/PHPUnit for unit+feature, Dusk for browser. No feature is "done" without tests.
- **Dependencies:** only Apache-2.0-compatible licenses; record anything non-obvious in
  `DECISIONS.md` before merging.

## Conventions

- Small, reviewable commits; conventional-commit messages; one logical change each.
- **Commit identity (mandatory):** every commit is authored **and** committed as
  `Tommy Huynh <tommy@saturnhq.net>` — run `git config user.name "Tommy Huynh"` and
  `git config user.email tommy@saturnhq.net` in your environment before the first commit (this overrides
  any sandbox default such as `Claude <noreply@anthropic.com>`). Sign off with `-s` (DCO). Never add AI
  co-author/attribution trailers — `.claude/settings.json` keeps attribution off; do not reintroduce it.
- Record non-obvious choices as ADRs in `DECISIONS.md`.
- Treat the **module and theme APIs as stable, semver'd public contracts** — a breaking change is a
  major-version event.
- Laravel idioms: Eloquent, form requests, policies/gates for authorization, queued jobs, events +
  listeners for the extension hook system. Avoid raw SQL except where measured and justified.

## Ask before

Destructive operations; adding a stack-changing dependency; ambiguous product calls; anything that
would relitigate a locked decision above. When you make a reasonable assumption to keep moving, state
it inline.

## Where things live

- `@docs/PROJECT-BRIEF.md` — the full brief (this file is the short form)
- `docs/research/`, `docs/architecture/`, `docs/product/` — Phase 0 deliverables
- `ARCHITECTURE.md`, `DECISIONS.md` (ADRs), `ROADMAP.md` — living docs
- `LICENSE`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `GOVERNANCE.md` — project/community files
