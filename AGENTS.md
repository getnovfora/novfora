<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->

# AGENTS.md — NovFora standing instructions for Codex (and other AGENTS.md-aware agents)

> Read every session. This is the **Codex-side companion to `CLAUDE.md`** (as `GEMINI.md` is for Gemini).
> `CLAUDE.md` and the `docs/` set are the **canonical source of truth**. When this file and `CLAUDE.md`
> disagree, `CLAUDE.md` wins — flag the drift so it gets fixed.

## Read first, in this order

1. `CLAUDE.md` — locked decisions, hard rules, conventions (the operating contract).
2. `PROJECT-STATE.md` — where the project stands right now (active task, owner section, open follow-ups).
3. `DECISIONS.md` — ADR log (search it; don't load all 4k lines). `docs/PROJECT-BRIEF.md` for full spec.
4. Any spec you were handed (`docs/product/*.md`). If told "Read <path> and execute it", do exactly that.

## Shared memory — Second Brain (cross-tool handoff bus)

Claude, Claude Code, ChatGPT, Codex and Gemini share context through the **Second Brain** MCP server,
project slug **`novfora`**. If the Second Brain tools are available in this session:

- **Start:** `brief(project:"novfora")` + `recall(project:"novfora", query:<your task>)`. Entries tagged
  `start-here`, `locked-decision`, `hard-rule`, `gotcha`, and the latest `handoff` are the ones that matter.
- **Repo wins.** Second Brain is an index and handoff bus, not a replacement for repo docs. If a memory
  contradicts the repo, trust the repo and `append` a correction to the memory.
- **During:** store durable findings (decisions, gotchas, bugs found, owner product calls) as their own
  memories with `project:"novfora"`; `append` to an existing thread instead of duplicating it.
- **Before you stop:** store **one** memory tagged `handoff` + `agent:codex` with: goal · branch + head SHA ·
  what's done · what's next · gate status (Pest/Pint/Larastan/audit) · open questions · spec path.
- **Never** store secrets, API keys, passwords, hostnames/IPs, or `.env` values in Second Brain.

If the tools are not available, put the same handoff block at the end of your final message so the owner
can paste it in.

## Project in one screen

- **NovFora** — open-source (Apache-2.0), self-hosted forum platform. "Hearth"/"NevoBB" are retired codenames.
- **Stack (locked):** Laravel 13 / PHP 8.3, Livewire 4 + Alpine + Blade, server-rendered. MySQL 8 / MariaDB
  default, PostgreSQL on Docker/VPS. Vite; prebuilt assets ship (hosts need no Node).
- **Two tiers, one codebase:** *Baseline* = shared host, **cron only, no daemons**; *Enhanced* = Docker/VPS
  (Redis, Reverb, queue workers, Meilisearch, S3). **Progressive enhancement is a hard rule** — a Baseline
  hard-dependency on an Enhanced service is a defect.
- **Permissions:** ALLOW / NO / NEVER, deny-by-default, NEVER absolute, bans first, most-permissive group
  merge, rank guards. Engine: `app/Permissions/{PermissionResolver,RoleExpander,PermissionInspector}` over
  `acl_entries`. Spec: `docs/architecture/security-and-permissions.md`.

## Hard rules (never violate)

- **Strict clean-room:** never copy/adapt code, UI, templates, themes, branding or docs from any reference
  forum (SMF included). Read their DB/output structure **only** to build importers.
- **Reversible, non-destructive migrations**; guard every `Schema::create('t', …)` with
  `if (! Schema::hasTable('t'))`.
- **Security by default:** OWASP Top 10, parameterized Eloquent, argon2id, CSRF, strict CSP, rate limiting,
  audit logging, sanitized rich text.
- **Tests with every feature** (Pest; Dusk for browser). Permission resolution + tier fallbacks get dedicated
  tests. Not done without tests.
- **Ask the owner before:** destructive ops, stack-changing deps, ambiguous product calls, touching a locked
  decision. State reasonable assumptions inline.

## Effort & review discipline

Use your **strongest model / highest reasoning** for correctness-load-bearing work: anything touching
`acl_entries` / `PermissionResolver` / the inspector; concurrency & idempotency on the cron-only baseline;
untrusted-input boundaries (webhooks, VERP/DSN/ARF parsing, installer, CSP, restore/upgrade zips);
plugin/theme API design. These keep their tier even for cosmetic changes. Mechanical, pattern-replicating
work can go lighter. Every security/permission/concurrency slice needs an adversarial **verify-then-refute**
review with **0 open HIGH/MEDIUM before merge** — the review is the signal, not the green suite.

## Gates

Run `php artisan route:clear` first (a stale `bootstrap/cache/routes-v7.php` causes false subdir/PWA reds),
then Pest, Pint, Larastan, `composer audit`. Cap output with `tail -n N`. Write → run gate → read tail → fix.

## Git conventions (mandatory)

- Author **and** committer: `Tommy Huynh <tommy@saturnhq.net>` — set `git config user.name` /
  `user.email` before the first commit. Sign off with `-s` (DCO).
- **Never** add AI co-author or attribution trailers.
- One branch per slice off `main`; small conventional commits; non-obvious choices → ADR in `DECISIONS.md`.
- You will likely be **blocked from pushing to protected `main`** — end at "merged + tagged locally"; the
  owner pushes. That is expected, not a failure.

## Don't

- Don't purge the `⚡`-prefixed files under `resources/views/components/admin/` — they are the live ACP
  (Livewire 4 single-file components), not dead code.
- Don't rebuild OAuth/social login or StopForumSpam screening — both exist (`app/Auth/Social/`,
  `app/AntiSpam/`); harden edges only.
- Don't commit or ship `modules/novfora/populate` — it's a junction to a private repo and must be absent from
  every release zip.
- Don't give the owner PowerShell; give **bash** (WSL, repo at `/mnt/d/Forum`).
