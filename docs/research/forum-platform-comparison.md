# Forum Platform Comparison & Competitive Matrix

> **Project:** Hearth (working codename) — open-source self-hosted forum platform (Laravel + Livewire).
> **Stage A deliverable** for Section 5.1 (tech-stack comparison), 5.3 (best ideas to emulate), and 5.5
> (competitive matrix).
> **Date:** 2026-06-01. **Research method:** hybrid — established knowledge + live web verification of
> load-bearing facts (versions, licenses, architecture, pricing). Source links are inline.
> **Clean-room notice:** This document studies publicly documented *behavior, schemas, and concepts only*.
> No code, templates, UI text, branding, or documentation is copied from any platform — commercial or
> open-source. "Concept-safe-to-emulate" marks a generic capability idea we may reimplement from scratch;
> "do-not-copy" marks a proprietary implementation, name, or asset we must not reproduce.

---

## 1. Executive summary — what this means for Hearth

The six reference platforms split cleanly into two groups, and Hearth's opportunity sits in the gap
between them:

- **The open-source incumbents (phpBB, MyBB, SMF)** own *shared-hosting reach* and *permission depth*
  but are architecturally dated: hand-rolled or partial-framework PHP, no real-time, no REST API, weak
  native search, dated mobile UX, and theming/upgrade models that punish customization. phpBB is the
  strongest of the three (Symfony components, Twig, a non-invasive extension system, the best security
  record); MyBB and SMF are pre-modern procedural codebases whose ground-up rewrites (MyBB 2.0, SMF 3.0)
  have stalled for years.
- **The commercial platforms (ProBoards, XenForo, Invision)** own *product polish* — inline moderation,
  warning-point automation, reactions/trophies, Clubs, Commerce, PWA/push, REST/GraphQL APIs — but are
  closed, paid, and (for Invision) increasingly resented over pricing and feature removal.

**Hearth's thesis:** deliver the commercial-grade product surface (XenForo/Invision polish) on the
open-source incumbents' hosting floor (runs on a shared PHP host), with phpBB-grade permissions, modern
Laravel architecture, and an explicit anti-fragility stance on the four things every incumbent gets
wrong — **spam, search, upgrades-that-break-customization, and migration fidelity**. The
[community-complaints evidence table](community-complaints-and-feature-requests.md) drives those
priorities.

This maps directly to the brief's Design DNA (Section 3): **permission model & data layout from
phpBB/MyBB/SMF; polish & engagement from XenForo/Invision; accessible point-and-click customization from
ProBoards.**

---

## 2. Tech-stack comparison — open-source incumbents (Section 5.1)

The OSS trio are the platforms Hearth is most directly compared to on hosting and architecture. Verified
facts as of June 2026.

| Dimension | **phpBB** | **MyBB** | **SMF** |
|---|---|---|---|
| Current stable | **3.3.16** (Apr 2026) | **1.8.40** (May 2026) | **2.1.7** (Mar 2024 — none since) |
| Min PHP | 7.2 | 5.2 (docs floor; runs on 8.x) | 7.1 |
| License | **GPL-2.0** | **LGPL-3.0** | **BSD-3-Clause** |
| Architecture | Hybrid: Symfony components (DI, EventDispatcher, Routing, Console) bolted onto a procedural core; per-action entry scripts, no unified front controller | Fully hand-rolled procedural PHP; per-action entry scripts; no Composer/autoloading | Fully hand-rolled procedural PHP; `index.php?action=` dispatcher; huge flat global-function `Sources/` |
| Templating | **Twig 2** files in styles; child-style overrides; template *events* for injection | Templates stored **in the DB**, rendered via `eval()` of `{$var}` strings | **PHP files** in `Themes/` that echo HTML (logic-in-template); child-theme file fallback |
| DB support | MySQL/MariaDB, PostgreSQL, SQLite, MSSQL, Oracle (custom DBAL) | MySQL/MariaDB, PostgreSQL, SQLite (custom DBAL) | MySQL/MariaDB, PostgreSQL (custom DBAL) |
| Extension model | **Extensions**: self-contained `ext/`, PHP **events** (Symfony EventSubscriber) + template events + DB **migrations**; survive upgrades | **Plugins**: hook callbacks (`run_hooks`) + fragile `find_replace_templatesets()` string-patching of DB templates; no migration runner | **Package Manager** mods: XML-described **file patches** to core (invasive) + a newer hook system; patches conflict on upgrade |
| Permission model | **Three-state YES / NO / NEVER** (NEVER is absolute); per-user & per-group; **global vs local (per-forum) scope**; **roles** as presets; primary+secondary groups; effective-permission "mask" viewer | Usergroup-based; global + per-forum overrides; effectively binary (most-permissive group wins); per-forum moderators | **Membergroups** + post-count groups; **permission profiles** (named bundles assigned per board); three-value allowed/disallowed/deny |
| Search | Native DB full-text (MySQL FULLTEXT / PG tsvector); **Sphinx** add-on for scale | Native MySQL full-text + custom word index; weakest at scale | Native full-text + custom index; official **Sphinx/Manticore** project for 300k+ messages |
| Caching | Pluggable: file (default), APCu, Memcached, Redis, WinCache | Pluggable: DB (default), file, Memcached, APCu, Redis | Levels 0–3; APC/eAccelerator/Memcache/xcache/file (no core Redis) |
| Install / upgrade | Web installer; overwrite + update mode runs migrations automatically (cleanest of the three) | Web installer (delete after); overwrite + run upgrade script | Web installer; overwrite + run `upgrade.php`; mod patches often break |
| i18n | PHP-array language packs; 50+ langs; RTL needs an RTL-aware style | PHP-include language files; RTL via theme, not auto | PHP language files; `lang_rtl` flag; partial RTL in default theme |
| Security posture | Best of three: bcrypt (phpass), low recent-CVE count on 3.3.x, admin re-auth | Weakest: recurring CVE clusters; **`eval()` templates are a structural risk**; documented BBCode-XSS→SQLi→RCE chain (2023) | Adequate; **file-patch mods inject PHP into core** (systemic risk); object-injection CVEs in 2.1 era |
| Testing | PHPUnit unit+functional (Goutte), GitHub Actions CI, extension test framework | Minimal automated tests | Minimal automated tests |
| Shared-host fit | Excellent (PHP+MySQL, web installer, file cache) | Excellent (lowest requirements; no Composer/CLI) | Excellent (PHP+MySQL, no Composer/CLI) |
| Top modernization debt | Procedural entry points; incomplete event coverage; feature-frozen 3.3.x (phpBB 4 alpha criticized as too little) | `eval()` template engine; no Composer/PSR-4; MyBB 2.0 stalled / effectively abandoned (~2018, after ~6 yrs of Laravel-based development) | Procedural sprawl; patch-based mods; governance risk (lead dev left 2024, no release in 15+ months) |

**Sources:** phpBB — [endoflife.date](https://endoflife.date/phpbb) · [3.3.16 release](https://www.phpbb.com/community/viewtopic.php?t=2671024) · [permission system](https://area51.phpbb.com/docs/dev/3.3.x/extensions/permission_system.html) · [events tutorial](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_events.html). MyBB — [versions](https://mybb.com/versions/) · [plugin hooks](https://docs.mybb.com/1.8/development/plugins/hooks/) · [template modification](https://docs.mybb.com/1.8/development/plugins/creating-modifying-templates/) · [license](https://mybb.com/about/license/) · [BBCode→RCE chain (dayzerosec, 2023)](https://dayzerosec.com/vulns/2023/01/30/mybb-bbcode-xss-to-admin-sql-injection-to-code-injection-chain.html). SMF — [GitHub releases](https://github.com/SimpleMachines/SMF/releases) · [permissions wiki](https://wiki.simplemachines.org/smf/SMF2.1:Permissions) · [package manager](https://wiki.simplemachines.org/smf/SMF2.1:Package_manager) · [3.0 announcement](https://blogs.simplemachines.org/dev/587334/Announcing+the+start+of+SMF+3.0+development.html).

### 2.1 Design implications for Hearth (from the OSS trio)

- **Adopt phpBB's permission concepts, modernized.** The three-state allow/deny/**never** resolution with
  global→category→forum→thread scope and role presets is the most expressive model and is a brief primary
  requirement. We reimplement it from scratch in Eloquent (clean-room). See
  [security-and-permissions](../architecture/security-and-permissions.md).
- **Never use `eval()` templates or patch-the-core mods.** MyBB's and SMF's worst structural traits cause
  both their security and their upgrade-breakage problems. Hearth's module system uses **events/hooks +
  declarative slot injection + reversible migrations** (no core edits), and theming uses **Blade override
  layers** (no core edits) — see [plugin-and-theme-system](../architecture/plugin-and-theme-system.md).
- **Keep the shared-host floor the incumbents have.** Their one durable advantage is "runs anywhere." Our
  tiered architecture preserves this (baseline tier) while adding a modern enhanced tier.
- **Cautionary note on rewrites.** MyBB 2.0 (a Laravel rewrite) and SMF 3.0 both stalled for years —
  evidence that *resourcing and incrementalism* matter more than framework choice. Hearth's roadmap is
  deliberately phased and always-runnable to avoid the same fate (see [roadmap](../product/roadmap.md)).

---

## 3. Commercial platforms — capabilities & best ideas to emulate (Section 5.3)

Studied for product/UX intent only. Every item below is a **concept** to reimplement from scratch, never
copied code/branding.

### 3.1 ProBoards — accessible, zero-tech customization

Hosted SaaS (free + ads; small paid add-ons). Targets non-technical admins. **Best ideas (concept-safe):**
drag-and-drop board/category reordering from the admin view; **visual color-scheme editor with live
preview**; **widget-based custom-page builder** with drag ordering; one-click plugin install from a
browsable library; post-count rank-title ladder; per-board configurable "Report" button; inline poll
creation in the composer. **Do-not-copy:** ProBoards' admin UI design, the "Yootil" API name, specific
plugin code. **Weaknesses to avoid:** no data ownership (boards can be deleted), forced ads, no importer,
limited SEO control. **Sources:** [free features](https://www.proboards.com/free-forum-features) ·
[admin widgets](https://www.proboards.com/admin-guide/custom-pages/adding-widgets).

→ ProBoards is the north star for the brief's **"point-and-click theming & layout for non-technical
admins"** requirement (Section 3, Section 6 theming).

### 3.2 XenForo — self-hosted polish & developer surface

Paid self-hosted (**$195 one-time + $60/yr** renewal for updates/support; Enhanced Search +$60, Resource
Manager/Gallery +$70 each; cloud **$60–$100/mo**). *Re-verified June 2026 against XenForo's
[purchase page](https://xenforo.com/purchase/self-hosted): the renewal is now **$60/yr** (the draft said
$55). Pricing is the most drift-prone data in this doc; re-verify before relying on it.*
**Best ideas (concept-safe):**

- **Style Properties** — a named layer of visual config values (colors/fonts/spacing) templates reference,
  editable without touching markup. (Directly informs our visual theme configurator.)
- **Diff-based template inheritance** — child themes store only their deviations from the parent, so
  upgrades preserve customizations. (Maps cleanly to Blade override layers.)
- **Reaction score = positives − negatives** as a single profile metric.
- **Trophy/criteria engine** — admin-defined rule conditions (post count, reaction score, tenure)
  auto-award trophies; **trophy points can auto-promote a user to a higher group** (gamification ↔
  permissions). Maps to Laravel events + our group-promotion rules.
- **Cross-page persistent bulk moderation** — select items across paginated pages, then act once.
- **Warning points with time-decay + automated escalating consequences** (restrict/moderate/ban at
  thresholds; points expire). *The single highest-leverage moderation feature.*
- **StopForumSpam with a configurable confidence threshold** (low = flag, high = block) + a **Spam Cleaner**
  that bulk-removes a flagged user's content.
- **Forum-as-OAuth2-provider** (2.3) + scoped ACP-issued API keys + webhooks; **automatic bounced-email
  handler**; **bot-visible content suppression** (lean HTML to crawlers).
- Editor moving to **Tiptap** in 2.4 — note Tiptap's **core and standard extensions are MIT** (usable
  directly), but its *Pro* extensions and collaboration server are commercial; Hearth would rely **only on
  the MIT-licensed parts** (recorded in [technical-stack-recommendation](../architecture/technical-stack-recommendation.md)
  dependency-license discipline).

**Do-not-copy:** XenForo brand, "XFRM"/"Resource Manager" naming, its template syntax, ACP layout, BBCode
tag behavior. **Sources:** [pricing](https://xenforo.com/purchase/self-hosted) ·
[moderation](https://xenforo.com/features/moderation/) · [spam](https://docs.xenforo.com/manual/configuration/spam)
· [REST API](https://docs.xenforo.com/manual/reference/rest-api) ·
[OAuth2 2.3](https://xenforo.com/community/threads/single-sign-on-and-more-with-oauth2-in-xenforo-2-3.217519/)
· [Tiptap editor](https://xenforo.com/community/threads/tiptap-a-new-editor-for-xenforo.227767/).

### 3.3 Invision Community (IPS 5) — full-stack community + commerce

Paid — **self-hosted "Classic" = $499 one-time + $199/yr** renewal; separate, much higher **cloud** tiers
(cheapest ≈ **$1,068/yr**; the entry cloud plan rose steeply during the v5 transition, fueling the backlash
below). *Re-verified June 2026 via the [IPS self-hosted store](https://invisioncommunity.com/buy/self-hosted/)
and [TrustRadius](https://www.trustradius.com/products/invision-community/pricing); pricing is drift-prone —
re-verify before relying on it.* The depth benchmark for monetization and breadth.
**Best ideas (concept-safe):**

- **Clubs** — user-created sub-communities with their own boards/gallery/events and **open / closed /
  private / paid** visibility types. Elevates a forum from archive to living social space.
- **Commerce** — subscriptions with **proration on upgrade/downgrade**; **subscription → group →
  permission** pipeline (buy → join group → gain perms; expire → lose perms); physical + digital products
  in one storefront; **selling ad placements inside notification/digest emails**.
- **Achievements/badges + time-windowed leaderboards** (today / this week / all-time on one surface).
- **Notification merging** (collapse N same-thread notifications into one) + **email previews** that show
  content to drive re-engagement + **web push + iOS PWA push + app-icon badge count**.
- **AI-scored suspicious-registration hold queue + MaxMind IP risk scoring** at registration.
- **Required warning acknowledgment** (member must click to acknowledge before posting is restored) +
  **pre-defined warning action bundles** (one warning type carries a preset consequence).
- **SEO:** auto-`noindex` on empty containers; **smart sitemap filtering** (only content above a quality
  threshold). Broadest **first-party importer** (14+ platforms incl. phpBB 2/3, MyBB 1.8, SMF 2.0,
  XenForo, vBulletin, WordPress). **REST + GraphQL + webhooks**. Mobile-first, PWA, TipTap editor.

**Do-not-copy:** IPS Commerce storefront/invoice templates, "Clubs" branding & screens, Marketplace listing
format. **Weakness to avoid:** v5's **pricing increases + feature removal** triggered documented churn —
Hearth's answer is open-source + a non-gatekept ecosystem. **Sources:**
[warnings](https://invisioncommunity.com/4guides/staff-and-moderation/warnings-restrictions-r84/) ·
[conversions/import](https://invisioncommunity.com/4guides/getting-started/migrating-from-another-platform/running-the-conversion-r211/)
· [web push](https://invisioncommunity.com/news/invision-community/web-push-notifications-native-sharing-offline-support-r1222/)
· [new editor](https://invisioncommunity.com/news/invision-community/invision-community-5-the-all-new-editor-r1301/)
· [pricing backlash](https://www.theadminzone.com/threads/price-hike-no-more-ticket-support-enough-signals-from-invision.154028/page-3).

### 3.4 Prioritized "steal-the-concept" list for Hearth

In rough leverage order (all reimplemented clean-room; phase tags reference the [roadmap](../product/roadmap.md)):

1. Warning points w/ time-decay + auto-consequences (XF/IPS) — **Phase 2**.
2. Cross-page persistent bulk moderation selection (XF) — **Phase 2**.
3. First-class anti-spam: StopForumSpam confidence thresholds + Spam Cleaner + risk scoring (XF/IPS) — **Phase 1–4** ([anti-spam ADR](../architecture/security-and-permissions.md)).
4. Trophy/criteria engine → auto group promotion (XF) — **Phase 2**.
5. Diff-based theme override layers + named Style-Properties visual config (XF/ProBoards) — **Phase 3**.
6. Subscription → group → permission pipeline (IPS) — **Phase 4**.
7. Notification merging + web push + PWA badge (IPS) — **Phase 2/4**.
8. `noindex` empty containers + smart sitemap + schema.org (IPS/XF) — **Phase 1/3**.
9. Forum-as-OAuth2 provider + scoped API keys + webhooks (XF/IPS) — **Phase 3/4**.
10. Clubs with visibility types (IPS) — **Phase 4**.
11. Wizard-driven, verifying importers (IPS) — **Phase 3**.
12. Automatic email bounce processing (XF) — **Phase 2**.

---

## 4. Competitive matrix (Section 5.5)

All six platforms scored on one **1–5** scale (5 = best-in-class), cross-normalized by the author from the
sourced research above. Scores are comparative judgments, not precise measurements; they are directional
inputs to prioritization.

| Dimension | phpBB | MyBB | SMF | ProBoards | XenForo | Invision |
|---|:--:|:--:|:--:|:--:|:--:|:--:|
| Install ease | 4 | 5 | 5 | 5 | 3 | 3 |
| Admin ease | 4 | 3 | 3 | 5 | 3 | 4 |
| Theme customization | 3 | 2 | 3 | 2 | 5 | 5 |
| Plugin ecosystem | 4 | 3 | 3 | 2 | 5 | 4 |
| Moderation tools | 4 | 3 | 4 | 2 | 5 | 5 |
| Permission flexibility | 5 | 3 | 4 | 2 | 5 | 4 |
| UX polish | 3 | 3 | 2 | 3 | 4 | 5 |
| Mobile | 3 | 2 | 2 | 2 | 3 | 5 |
| Performance / scalability | 3 | 2 | 3 | 3 | 4 | 4 |
| Security posture | 4 | 2 | 3 | 3 | 4 | 4 |
| Upgrade experience | 4 | 3 | 3 | 5 | 3 | 3 |
| Developer experience | 4 | 2 | 2 | 1 | 4 | 4 |
| Docs quality | 4 | 3 | 3 | 2 | 4 | 4 |
| Community health | 4 | 3 | 2 | 2 | 5 | 3 |
| Architecture modernity | 3 | 1 | 1 | 2 | 3 | 5 |
| Import / export | 4 | 4 | 4 | 1 | 3 | 5 |
| **Best-fit use case** | Traditional free community w/ big extension catalog | Lightweight hobby forum on cheap shared hosting | Simple familiar forum; permissive BSD license | Zero-maintenance hosted hobby community | Self-hosted enthusiast/power-user forum | Full-stack community suite + commerce |

### 4.1 Where Hearth aims to land

Hearth's target profile (to be earned, not assumed): **install ease 4–5** (web installer on shared hosts),
**permission flexibility 5** (phpBB-grade), **moderation 5** (XF/IPS-grade inline + queue), **theme
customization 5** (visual + developer), **mobile 5** (mobile-first), **architecture modernity 5** (Laravel),
**import/export 5** (verifying importers), **upgrade experience 5** (reversible migrations + semver'd
module/theme APIs) — i.e., **combine the incumbents' install ease and permission depth with the commercial
platforms' polish**, while beating all six on upgrades and openness.

---

## 5. The gap all six share (Hearth's wedge)

No reviewed platform delivers *all* of: shared-host-installable **and** modern (real-time, API-first,
mobile-first) **and** open-source **and** phpBB-grade permissions **and** non-fragile customization/upgrades
**and** strong out-of-the-box anti-spam/search/SEO/migration. The open-source trio lack the modern product
surface; the commercial trio lack openness and (mostly) the shared-host story. **That intersection is
Hearth.** The next document quantifies the specific pain points with operator evidence and maps each to a
concrete Hearth design response.

→ Continue to [community-complaints-and-feature-requests.md](community-complaints-and-feature-requests.md).

---

## Appendix — primary sources

phpBB: [endoflife.date](https://endoflife.date/phpbb) · [GitHub](https://github.com/phpbb/phpbb) ·
[permissions](https://area51.phpbb.com/docs/dev/3.3.x/extensions/permission_system.html) ·
[extensions/events](https://area51.phpbb.com/docs/dev/3.3.x/extensions/tutorial_events.html). 
MyBB: [versions](https://mybb.com/versions/) · [plugin basics](https://docs.mybb.com/1.8/development/plugins/basics/) ·
[hooks](https://docs.mybb.com/1.8/development/plugins/hooks/) · [license](https://mybb.com/about/license/) ·
[security issues](https://mybb.com/versions/security-issues/). 
SMF: [releases](https://github.com/SimpleMachines/SMF/releases) · [permissions](https://wiki.simplemachines.org/smf/SMF2.1:Permissions) ·
[package manager](https://wiki.simplemachines.org/smf/SMF2.1:Package_manager) · [3.0 announcement](https://blogs.simplemachines.org/dev/587334/Announcing+the+start+of+SMF+3.0+development.html). 
ProBoards: [features](https://www.proboards.com/free-forum-features) · [admin widgets](https://www.proboards.com/admin-guide/custom-pages/adding-widgets). 
XenForo: [pricing](https://xenforo.com/purchase/self-hosted) · [moderation](https://xenforo.com/features/moderation/) ·
[spam](https://docs.xenforo.com/manual/configuration/spam) · [REST API](https://docs.xenforo.com/manual/reference/rest-api) ·
[OAuth2](https://xenforo.com/community/threads/single-sign-on-and-more-with-oauth2-in-xenforo-2-3.217519/) ·
[Tiptap](https://xenforo.com/community/threads/tiptap-a-new-editor-for-xenforo.227767/). 
Invision: [warnings](https://invisioncommunity.com/4guides/staff-and-moderation/warnings-restrictions-r84/) ·
[conversions](https://invisioncommunity.com/4guides/getting-started/migrating-from-another-platform/running-the-conversion-r211/) ·
[web push](https://invisioncommunity.com/news/invision-community/web-push-notifications-native-sharing-offline-support-r1222/) ·
[IPS 5 editor](https://invisioncommunity.com/news/invision-community/invision-community-5-the-all-new-editor-r1301/) ·
[pricing backlash](https://www.theadminzone.com/threads/price-hike-no-more-ticket-support-enough-signals-from-invision.154028/page-3).
