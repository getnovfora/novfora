# CLAUDE.md — [YourForum] (open-source forum platform)

> Standing instructions for Claude Code on this repo. **Read every session.** This is the distilled
> operating contract; the full rationale and complete spec live in `@docs/PROJECT-BRIEF.md` — consult
> it for detail. Place this file at the repo root. Rename `[YourForum]` once the project has a name.

## Project

Open-source, **self-hosted** forum/community platform on a **modern PHP** stack. It combines the
proven fundamentals of phpBB/MyBB/SMF with the polish of XenForo/Invision/ProBoards, while fixing the
pain points all of them share (spam, weak search, dated mobile UX, painful upgrades, core-edit
theming, weak real-time, install friction).

**Status: pre-code.** Do not write application code until Phase 0 (Discovery) is approved.

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

## Model & effort (this account is on Claude Max)

- **Build with Opus 4.8 at `xhigh` effort** — the documented sweet spot for coding/agentic work. The
  default is `high`, so set `xhigh` explicitly (`/model` → toggle effort with the arrow keys).
- **Think hard** (keep xhigh; reason deeply): permission-mask resolution, tenancy-safe data access,
  the anti-spam subsystem, security-sensitive code, the phpBB/MyBB/SMF importers, and the plugin/theme
  API. A subtle miss in these is expensive.
- **Move fast** (Sonnet 4.6 is fine): CRUD, view scaffolding, boilerplate, test stubs, repetitive
  refactors once the design is settled.
- Use `max` effort only if evals show real headroom at `xhigh`. Haiku 4.5 for trivial edits.
- `opusplan` (Opus plans, Sonnet implements) mirrors our plan-then-build flow and saves tokens;
  optional given Max headroom.

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
