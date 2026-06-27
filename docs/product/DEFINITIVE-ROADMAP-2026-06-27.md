<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Definitive Roadmap (aggregated, 2026-06-27)

> **What this is.** One reconciled roadmap aggregating the four multi-agent workflow briefs (GPT, Gemini, Claude,
> Manus) against the **actual `main`** (`9c5586d` ≡ `origin/main`). Supersedes the scattered handoffs as the single
> forward plan. Companion source docs: `GPT_UIUX_HANDOFF.md`, `NovFora_MultiAgent_Workflow.md`,
> `NOVFORA-multi_sonnet.md`, `UI-AUDIT-FIX-SPEC.md`, `PROJECT-STATE.md`, `ROADMAP.md`.

## Status snapshot

- **v1.0.0 GA** shipped. `main` (`9c5586d`) **≡ `origin/main`** — synced.
- **v1.x Feature Program — DONE & pushed** (merged at `4b4e64b`): member table (A1), per-member admin (A2),
  warnings-in-ACP (A3), **S5 owner-strand guard** (apex, GO/0 HIGH), quote-reply (M1), subscriptions (M2),
  unread/excerpt/slug (M3), canned replies (T1), analytics charts (T3), email templates (T2), R1/R2 hygiene, S1/S2.
- **v1.x Polish-2 + a11y — DONE & pushed** (merged at `37c9cb8`, 2128 tests): F1 ACP recents removal, F3 mod-CP width,
  F5 Info Center, F6 latest-activity, F4 report-review, **F2 manual trust + reputation** (apex, ADR-0101), P1
  design-critique, P2 a11y→AA (gate 27→30 surfaces).
- **Brand applied — DONE & pushed**: "Indie Web Hearth + Nova" brand kit + theme (PR #48).
- **Open / in-flight:** UI-AUDIT-FIX-SPEC (21 diagnosed bugs), live beta bugs, the UI/UX polish program, Phase 6.
- **Open PR:** **#49 (Codex)** — a UI-audit fix batch; review/merge on the owner's machine (not accessible from Cowork).

---

## 1. Multi-agent execution model (aggregated from all four briefs)

The four briefs agree on a tool-responsibility split. This is the operating model for the active UI/UX program.

| Agent | Role |
|---|---|
| **Claude Code** | Architecture, repo-aware planning, sweeping refactors, permission contracts, apex/security & backend-aligned UX. Owns the correctness-load-bearing slices. |
| **Cursor** | Hands-on Blade/Tailwind/Livewire polish, component refinement, visual iteration (human-reviewed). |
| **Codex** | Background PR batches for the UI-audit fixes; PR review; tests; a11y/security checks. (PR #49 is a Codex batch.) |
| **Antigravity** | Isolated UI prototypes + browser QA (screenshots, **390px** mobile verification); tiered-deploy testing (Baseline vs Enhanced). |
| **Manus** | Competitor UX research, UX copy/microcopy, doc bootstrapping (e.g. migration guides). |

**Core design direction (locked):** *Warm indie web + premium SaaS clarity + classic forum density.* Avoid generic
Bootstrap/starter-kit visuals, old phpBB/MyBB/SMF styling, or a Discourse clone.

**Working rule (locked):** no tool redesigns the UI in isolation — the frontend always respects the backend
authorization model, real data availability, theme/sandbox hooks, accessibility, shared-host compatibility, and the
existing Blade/Livewire/Tailwind component system. Reuse `x-ui.*`; never fake metrics or permissions; clean-room.

---

## 2. ACTIVE — UI/UX Audit, Beta Fixes & Polish (the current program)

The everyday-forum and admin surfaces, grounded in the live beta at novfora.com/community. **Tracks below; build order
top-to-bottom.** Apex/correctness items flagged ◆.

### Track UA — UI-AUDIT-FIX-SPEC.md (21 diagnosed bugs; root-caused with file:line)
Execute in the spec's slice order. Some may be covered by the open Codex **PR #49** — reconcile on merge.

| ID | Bug | Priority | Rung |
|---|---|---|---|
| BUG-001 | Admin section landing emits a bare `<svg>` gear (no `@extends`) — **blocks all admin nav** | **P0** | Sonnet |
| BUG-004/005 | Structure title `&amp;` literal + breadcrumb `Content`→`Forums` | High | Sonnet |
| BUG-013/021 | Breadcrumb literal cluster — 9 edits across 8 view files | High | Sonnet |
| BUG-007/008/015 | Pluralization sweep (topics/posts/views/reports → `trans_choice`) | Med | Sonnet · *likely PR #49* |
| BUG-010 | Presence/privacy: Who's-Online badge vs count mismatch (privacy leak) | High | ◆ Opus |
| BUG-011/009 | Seed-data artifacts: post_count backfill + importer view_count hardening | Med | Sonnet |
| BUG-002/003 | Dual route binding — forum slug + user username (non-breaking, no 301s) | High | ◆ Opus |
| BUG-012/020 | Activity-feed limit setting + RecentActivityWidget | Med | Sonnet |
| BUG-016 | Settings 10-tab wrap → left sidebar nav | Med | Sonnet |
| BUG-017/018 | Profile tabs (Activity/Posts/About) + collapse staff-tools | Med | Sonnet/◆Opus (posts query) |
| BUG-019 | Display-name field (username stays read-only) | Med | Sonnet · *likely PR #49* |
| BUG-014 | Draft banner on blank editor (reproduce first) | Med | ◆ Opus |
| BUG-006 | **Reclassified — NOT a defect.** Do not touch reaction-count logic. | — | — |

### Track BETA — Live beta-tester bugs (novfora.com/community)
| Item | Bug | Priority | Rung |
|---|---|---|---|
| BETA-1 | Notification read-state doesn't auto-update (topic #31) | High | ◆ Opus (Livewire reactivity) |
| BETA-2 | Mobile portrait nav spillover / second row (topic #21, regression) | High | Sonnet |
| BETA-3 | DM 403 error | High | ◆ Opus (permission) |
| BETA-4 | Lock-thread action shown to users without permission (ghost UI) | High | ◆ Opus (gate) |
| BETA-5 | Scheduled-reply error | Med | Opus |

### Track UX — Polish & redesign (Cursor-led, Claude for permission-scoped parts)
- **UX-1 Forum index modernization** — card-based board layout in the existing token system (view-layer only).
- **UX-2 Profile page redesign** — tabbed (Activity/Posts/About), hero stats; posts query is ◆ permission-scoped.
- **UX-3 Nav/search polish** — premium header, consolidate duplicate links, mobile menu, search discoverability.
- **UX-4 Topic-list & thread-view polish** — scan-ability, prefixes/tags, post-card layout, signed-out CTA.
- **UX-5 Auth/register polish**; **UX-6 component-system refinement**; **UX-7 admin dashboard MVP polish**.
- **Permission-aware UI contract** (◆ Claude Code) — every action: show / show-disabled-with-reason / sign-in CTA /
  hide / friendly-403. Prevents ghost UI (covers BETA-4) and avoidable 403s (covers BETA-3).

**Sequencing:** **BUG-001 (P0) first** → BETA-2 mobile nav (regression, affects every page) → the UA breadcrumb/
pluralization cluster (or accept PR #49) → BUG-010/002/003/014 (◆) → BETA-1/3/4 → UX polish (index → profile → nav →
thread). Deploy to demo backup-first after each meaningful batch.

---

## 3. HORIZON — Phase 6 / "U-series" (21 features, 4 tiers)

The strategic post-GA feature backlog (Manus synthesis). Run after the active program stabilizes the surfaces.

**Quick wins (S):** **U8** username history + revert · **U18** finish CAPTCHA drivers (hCaptcha/reCAPTCHA) + Gravatar ·
**U20** SEO polish (JSON-LD/OG, social share, "find content by user").

**Tier 1 — real core gaps (M–L):** **U1** quoting & multi-quote · **U2** follow forums/tags + watched-content surface ·
**U4** announcements/notices · **U5** admin nav/menu manager · **U6** front-of-site mod toolset + stored replies ·
**U3** profile wall / status posts.

**Tier 2 — net-new:** **U7** Embed API / SSI / web components ◆ APEX.

**Tier 3 — theming depth:** **U9** rich style-property system · **U10** multi-style tree + user chooser · **U11**
upgrade-safe template hook + Diff3 merge ◆ APEX · **U12** style import/export + global CSS box.

**Tier 4 — admin/ops/SEO breadth:** **U13** IP investigation + ban-management UI · **U14** registration controls
(approval queue, ToS/age gate, domain allow/deny) · **U15** mass member ops + bulk-mail ◆ APEX · **U16**
maintenance/logs/mail-test UI · **U17** plugin install-from-zip + signature gate ◆ APEX · **U19** custom topic fields +
move-with-redirect · **U21** finish the i18n string sweep.

**Suggested sequence:** quick wins (U8·U18·U20) → Tier 1 (U1→U2→U4→U5→U6→U3) → U7 Embed/SSI → Tier 3 theming
(U9→U10→U11→U12) → Tier 4 (U13/U14 → U15 → U16 → U17 → U19 → U21).

---

## 4. Go-live gate (carried)

Validate against live services before relying on each: **Meilisearch · Reverb · live Stripe · OAuth/SAML · Web Push ·
StopForumSpam submission · at-scale load · manual a11y residue** → deploy demo backup-first (cron auto-upgrade path,
since recent batches carry migrations) → reinstall fresh on the production host → cut the 1.0 tag. (Enhanced-tier
Meili + Reverb + Redis already validated live on the build VPS.)

---

## 5. Intentionally deferred (out of scope for 1.x)

Suite apps as modules (Media/Blog/Calendar/Downloads/Pages/Commerce) · GraphQL API · advertising slots ·
post-by-email · multi-tenant SaaS (data seam kept) · native mobile apps (PWA instead) · in-core chat bridges.

---

*Aggregated 2026-06-27 from the four agent briefs + verified `main` @ `9c5586d`. Mirrored to Linear (team NovFora):
v1.x → Done; "UI/UX" project = the active program (Tracks UA/BETA/UX); "Phase 6 — U-series" project = the horizon.*
