<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Roadmap & Feature Inventory (snapshot 2026-06-22)

> **What this is.** A single, current, owner-facing snapshot of *what's been built across the whole project*, *what's
> next*, and *the major phases upcoming*. Reconciled against the actual `main` codebase + git history, not narrative.
>
> **Status.** **v1.0.0 GA shipped.** Local `main` (HEAD `6862775`) **≡ `origin/main`** — 0 ahead / 0 behind, no
> unmerged branches. ~553 commits, ~100 ADRs. Everything below "Completed" is verified reachable from `main`.
>
> **Companions:** `ROADMAP.md` (canonical phase table) · `docs/product/feature-list.md` (domain inventory + U-series) ·
> `docs/product/v1x-feature-program-kickoff.md` (the next program) · `docs/product/audit-ips-gap-analysis-2026-06-22.md`.

---

## 1. Where we are

NovFora is a self-hosted forum platform on a modern PHP stack (Laravel 13 / PHP 8.3 / Livewire 4), shipped to **1.0.0
GA**. Phases 0–5 are complete; the full **ACP v3** admin-and-permissions program (9 slices) is complete; the
**Design-Polish** program, **subdirectory install**, and a **demo-shakeout batch** are all merged. The build runs
green on the Baseline tier (PHP 8.3 + MySQL + cron) and is in a post-GA increment cycle.

The spine — permission engine, anti-spam, deliverability, extensibility, install/upgrade — is the strongest part of the
product and leads the incumbents (phpBB/MyBB/SMF/XenForo/Invision). The remaining work is **member-UX polish and
admin-surface breadth**, not core architecture.

---

## 2. Completed feature inventory (shipped to `main`)

Legend: ✅ shipped & verified · 🟡 working baseline, extension planned · ⚠ shipped but **validate against a live service
before relying on it**.

### Content & posting
- ✅ **WYSIWYG-first editor** (TipTap; canonical JSON → server-sanitised HTML).
- ✅ **Grouped editor toolbar** (Text-style / Insert menus, emoji picker, link dialog, smart paste) — *design-polish*.
- ✅ **First-class attachments / media upload** — drag-drop + paste, off-web-root storage, IDOR guard, hardened upload
  boundary (decompression-bomb fence, MIME allowlist, re-encode/EXIF strip) — *design-polish (apex)*.
- ✅ **Markdown mode**; **BBCode import/compatibility layer**.
- ✅ **Drafts** · **post scheduling** (cron-tolerant) · **edit history** · **spoiler / content-warning blocks** · **oEmbed**.
- 🟡 **Quoting** — plain blockquote only today → per-post quote/multi-quote is the **next** item (M1 / U1).

### Topics, forums & reading
- ✅ Categories → forums → topics → posts · **polls** · **tags / prefixes** · **bookmarks**.
- ✅ **Merge / split / move** topics · **read/unread tracking** + **What's New** · **trending / best-of** · related topics.
- ✅ **RSS/Atom** feeds · **sitemap** · **Info Center** (statistics + opt-in who's-online).

### Search
- ✅ **Scout DB search** with inline operators (`author:` `in:` `tag:` `after:` `before:` `type:`) + **saved searches**.
- ⚠ **Meilisearch** enhanced path (via Scout, DB fallback, no-leak re-gate).

### Members, profiles & social
- ✅ **Profiles + custom fields** · **avatars** (upload + initial fallback) + **cover photo** · **member directory** ·
  **leaderboard** (rep/posts; all-time/30d/7d).
- ✅ **Activity feeds** (incl. "Following") · **reactions** · **badges / reputation** · **trust levels**.
- ✅ **Follow** (user→user) + **block / ignore** · **staff flair + "The Team" roster** (*ACP v3-g*).

### Private messaging
- ✅ **Multi-participant PMs** — rate-limited, ignore-aware, group conversations.

### Permissions, groups & roles  *(the flagship)*
- ✅ **phpBB-grade permission engine** — ALLOW / NO / NEVER across **global → forum → thread → club** scope, role
  presets, group merge, and a **"why can/can't X" inspector** (CLI + ACP).
- ✅ **ACP v3 program — COMPLETE (all 9 slices):** TTL-expiry engine seam (v3-0) · Invision-style IA + **global ACP
  search** (v3-h) · card-per-group permission editor (v3-c) · group system — membership models + AND/OR auto-promotion +
  Groups directory (v3-e) · custom role builder (v3-d) · per-forum moderator assignment (v3-b) · **co-owners + Admin
  Manager** + per-section bundles (v3-a) · **temporary-access delegation** (v3-f) · staff flair + roster (v3-g).

### Moderation & safety
- ✅ **Moderation queue** · **inline + bulk moderation** · **reports** · **soft-delete**.
- ✅ **Warnings / infractions** — points, decay, auto-consequences, acknowledgement *(engine shipped; ACP surface is
  the **next** A3 item)*.
- ✅ **Audit log** (surfaced on the ACP dashboard).

### Anti-spam
- ✅ Trust-levels ↔ ACL · **StopForumSpam** · **CAPTCHA abstraction + Turnstile + Q&A** · honeypot · rate limits.
- ✅ **HOLD-only spam intelligence** (similarity / burst / reputation) + false-positive guards + staff review surface +
  content-privacy fence.

### Admin / ACP
- ✅ **Invision-style IA** (icon rail → sections → dashboards) · **global search** · **system-health dashboard**
  (DB/cache/queue/schema/backup + pending queue + open reports + recent audit).
- ✅ **Persistent-shell nav** + section breadcrumbs + quick-search + recents (*design-polish*) · **x-ui.\* component
  library** (data table, skeleton/empty/loading states, motion tokens) · settings registry · privacy-conscious analytics.

### Theming & layout
- ✅ **Theme Studio** — visual token editor (AA-checked + dark palette), filesystem child themes, per-theme
  logo/favicon/background, layout regions + widgets, sanitised custom header/footer.
- ✅ **Sandboxed template editor** — data-only lexer/parser/evaluator (never raw Blade/PHP; threat model documented).

### Extensibility & integration
- ✅ **Module / plugin API** — semver'd, event/filter/slot hooks, manifest + lifecycle, **no core edits**.
- ✅ **REST API** (`/api/v1`, hashed tokens, engine-authorized) + **outbound webhooks** (HMAC, SSRF-guarded).
- ✅ **Importers** — phpBB / MyBB / SMF / XenForo (idempotent, attachment-verified, 301 redirects).

### Clubs · memberships · SSO · real-time · PWA
- ✅ **Clubs** (sub-communities, club-scoped permissions, membership flows, no-leak privacy fence).
- ⚠ **Paid memberships** — tiers + perk gating through the engine; **manual provider = live-granting path**; **Stripe
  hosted checkout shipped with charging DISABLED**.
- ⚠ **SSO** — OAuth (Google/GitHub/Discord), SAML scaffold, staff-2FA step-up, account linking.
- ⚠ **Real-time** — Reverb broadcasting + channel-authz no-leak fence + opt-in presence + polling fallback.
- ⚠ **PWA + Web Push** — installable, subpath-aware service worker, VAPID push.

### i18n · accessibility · SEO
- ✅ **i18n framework** + RTL + locale switch + per-key `en` fallback + **`es` proof locale** + partial sweeps.
- ✅ **WCAG 2.1 AA** automated gate across **27 surfaces** + manual checklist.
- ✅ **SEO basics** (sitemap, meta, canonical).

### Install, upgrade & ops
- ✅ **No-SSH web installer** (tier detection, setup token) · **reversible migrations** · cron-driven **auto-upgrade**
  (backup-first, maintenance-gated) · **portable backup/restore**.
- ✅ **First-class subdirectory install** (canonical home at mount root) · **fresh-install path proven** (empty DB → GA).

---

## 3. Phase timeline (completed)

| Phase | Theme | Headline deliverables | Status |
|---|---|---|---|
| **0** | Discovery | Research, architecture, data model, ADR log, governance, MVP definition | ✅ |
| **1** | Core MVP | Permission engine · no-SSH installer · WYSIWYG · forum CRUD · anti-spam · notifications · DB search | ✅ |
| **2** | Community | Reactions · profiles · PMs · trust levels · warnings · activity feeds · inline/bulk moderation → **Public Beta** | ✅ |
| **3** | Extensibility | Module/plugin API · REST + webhooks · phpBB/MyBB/SMF importers · Theme Studio · analytics (ADR-31…35) | ✅ |
| **4** | Advanced | Clubs · SSO · PWA + Push · Enhanced tier (Meili/Reverb) · paid memberships · advanced anti-spam · XenForo importer (ADR-41…69) | ✅ |
| **5** | Hardening → GA | Security review · WCAG AA · i18n · perf gate · 1.0.0 release · fresh-install (ADR-72…76) | ✅ |
| **★** | **v1.0.0 GA** | + ACP v3 (9 slices) · Design-Polish · subdirectory install · demo-shakeout batch | ✅ **current** |

---

## 4. What's next — the v1.x Feature Program

The immediate next program (spec'd as an executable cold-start kickoff). Five tracks, independent branch per slice off
`main`, gated green, owner reviews & merges. Build order: **R → S → A1→A2→A3 → M1 → M2 → M3 → T1 → T3 → T2.**

| Track | Slice | What | Rung | Notes |
|---|---|---|---|---|
| **R** Hygiene | R1, R2 | Trim session-loaded docs; archive spent kickoffs | Sonnet | Run first; reversible |
| **S** Stabilize | S1 | Attachment hardening follow-through (scheduled-orphan prune) | Opus xhigh | ◆ apex-adjacent |
| | S2 | Polish Dusk specs green in CI | Sonnet | |
| | ~~S3, S4~~ | ~~Lift ADRs; ACP groups action-icons~~ | — | ✅ already on `main` |
| | **S5** | **Owner-strand guard** on ban/warn paths | Fable-max | ◆ **apex · security must-fix** (pre-existing HIGH) |
| **A** ACP v4 | **A1** | **In-admin member table** (paginated/sortable/filterable) | Fable-max | ◆ apex · ★ audits' #1 admin gap |
| | A2 | Per-member admin view (ban/warn/groups/IP) | Fable-max | ◆ apex · clears the v3-b deferral |
| | A3 | Warnings surfaced in the ACP | Opus high | surface existing engine |
| **M** Member-UX | **M1** | **Per-post quote-reply** | Opus high | ★ audits' "essential" |
| | M2 | Topic/forum follow-subscribe | Fable-max | ◆ apex (notification fan-out) |
| | M3 | Finish unread indicator / first-post excerpt / topic slug | Opus high | |
| **T** Tooling | T1, T3, T2 | Canned replies · analytics charts · email templates | Sonnet/Opus | T2 ◆ apex (render sandbox); ship last |

**These map to three post-GA versions:** **1.1** member-experience completion (Track M) · **1.2** ACP v4 member
management (Track A) · **1.3** admin content & insight tooling (Track T). 1.1 and 1.2 can run in parallel (different
surfaces, different model rungs). **Highest leverage:** **A1** (admin) and **M1** (member). **Land S5 before any
member-management code reaches the demo.**

---

## 5. Upcoming major phases

### Phase 6 — Post-GA "U-series" backlog (the broader horizon)
The full reconciled backlog from the external reviews (`feature-list.md` Part 2). The v1.x program above is the
highest-priority subset pulled forward; the rest is Phase 6:

- **Core parity:** follow forums/tags + watched content (U2) · profile wall / status posts (U3) · announcements/notices
  (U4) · admin-editable nav manager (U5) · front-of-site moderator toolset + stored replies (U6) · username history (U8).
- **Net-new (apex):** Embed API / SSI / web components for external sites (U7).
- **Theming depth (on the sandbox):** rich style-property system (U9) · multi-style tree + user chooser (U10) ·
  upgrade-safe template hook + Diff3 merge (U11, apex) · style import/export + global CSS box (U12).
- **Admin / ops & SEO:** IP investigation + ban UI (U13) · registration controls / approval queue (U14) · mass member
  ops + bulk-mail (U15, apex) · maintenance/logs/mail-test UI (U16) · plugin install-from-zip + signature gate (U17,
  apex) · finish CAPTCHA drivers + Gravatar (U18) · custom topic fields + move-with-redirect (U19) · SEO polish —
  JSON-LD/OG/social share (U20) · finish i18n string sweep (U21).

### Go-live — validate-before-go-live → production launch
Several Phase 4/5 features ship **scaffolded / disabled-by-default** (unit-tested against fakes only). A default
baseline deploy uses none of them. Before relying on each, validate against the real service: **Meilisearch · Reverb
realtime · live Stripe payments · OAuth/SAML providers · Web Push delivery · StopForumSpam submission · at-scale load
test · the residual manual a11y checklist.** Then reinstall fresh on the new production host/domain (the proven
fresh-install path) and cut the 1.0 tag. *(Enhanced-tier items 1–2 — Meilisearch + Reverb + Redis — were already
validated live on the build VPS.)*

---

## 6. Intentionally deferred (out of scope for 1.x)

Kept architecturally possible, not built now:

- **Suite apps as first-party/community modules** (not core weight): Media Gallery · Blog · Calendar/Events ·
  Downloads/Resource Manager · Pages/CMS · Commerce/Store.
- **GraphQL API** · advertising / promo-slot system · post-by-email replies.
- **Multi-tenant SaaS** (data-model seam kept) · native mobile apps (PWA instead) · in-core chat bridges.

---

*Snapshot generated 2026-06-22 from `main` @ `6862775`. For the live source of truth, see `PROJECT-STATE.md` (current
handoff) and `ROADMAP.md` (canonical phase table).*
