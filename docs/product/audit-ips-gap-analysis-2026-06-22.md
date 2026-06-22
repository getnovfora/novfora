<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# UI/UX Audit Gap Analysis — IPS vs. NovFora (2026-06-22)

> **What this is.** A reconciliation of the two external UI/UX audits (`admin-panel-audit-ips-vs-novfora.md`,
> `member-ux-audit-ips-vs-novfora.md`) against the **actual `main` codebase**, with each real gap mapped to (a) where
> it lives in code and (b) where it slots in the roadmap. This is an **analysis/planning doc, not an ADR** — nothing
> here is a locked decision yet.
>
> **Companion:** the design/polish + flagship-editor track lives in
> [`design-polish-program-2026-06-22.md`](design-polish-program-2026-06-22.md).
>
> **Read this first:** both audits were run against a live demo while **signed in as a Co-owner**, and several findings
> were tested against **guessed URLs** (`/u/tommy`, `/admin/appearance/appearance`, `/admin/moderation/spam`). That
> single fact invalidates roughly a third of the headline findings — including most of the ones flagged "critical."

---

## TL;DR

The audits are useful but **demo-limited**. Of ~30 distinct findings:

- **~8 are already shipped or were auditor artifacts** — do **not** build (profile page, dark mode, advanced search,
  favicon/logo upload, editor a11y labels, the two "dead routes," and almost certainly the
  "mod buttons visible to members"). **Correction:** the post **"scroll-trap" is REAL** — caught on a second, CSS-level
  pass (see X2 / M0); my first pass mis-called it. Details in §1.
- **2 are surface gaps** — the engine exists, only the UI surfacing is incomplete (warnings in the ACP; unread state on
  the topic list). Cheap. §2.
- **~12 are genuine net-new** — real, mostly small, and they cluster cleanly into **three post-GA workstreams**. §2–§3.

Net: the project is in far better shape than the audits imply. The real backlog is **per-post quote-reply** and a
**proper ACP member-management surface** — everything else is polish.

---

## 1. Already solved or auditor artifact — DO NOT BUILD

| # | Audit claim (severity it gave) | Reality | Evidence in code |
|---|---|---|---|
| X1 | **User profile `/u/username` 404 — "no identity layer"** (Critical) | Profiles fully shipped at **`/users/{username}`** (tabs, custom fields, badges, follow). Auditor hit the wrong prefix. | `ProfileController`, `resources/views/profiles/show.blade.php`, route `profiles.show`; `User::getRouteKeyName()='username'` |
| X2 | **Inner-post scroll trap** ("most disruptive defect") | **CORRECTED — this IS real.** Rendered posts share the `.novfora-prose` class, which carries `max-height:28rem; overflow-y:auto` (`app.css:404`) — the editor box's cap leaking onto posts. My first pass only grepped Blade for inline styles and missed the CSS rule. Now tracked as **M0** (§2) + the polish program. | `app.css:404`; `forum/topic.blade.php:175,178` |
| X3 | **No dark mode** (Medium) | Full dark mode: OS detection + per-user `color_mode` + no-flash SSR + token system. | `resources/css/app.css`, `layouts/app.blade.php`, `/settings/appearance` |
| X4 | **No advanced search page** (High-ish) | Dedicated `/search` page with forum/author/type/date filters + saved searches. | `SearchController`, `resources/views/search/index.blade.php`, `SearchService` |
| X5 | **No favicon/logo management** (admin P4) | Logo + favicon + background upload all present — on the **Themes** page, not the Appearance page the auditor opened. | `…/admin/settings/⚡themes.blade.php`, `StyleThemeManager::storeAsset`, route `admin.settings.themes` |
| X6 | **Editor toolbar icons unlabelled** (a11y) | Every toolbar button has `aria-label`; wrapper is `role="toolbar"`. | `resources/views/components/content-editor.blade.php` |
| X7 | **Dead routes** `/admin/appearance/appearance`, `/admin/moderation/spam` | Neither URL is real. Correct paths exist (`/admin/appearance`, `/admin/moderation/spam-intelligence`). The nav only renders links gated by `Route::has()`, so it can't emit a dead link. | `AdminNavigation` SECTIONS map + `web.php` 301 table |
| X8 | **Mod actions (Pin/Lock/Merge/Delete) shown to regular members** (High) | Almost certainly an artifact of viewing as a Co-owner. Worth a 5-min confirm that the topic header gates these with `@can`. | `resources/views/forum/topic.blade.php` — **verify, do not assume a bug** |
| X9 | **No member directory** (front-end) | Front-end `/members` directory exists (card grid, filters). Only the **admin-side** table is missing (that's A1, a real gap). | `<livewire:members-directory>` |

**Takeaway:** before acting on any future audit, re-run it as a **regular member** against the **real route table**.
Five of the audits' "critical/high" items evaporate under that lens.

---

## 2. Real gaps — where to fix + how big

`SURFACE` = engine already exists, wire up UI. `NEW` = net-new feature. `ENH` = enhance an existing surface.

### Admin panel

| # | Gap | Where it lives now | Type | Priority |
|---|---|---|---|---|
| A1 | **In-admin member table** (sortable/filterable list of all members, row actions) | Only a visibility *setting* exists (`…/admin/settings/⚡members-directory.blade.php`). No data table. | NEW | **High** |
| A2 | **Per-member admin management view** (ban / warn / groups / IP / devices in one screen) | Only primary-group override exists (`⚡edit-primary-group`, `MemberPrimaryGroupController`). Ban/warn live in the front-end mod CP (`BanController`, `WarningController`). **Already a recorded v3-b deferral** ("per-user Moderation tab on member-edit"). | NEW | **High** |
| A3 | **Warnings/infractions in the ACP** (manage warning types, see per-member history) | Full engine shipped, surfaced **front-end only**: `Warning`/`WarningType` models, `WarningService` (decay + auto-consequence + ack), `/warnings`. | SURFACE | Medium |
| A4 | **Canned / stock moderator replies** | Nothing — no model/route/view. | NEW | Medium |
| A5 | **Email template editor** (admin-editable transactional emails) | Code-only Mailables (`app/Mail/DigestMail`, `NotificationMail` + `resources/views/mail/*`). A sandboxed *page*-template editor exists but is unrelated. | NEW | Low–Med |
| A6 | **Analytics charts** | `admin.analytics` renders 4 stat cards + a 30-day **table** (`AnalyticsService`, `DailyMetric`). No JS charting. | ENH | Low–Med |
| A7 | **Geo-blocking + IP/spam whitelist** | CAPTCHA (Q&A + Turnstile), SFS API, blocklist all exist; geo + whitelist do not. | NEW | Low (only on demand) |

### Member-facing

| # | Gap | Where it lives now | Type | Priority |
|---|---|---|---|---|
| M0 | **Inner-post scroll trap** — long posts clipped at 28rem with an inner scrollbar (audit's worst-rated defect) | Shared `.novfora-prose` cap (`app.css:404`) leaks the editor box's height limit onto rendered posts (`topic.blade.php:175,178`, `profiles/show.blade.php:147`). | Fix — scope cap to `.novfora-editor` | **High (hotfix)** |
| M1 | **Per-post quote / reply-to-post** | One bottom-of-thread composer; `reply-composer` takes only `topicId` (no `quotePostId`). | NEW | **High** |
| M2 | **Unread/read indicator on the topic list** | `TopicRead` watermark + `/whats-new` exist; the board list (`forum/show.blade.php`) shows no per-row unread state. | SURFACE | Medium |
| M3 | **Topic-list first-post excerpt** | Rows show title/author/counts only. | ENH | Medium |
| M4 | **Notification inline dropdown** | Bell is a plain link to `/notifications` (live count badge only). | ENH | Medium |
| M5 | **Topic / forum follow-subscribe** (notify without replying) | Only user→user follow (`FollowService`) + bookmarks (no notify). No topic/forum subscription model. | NEW | Medium–High |
| M6 | **Slug in topic URLs** (`/topics/19-php-vs-node`) | `/topics/{topic}` binds by numeric id; `Topic` has no slug + no `getRouteKeyName` override. | NEW | Medium (SEO) |
| M7 | **Polish bundle** — de-weight `Delete` vs `Edit`, reply-submit button in viewport, optional header dark-mode toggle, confirm mod-action gating (X8) | `forum/topic.blade.php`, `reply-composer`, header nav | ENH | Low |

---

## 3. Roadmap placement

NovFora shipped **1.0.0 (GA)** — Phases 0–5 plus the full ACP v3 program are complete, so these are **post-1.0
increments**. They group into three workstreams that match the project's existing post-GA cadence (small ADR-backed
branches; an ACP "vN" program for admin work).

### → 1.1 — Member-experience completion *(front-end polish branches, à la `claude/ui-ux-*`)*
The everyday-forum loop. Mostly small, mostly Sonnet-class view work; ships as independent gated branches.

- **M1 per-post quote-reply** (High) and **M5 topic follow/subscribe** (High) are the two that move the needle —
  M5 adds a new subscription model + notification wiring, so it's the largest item here.
- **M2 unread indicator** (cheap — `TopicRead` already exists), **M3 excerpt**, **M4 notification dropdown**,
  **M6 topic slug**, **M7 polish** round it out.

### → 1.2 — ACP v4: Member management *(the natural successor to ACP v3)*
Admin-side, apex-rung (touches member/permission/ban surfaces → Fable-max per CLAUDE.md routing).

- **A1 member table** → **A2 per-member management view** (A2 builds on A1 and **discharges the already-recorded
  v3-b deferral**) → **A3 warnings surfaced in the ACP**. Run as ACP v4 slices with the same adversarial-review
  discipline as v3.

### → 1.3 — Admin content & insight tooling *(lower priority)*
- **A5 canned replies**, **A4 email template editor**, **A6 analytics charts**, **A7 geo/whitelist** (only if a real
  install asks for it).

**Suggested sequencing:** run **1.1 and 1.2 in parallel** (different surfaces, different model rungs — no contention),
defer **1.3**. Highest single-item leverage: **M1 (quote-reply)** for members, **A1→A2 (member management)** for admins.

### Items to drop from the audits entirely
X1 and X3–X9 above (already shipped or artifact; **X2 was corrected to a real gap — M0**), plus the audits' "document the
trust-level model" suggestion — a docs task, not roadmap-worthy. *(Quick-links/recents is **not** dropped — it's folded
into the Design-Polish Program, Pillar 2.)*

---

## 4. Suggested next step
Pick one and I'll produce it: (a) fold §3 into `ROADMAP.md` as the 1.1/1.2/1.3 rows; (b) write an **executable
handoff/kickoff spec** for the top workstream (1.1 or 1.2) in the `docs/product/*-kickoff.md` style Code can run cold;
or (c) open ADR stubs for the net-new surfaces (member table, quote-reply, topic subscriptions).
