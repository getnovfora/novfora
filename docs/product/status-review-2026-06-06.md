<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Status Review & Updated Roadmap (2026-06-06)

> Owner-facing retrospective + forward plan, written while the theme phase runs. Canonical phase detail
> stays in [roadmap.md](roadmap.md) / [ROADMAP.md](../../ROADMAP.md); the live status ledger stays in
> [PROJECT-STATE.md](../../PROJECT-STATE.md). This document is the narrative: where we are, how we got
> here, what fought back, and the resequenced path to "the best forum software."

---

## 1. Where we are — one paragraph

**NovFora is a real, installed, working forum on a real $5 shared host.** The riskiest promises of the
brief are proven, not claimed: one codebase detected and adapted to a baseline cPanel host (PHP 8.4 +
MySQL + a single cron line), a no-SSH web installer took a non-technical path from "uploaded a zip" to a
seeded community, and the engine underneath — phpBB-grade permission masks, WYSIWYG editor, anti-spam,
search, backups, queued email — passed both CI and live-host validation. Nine real-host findings
(RH-1…RH-9) were found, root-caused, fixed, and turned into permanent regression guards. What NovFora does
not yet have is its face (the default theme is placeholder-grade — the theme phase is in flight right
now), its community depth (Phase 2), or its ecosystem (Phase 3).

**Snapshot:** Phase 1 (Core MVP) ✅ complete & live-validated · Phase 1.5 (security) ✅ · Real-host
validation ✅ (RH-4 subdirectory remains open by design) · Hygiene/CI guards ✅ · **Theme phase ▶ in
flight** · Phases 2–5 ahead. Suite: **Pest 333 green / Dusk 2 journeys (incl. installer under real
enforcement) / Pint / Larastan / audit clean**. Live: `nevo.adorablespider.com` (installed, cron
running, health green).

## 2. What we accomplished

- **Phase 0 — Discovery.** Full research/architecture/product doc set, 20+ ADRs, MoSCoW prioritization,
  MVP scope, clean-room rules. Locked stack: Laravel 13 / Livewire 4 / Alpine / Blade, PHP 8.3 floor,
  MySQL baseline, two tiers from one codebase.
- **Spike 0.** De-risked the #1 technical risk first: TipTap WYSIWYG inside Livewire via the
  `wire:ignore` + Alpine-island pattern — validated before any feature code.
- **M0–M5 (Phase 1 build).** Scaffold + tier detection → auth/profiles → permission-mask engine (the
  ALLOW/NO/NEVER tri-state, NO=neutral per ADR) → forums/topics/posts + moderation + recycle bin + audit
  log → editor + attachments → anti-spam baseline (blocklists, CAPTCHA abstraction, trust gating,
  moderation queue) → Scout search, SEO, backups/restore, health endpoint, **no-SSH installer** (web +
  CLI sharing one runner), reversible migrations throughout.
- **Phase 1.5 — security pass.** All nine flagged items fixed before any beta exposure: setup-token gate
  on the installer, strict nonce CSP (toggleable), argon2id, mass-assignment guards, rank checks,
  auth-event audit logging, and more.
- **Release engineering.** A deployable zip a shared-host user can extract with **zero commands**:
  `build-release.sh` (ships the package manifest — RH-1) + `verify-release.sh` (true cold-HTTP-boot
  acceptance: fresh extract, empty key, no DB → `302 → /install`).
- **Real-host validation (the decisive phase).** Deployed to actual cPanel/CloudLinux/LiteSpeed and
  fixed what no container could surface — nine findings, every one now guarded by a test or CI step
  (§3). Outcome: **full wizard install completed live; forum browsing, health, cron, queue all green.**
- **Project infrastructure.** Private GitHub repo (`echo5tech/novfora`) as single source of truth;
  authorship policy (sole-author history, AI attribution off, enforced via CLAUDE.md + committed
  settings); reproducible dev environments for macOS + Ubuntu (one-command setup scripts + Docker MySQL);
  CI: lint/static analysis, Pest on MySQL, asset budget + **assets-fresh drift guard**, **two-pass Dusk**
  (installer under real enforcement + editor journey); PR-based delivery with review gates.
- **In flight now:** the default theme phase — indigo/slate, light+dark, comfortable+compact density,
  classic-forum structure with SaaS polish — delivered as a PR with screenshot review gates.

## 3. Roadblocks — and what each one became

The pattern worth noticing: **every blocker ended as a permanent guard.** The same failure cannot happen
twice silently.

| Roadblock | Root cause | What it became |
|---|---|---|
| **RH-1** "Target class [view] does not exist" on first boot | bundle built `--no-scripts` shipped no package manifest; the old verify masked it by running artisan first | manifest ships; **cold-boot verify** that can never be masked |
| **RH-2** 500s on CloudLinux | 0777 files from panel extraction vs suEXEC strictness | `novfora:doctor` check + runbook step |
| **RH-6 → RH-7** "Continue does nothing" (the long one) | misdiagnosed as front-end boot timing; live-browser replay proved the truth: Livewire 4's **hashed endpoint** (`livewire-<hash>/update`) missed the install-enforce allowlist → every wizard POST 302'd to HTML | hash-agnostic allowlist + runtime-derived path; **enforcement-ON tests against the real middleware stack** (the suite had been blind: enforce-off + `Livewire::test()` bypasses middleware) |
| **RH-8** root URL showed Laravel's welcome page | scaffold route never replaced; invisible pre-install; no test asserted `/` | `/` → community home; root-route test; **public-route smoke test** |
| **RH-9** `/forums` 500 every other minute | P1.5 hardening (`serializable_classes => false`) × the one place caching **live Eloquent objects** → `__PHP_Incomplete_Class` on every cache hit; invisible because tests use the array store (never serializes) | cache primitives only + rehydrate; repo-wide cache-write sweep; **serializing-store cache-HIT tests** |
| **RH-5 + PR #2** asset drift & CI red | committed CSS stale; deeper: the build was **machine-state-dependent** (Tailwind `@source` scanning `vendor/` and compiled views) | canonical rebuild + **assets-fresh CI guard** + deterministic build inputs (theme phase finishes the job: kills the compiled-views source + the CDN font fetch) |
| **RH-4** subdirectory installs broken | dual-`public/` drift + base-path URL generation + storage publish target | **open by design** — needs a design spike + ADR, not a patch (next after theme) |
| **Workflow: NTFS/drvfs** | Windows ACLs/locks corrupted merges on `/mnt/d`; a branch-delete also killed the local tracking ref mid-rescue | standing rule: **history surgery in WSL-native clones or via server-side PR merges**; D:\Forum is a read/sync mirror |
| **Workflow: AI authorship** | web sandbox committed as `Claude <noreply@anthropic.com>`, re-adding the contributor we'd scrubbed | history rewritten (sole-author); **CLAUDE.md commit-identity mandate** + attribution-off settings committed — held on every run since |
| **Test-environment blindness (the meta-lesson)** | three separate classes: enforcement off, array cache store, vendor-less builds — each made CI green while production broke | the recurring question is now institutional: *"what does the test environment fake?"* — and each answer became a like-for-production test |

## 4. Scoreboard vs. the brief's promises

| Brief differentiator | Status |
|---|---|
| Installs on a commodity shared host, no SSH, two tiers/one codebase | ✅ **proven live** |
| Painless ops: one cron line, automated backups + restore, health endpoint, reversible migrations | ✅ live (cron running, queue draining) |
| phpBB-grade permissions (mask engine) | ✅ shipped + dedicated suite |
| WYSIWYG-first editor (TipTap, canonical storage) | ✅ shipped + browser-tested |
| First-class anti-spam | ✅ baseline shipped (intelligence layer = Phase 4) |
| Security by default | ✅ P1.5 pass complete; independent review reserved for Phase 5 |
| Modern, polished UX | ▶ **theme phase in flight** — the visible gap |
| Community depth (reactions, PMs, warnings-decay, feeds…) | ⏭ Phase 2 |
| Extensibility without forking (plugins, theme configurator, API) + **importers** | ⏭ Phase 3 — the adoption moat |
| Enhanced tier lit up (Redis/Meilisearch/Reverb/S3) | ⏭ Phase 4 (drivers + graceful degradation already built) |

## 5. Updated roadmap — Now / Next / Later

*(Phases per [roadmap.md](roadmap.md); this is the owner-approved resequencing: the theme was pulled
forward ahead of Phase 2 because first impressions now gate everything — beta invites, screenshots,
word of mouth.)*

**NOW — in flight**
1. **Default theme / UI polish** (PR + screenshot gate) → merge → **deploy the themed bundle live**.
   Includes the build-determinism finishers (deliberate `@source` set; drop the CDN font fetch).

**NEXT — the path to Private Beta**
2. **RH-4: subdirectory install** — design spike → ADR → implement + install-matrix test. (Owner-flagged
   end-user must-have.)
3. **Beta-readiness slice:** outbound email deliverability sanity on the live host (registration verify +
   notification emails actually arriving; suppression handling), a feedback channel, and a themed demo
   community worth screenshotting.
4. 🚩 **Private Beta gate** — invite a handful of real communities/users onto `0.9`. Entry criteria:
   theme merged + deployed · RH-4 closed · email verified working · backups restore-tested once on the
   live host.

**THEN — Phase 2: Community** *(engagement + moderation depth; full list in roadmap.md)*
Reactions · custom profile fields · multi-participant PMs · rich + digest notifications with bounce/
suppression hygiene · warnings with decay/auto-consequences/ack · trust-level promotion · activity feeds ·
inline + bulk moderation · Markdown mode · oEmbed · drafts/autosave · edit history with diffs.
→ 🚩 **Public Beta** when Phase 2 core lands and private-beta feedback is folded in.

**THEN — Phase 3: Extensibility** *(the moat — customization without forking + the migration on-ramp)*
Module/plugin API + hooks/events/UI-slots (semver'd) with pre-upgrade compatibility check · visual theme
configurator atop the override layer · versioned REST API + webhooks · **resumable phpBB/MyBB/SMF
importers** (dry-run → verify → 301 maps; password rehash-on-login) · admin analytics.
*Importers are the single biggest adoption lever for "best forum software ever" — every aging phpBB board
is a prospective NovFora community, and clean-room importers are our bridge.*

**THEN — Phase 4: Advanced / competitive**
SSO (OAuth2/OIDC/SAML, forum-as-provider) · paid memberships/subscriptions · Clubs · advanced anti-spam
intelligence · Meilisearch + Reverb real-time on the enhanced tier · PWA + push · XenForo importer
(stretch).

**THEN — Phase 5: Hardening → 1.0**
Independent security review/pen-test · WCAG 2.1 AA completeness · i18n/RTL completeness · load testing
against the performance budgets on both tiers · docs completeness · 1.0 release + upgrade rehearsal.

**Continuous (no phase):** real-host re-validation on each significant deploy · the always-runnable
baseline rule · clean-room discipline · PROJECT-STATE as the living ledger · second/third dev machines
(MacBook + Linux laptop) via `scripts/dev-setup-*.sh` whenever convenient.

## 6. What "best forum software ever" means — measurable

1. **Install:** zip → running community in **under 10 minutes** on a $5 shared host, zero commands —
   *already true; keep it true every release.*
2. **Escape hatch:** a 10-year-old phpBB/MyBB/SMF board imports with verified fidelity and working 301s.
3. **Feel:** UX a Discourse user calls modern *and* structure a phpBB veteran navigates without thinking;
   AA-accessible, fast on a phone, light+dark.
4. **Trust:** spam dies at the door; upgrades never require manual DB surgery; backups restore; security
   posture independently reviewed.
5. **Ecosystem:** plugins + themes against a semver'd contract that upgrades don't break.
6. **Honest openness:** Apache-2.0, no feature ransom, self-hosted data ownership — the polish of the
   commercial suites without their lock-in.

---

*Next review checkpoint: after the theme PR merges + the themed live deploy (update §1 snapshot and tick
roadmap item 1).*
