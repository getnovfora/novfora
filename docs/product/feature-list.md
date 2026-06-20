<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# NovFora — Feature List & Completion Status

> **Updated:** 2026-06-18 · **Companion to:** `ROADMAP.md` (Phase 6), `docs/product/reevaluation-synthesis.md`
> (the three-way review reconciliation), `docs/product/reevaluation-scorecard.html` (interactive).
> **What this is:** the authoritative, domain-by-domain inventory of NovFora — **what's shipped, what's partial,
> and what's planned** — reconciled against the actual v1.0.0 GA codebase (verified read-only, not from review claims).

## Legend

- ✅ **Shipped** — in v1.0.0 GA or a merged post-GA branch; verified in code.
- 🟡 **Partial** — a working baseline exists; the listed extension is planned.
- 🔜 **Planned** — Post-GA `U`-series (see Phase 6 / synthesis). Not built yet.
- ⛔ **Deferred** — intentionally out of scope for now; lands later as a module.
- **APEX** — permission / untrusted-input / concurrency surface → apex rung + verify-then-refute before merge.

Effort: **S** ≤2d · **M** ≤1wk · **L** 1–3wk · **XL** >3wk.

---

# Part 1 — What's already completed (shipped in v1.0.0 + post-GA merges)

The spine — permissions, anti-spam, deliverability, extensibility, install/upgrade — is **best-in-class and leads the
incumbents**. The reconciliation also confirmed several items the external reviews wanted built are **already done**.

## Content & posting
- ✅ **WYSIWYG-first editor** (TipTap; canonical JSON → server-sanitised HTML via `ContentSanitizer`).
- ✅ **Markdown mode**; **BBCode import/compatibility layer** (not the primary authoring format).
- ✅ **First-class attachments / media upload** — `attachments` table, `AttachmentController`, `AttachmentService`,
  drag-drop/paste in the editor, off-web-root storage, IDOR guard. *(Reviews claimed missing — it's built.)*
- ✅ **Drafts** + **post scheduling** (cron-tolerant) + **edit history** + **spoiler / content-warning blocks**.
- ✅ **oEmbed** media embedding (trusted-provider list).
- 🟡 **Quoting** — only a plain blockquote button today → **U1** adds per-post quote, attribution/backlink, multi-quote.

## Topics, forums & reading
- ✅ Categories → forums → topics → posts; **polls**; **tags / prefixes**; **bookmarks**.
- ✅ **Merge / split** topics; **move** topic (between forums).
- ✅ **Read / unread tracking** — `topic_reads` watermark + **What's New** (`WhatsNewController`). *(Claimed missing — built.)*
- ✅ **Trending / best-of**, **related-topic recommendations**, **RSS/Atom** feeds, **sitemap**.
- ✅ **Info Center** (statistics + opt-in who's-online) on the board index.
- 🟡 **Move-with-301-redirect** — `moved_to_topic_id` seam exists but is unused → **U19** wires it.
- 🔜 **Custom thread/topic fields** (per-forum) — **U19**.

## Search
- ✅ **Scout DB search** with inline operators (`author:`/`in:`/`tag:`/`after:`/`before:`/`type:`) + **saved searches**.
- ✅ **Meilisearch** path (enhanced tier, via Scout, DB fallback, no-leak re-gate). *(⚠ validate against a live instance.)*

## Members, profiles & social
- ✅ **Profiles + custom profile fields**; **avatars** (upload + letter/initial fallback) + **cover/banner photo**.
- ✅ **Member directory** + **leaderboard** (rep/posts; all-time/30d/7d). *(Leaderboard claimed missing — built.)*
- ✅ **Activity feeds** (incl. a "Following" tab); **reactions**; **badges / reputation**; **trust levels**.
- ✅ **Follow** (user → user) + **block / ignore**.
- 🟡 **Following forums/tags + a "Watched/Followed content" surface** → **U2**.
- 🔜 **Profile wall / status posts + comments** — **U3**. **Username history + revert** — **U8**. **Gravatar** — **U18**.
- 🔜 **"Find more content by this user"** surface — **U20**.

## Private messaging
- ✅ **Multi-participant PMs**, rate-limited, ignore-aware, group conversations.
- ⛔ PM folders / labels / star / in-PM search / export — deferred (nice-to-have).

## Permissions, groups & roles
- ✅ **phpBB-grade permission engine** — ALLOW / NO / NEVER, global→forum→thread + **club** scope, role presets, group
  merge, and a **"why can/can't X" inspector** (CLI `novfora:why` + ACP Livewire). *(Inspector claimed missing — built.)*
- ✅ **ACP v3 program** (on the engine): **v3-0** TTL-expiry seam · **v3-h** Invision-style IA + **global ACP search** ·
  **v3-c** card-per-group permission editor · **v3-e** group system (membership models + AND/OR auto-promotion +
  Groups directory) · **v3-d** custom role builder (ADR-0084). *(ACP global search claimed missing — built.)*
- 🔜 ACP v3 remaining: **v3-b** per-forum moderator assignment · **v3-a** · **v3-f** TTL delegation · **v3-g**.

## Moderation & safety
- ✅ **Moderation queue**; **inline + bulk moderation** (`BulkModerationService`); **reports**; **soft-delete**.
- ✅ **Warnings / infractions** — points, decay, auto-consequences, acknowledgement. *(All claimed as to-build — built.)*
- ✅ **Audit log** (`AuditLog`, surfaced on the ACP dashboard). *(Reviews proposed a new dep for this — not needed.)*
- 🟡 **Front-of-site moderator toolset** (public inline menu + stored replies) → **U6**.
- 🟡 **IP investigation + ban-management UI** (CIDR/range, IP history) → **U13**; **registration controls** → **U14**.

## Anti-spam
- ✅ Trust-levels ↔ ACL, **StopForumSpam**, **CAPTCHA abstraction + Turnstile driver + Q&A**, honeypot, rate limits.
- ✅ **HOLD-only spam intelligence** (similarity/burst/reputation) + FP guards + staff review surface + content-privacy fence.
- 🔜 **hCaptcha + reCAPTCHA** drivers (alongside Turnstile) — **U18**.

## Admin / ACP
- ✅ **Invision-style IA** (icon rail → sections → dashboards), **global search**, **system-health dashboard** (DB/cache/
  queue/schema/backup status + pending-queue + open-reports + recent audit). *(Dashboard claimed as to-build — built.)*
- ✅ **Settings registry**; **privacy-conscious analytics**.
- 🟡 **Maintenance/rebuild tools UI** + broader **log surfaces** + **mail test** → **U16**.

## Theming & layout
- ✅ **Theme Studio** — visual token editor (AA-checked, ~7 tokens + dark palette), **filesystem child themes**,
  per-theme logo/favicon/background, layout regions + widgets, sanitised custom header/footer.
- ✅ **Sandboxed template editor** — bespoke **data-only** lexer/parser/evaluator (ADR-0037/0038; **never raw Blade/PHP**;
  threat model documented).
- 🔜 **Style-Property system** (U9) · **multi-style tree + user chooser** (U10) · **upgrade-safe hook + Diff3 layer**
  (U11, APEX, on the sandbox) · **style import/export + global CSS box** (U12).

## Extensibility & integration
- ✅ **Module / plugin API** — semver'd, event/filter/slot hooks, manifest, lifecycle; **no core edits**.
- ✅ **REST API** (`/api/v1`, hashed tokens, engine-authorized) + **outbound webhooks** (HMAC, SSRF-guarded).
- ✅ **Importers** — phpBB / MyBB / SMF / XenForo (idempotent, attachment-verified, 301 redirects).
- 🔜 **Plugin install-from-zip + signature/trust gate** (U17, APEX); **Embed API / SSI / web components** (U7, APEX, net-new).
- ⛔ **GraphQL API** — deferred.

## Clubs, memberships, SSO, real-time, PWA
- ✅ **Clubs** (sub-communities, club-scoped permissions, membership flows, no-leak privacy fence).
- ✅ **Paid memberships** — tiers + perk gating through the engine; **manual provider is the live-granting path**;
  **Stripe hosted checkout shipped with charging DISABLED**; money-fenced paid clubs. *(⚠ validate live Stripe.)*
- ✅ **SSO** — OAuth (Google/GitHub/Discord), **SAML scaffold**, staff-2FA step-up, account linking. *(⚠ validate live.)*
- ✅ **Real-time** — Reverb broadcasting + **channel-authz no-leak fence** + opt-in presence + polling fallback. *(⚠ validate.)*
- ✅ **PWA + Web Push** — installable, subpath-aware service worker, VAPID push. *(⚠ validate push delivery + device install.)*

## i18n, accessibility, SEO
- ✅ **i18n framework** + RTL (`<html dir>`) + locale switch + per-key `en` fallback + **`es` proof locale** + partial sweeps
  (auth/errors/common/search/forum/members/profiles).
- ✅ **WCAG 2.1 AA** automated page gate across **27 surfaces** + manual checklist.
- ✅ **SEO basics** (sitemap, meta, canonical).
- 🔜 **Finish i18n string sweep** (~600+ residual strings) — **U21**. **JSON-LD/OG/Twitter + social share** — **U20**.

## Install, upgrade & ops
- ✅ **No-SSH web installer** (tier detection, setup token); **reversible migrations**; cron-driven **auto-upgrade**
  (backup-first, maintenance-gated); **portable backup/restore**.
- ✅ **First-class subdirectory install** (canonical home at mount root); **fresh-install path proven** (empty DB → GA).

---

# Part 2 — Planned (Post-GA `U`-series — Phase 6)

Reconciled, deduplicated, and scoped to **core parity + admin/ops breadth**. Detail (designs, evidence, APEX rationale)
in `docs/product/reevaluation-synthesis.md`.

## Tier 1 — Real core gaps (verified missing/partial)
| ID | Feature | Today | Effort | APEX |
|---|---|---|---|---|
| U1 | Quoting & multi-quote | 🟡 blockquote button only | M | — |
| U2 | Follow forums/tags + "Watched/Followed content" surface | 🟡 user→user only | M | — |
| U3 | Profile wall / status posts + comments | 🔜 absent | L | — |
| U4 | Announcements / notices (dismissible, display criteria) | 🟡 half-wired type + plain banner | M | — |
| U5 | Navigation / menu manager (admin-editable nav) | 🔜 hardcoded | M | — |
| U6 | Front-of-site moderator toolset + stored replies | 🟡 ACP/bulk only | L | — |

## Tier 2 — Net-new (kept from the Codex/Gemini reviews)
| ID | Feature | Today | Effort | APEX |
|---|---|---|---|---|
| U7 | Embed API / SSI / web components (external-site widgets) | 🔜 absent | M–L | **APEX** |
| U8 | Username history + name-change revert | 🔜 absent | S | — |

## Tier 3 — Theming depth (on the ADR-0037/0038 sandbox — never raw Blade)
| ID | Feature | Today | Effort | APEX |
|---|---|---|---|---|
| U9 | Rich Style-Property system (grouped typed props, live preview) | 🟡 ~7 tokens | L | — |
| U10 | Multi-style tree + parent/child inheritance + user chooser | 🟡 filesystem child themes | L | — |
| U11 | Upgrade-safe template hook/modification layer + Diff3 merge | 🟡 whole-file override | L | **APEX** |
| U12 | Style import/export (zip+manifest) + global custom-CSS box | 🟡 filesystem only | M | — |

## Tier 4 — Admin / ops & SEO breadth
| ID | Feature | Today | Effort | APEX |
|---|---|---|---|---|
| U13 | IP investigation + ban-management UI (CIDR/range, IP history) | 🟡 engine, thin UI | M | elevated |
| U14 | Registration controls (approval queue, ToS/age gate, domain allow/deny) | 🟡 open/closed + anti-spam | M | elevated |
| U15 | Mass member ops + bulk-mail / newsletter | 🟡 dir seams only | L | **APEX** |
| U16 | Maintenance/rebuild + broadened logs + mail test (ACP UI) | 🟡 crons + dashboard | M | — |
| U17 | Plugin install-from-zip + signature/trust gate | 🟡 enable/disable only | M | **APEX** |
| U18 | Finish CAPTCHA drivers (hCaptcha/reCAPTCHA) + Gravatar | 🟡 Turnstile only | S | — |
| U19 | Custom topic fields + move-with-redirect (wire the dead seam) | 🔜 / 🟡 | M | — |
| U20 | SEO polish — JSON-LD/OG, social share, "find content by user" | 🟡 basics only | S–M | — |
| U21 | i18n string sweep (residue: components → admin → clubs/settings/pm) | 🟡 framework + es | L | — |

**Suggested sequence:** quick wins (U8 · U18 · U20) → Tier 1 (U1→U2→U4→U5→U6→U3) → U7 Embed/SSI (APEX) →
Tier 3 theming (U9→U10→U11→U12) → Tier 4 (U13/U14 → U15 → U16 → U17 → U19 → U21).

---

# Part 3 — Deferred (intentionally out of scope; later as modules)

- ⛔ **Standalone suite apps as modules:** Media Gallery · Blog · Calendar/Events · Downloads/Resource Manager ·
  Pages/CMS · Commerce/Store. *(Recommendation: harden the module-author experience so these ship as
  first-party/community modules, not core weight.)*
- ⛔ **GraphQL API** · **Advertising/promo-slot system** · **Post-by-email / email-in replies**.
- ⛔ **Multi-tenant SaaS** (data-model seam kept, not built) · native mobile apps (PWA instead) · in-core chat bridges.

---

# Validate-before-go-live (carried from Phase 4/5)

Shipped but **scaffolded / disabled-by-default**; unit-tested against fakes only — enable + validate per each ADR:
**Meilisearch · Reverb realtime · live Stripe payments · OAuth/SAML providers · Web Push delivery · StopForumSpam
submission · at-scale load test · the residual manual a11y checklist.** A default baseline deploy uses none of them
(they ship inert). See `PROJECT-STATE.md → VALIDATE-BEFORE-GO-LIVE` and `docs/product/release-checklist-1.0.md`.
