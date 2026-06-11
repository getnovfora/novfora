# MVP Scope

> **Project:** NovFora (working codename). **Stage A deliverable** (Section 8 #5). **Date:** 2026-06-01.
> Defines the **Phase 1 Core MVP** — the **Must** set from [feature-prioritization](feature-prioritization.md) —
> plus an explicit **MVP-cut-options menu** for the Phase 0 gate (your decision). The MVP must **run on a
> baseline host (PHP 8.3 + MySQL + cron)** and be **tested + runnable** at completion, like every phase.

---

## 1. MVP goal (one sentence)

A self-installable, server-rendered forum that a real community can run on a commodity shared host — with
**phpBB-grade permissions**, a **modern WYSIWYG editor**, **first-class anti-spam**, **email notifications**,
a **mobile-first theme with a no-core-edit override layer**, and **safe install/upgrade/backup** — proving the
two-tier architecture end-to-end.

## 2. In scope (Must)

**Foundation & operability**
- Laravel 13 + Livewire 4 skeleton; **service-tier detection + driver abstraction wired from the start** (ADR-0003).
- **Web installer that needs no SSH** + Composer path; tier detection surfaced in installer/admin.
- **Reversible-migration baseline** + **automated DB backup** + restore path; prebuilt assets (no host Node).

**Identity & access**
- Email-verified registration (optional admin approval); basic rich profiles; avatars (basic).
- **Permission-mask engine** (ALLOW/NO/NEVER, global→forum scope), primary+secondary groups, **role presets**,
  and a **minimal "why can/can't X" inspector**.
- **Trust levels** (TL0…) as system groups — the new-user anti-spam gate, unified with the ACL.

**Content**
- Categories → forums → topics → posts (nesting, ordering, **per-node permissions**); sticky/lock/move;
  soft-delete + recycle bin; **audit log**.
- **WYSIWYG editor** (TipTap-class: formatting, @mentions, quotes, code, **drag-drop/paste image upload**) with
  **canonical sanitized storage** (ADR-0005); image attachments + thumbnailing.
- Per-user unread / "what's new".

**Anti-spam (first-class — the differentiator, not cut)**
- Crowdsourced blocklist (cron-cached) · **provider-swappable CAPTCHA** (Q&A + invisible) · honeypot/timing ·
  trust-tiered rate limiting · disposable-email block · **new-user moderation queue** · email verification.

**Moderation & notifications**
- ACP + MCP; **moderation queue** + approval workflow; bans (user/IP/email/range); email **and** basic in-app
  notifications (queued; polling on baseline).

**Presentation & findability**
- **Mobile-first responsive default theme** + **Blade override layer** (no core edits) + **theme a11y floor**
  (contrast/keyboard).
- Search via **Scout DB driver (MySQL full-text)**; canonical URLs + `schema.org` + OG tags + basic sitemap;
  response/fragment caching to the [performance budgets](../architecture/system-architecture.md).

**Cross-cutting:** OWASP security baseline · tests (permission-mask + tier-fallback suites mandatory) ·
WCAG 2.1 AA baseline · utf8mb4/i18n-ready.

## 3. Out of scope for MVP (later phases — see [roadmap](roadmap.md))
PMs, reactions, badges, activity feeds, custom profile fields, digests (Phase 2) · inline thread-view bulk
moderation, warnings/infractions, reports UI (Phase 2) · module/plugin API, visual theme configurator, REST
API/webhooks, **phpBB/MyBB/SMF importers**, analytics (Phase 3) · SSO, paid memberships, Clubs, Meilisearch,
Reverb real-time, PWA/push (Phase 4) · multi-tenant (never, seam only).

## 4. MVP-cut options (decide at the Phase 0 gate)

If Phase 1 needs to ship leaner/faster, here is the **trim menu** — what each saves and its consequence. **Do
not cut** the anti-spam baseline, the permission engine, reversible migrations, or the no-SSH installer (the
load-bearing differentiators). My recommendation is in the last column.

| # | Candidate cut | Saves | Consequence / risk | Rec. |
|---|---|---|---|:--:|
| 1 | **Editor: reduced node set** (defer slash-commands, tables, advanced embeds to P2) | meaningful (de-risks the #1 spike) | editor is "good" not "rich" at launch | **Cut** |
| 2 | **Markdown input mode → P2** (WYSIWYG-only MVP) | small | power users wait one phase | **Cut** |
| 3 | **2nd/example theme + dark mode → P2/3** (ship one polished default + the override mechanism) | moderate design time | less visual choice at launch | **Cut** |
| 4 | **Attachments: images-only** (defer arbitrary files to P2) | small–moderate | no file uploads initially | **Cut** |
| 5 | **Custom profile fields, signatures, covers → P2** | small | basic profiles only | **Cut** |
| 6 | **Permission inspector → minimal/log-only** (full UI in P2) | small | admins debug masks via logs first | Keep-min |
| 7 | **In-app notifications → email-only MVP** | moderate | no bell icon at launch (expected feature) | **Keep** |
| 8 | **Search filters/facets → P2** (basic keyword MVP) | small | keyword-only search initially | **Cut** |
| 9 | **StopForumSpam live API → cached-list-only** (keep all other anti-spam layers) | small | slightly less fresh blocklist; everything else intact | **Cut** |
| 10 | **Backups → CLI command + docs** (defer scheduled/cloud UI to P3) | small | operator schedules the cron themselves | **Cut** |

**Two framings for the gate:**
- **Full MVP** (§2 as written) — the complete, demo-ready Phase 1.
- **Lean MVP** (apply recommended cuts 1–5, 8–10) — same architecture and differentiators, smaller surface,
  faster to a runnable milestone; the deferred items land in Phase 2.

## 5. Acceptance criteria (MVP "done")
1. Installs on a **PHP 8.3 + MySQL + cron** shared host via the **web installer, no SSH**.
2. A user can **register (passing anti-spam), verify email, and post via the WYSIWYG editor**; content stores
   canonically and renders safely.
3. **Permissions resolve correctly** (the dedicated truth-table suite is green; the inspector explains a
   verdict).
4. **Service-tier fallbacks pass** (the same suite is green under baseline drivers; forced-absence tests pass).
5. A moderator can **queue-approve, ban, and recycle/restore**; every action is **audited**.
6. **Email notifications send** via host SMTP; the deliverability self-test runs.
7. A **reversible migration** upgrade and a **backup→restore** both succeed on the baseline tier.
8. The default theme is **mobile-first** and passes the **a11y contrast/keyboard floor**; a child theme can
   override a view **without editing core**.
9. **No baseline feature errors** when Redis/Meilisearch/WebSockets are absent.
