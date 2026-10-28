# NovFora — Claude Code Session Kickoff
> **How to use this document:** Read this fully before writing a single line of code.
> Then read CLAUDE.md, PROJECT-STATE.md, and UI-AUDIT-FIX-SPEC.md in that order.
> This document is the master brief for the current frontend/UX enhancement program.
> Everything in it is grounded in the live codebase and beta feedback from the demo at
> https://novfora.com/community/ — it is not speculative.

---

## 1. Who This Is For and What We're Doing

This is a session handoff document for Claude Code on the NovFora repository
(`github.com/getnovfora/novfora`). The project is an open-source self-hosted forum platform
on Laravel 13 + PHP 8.3 + Livewire 4 + Alpine.js + Blade + Tailwind. Status: **v1.0.0 GA
shipped**. Current work phase: **post-1.0 Design-Polish Program + UI-AUDIT fixes +
frontend feature enhancements**.

**Goal of this session program:** Greatly enhance the frontend UI/UX for both end users
and admins. The forum is live at https://novfora.com/community/ with real beta testers
reporting issues. Everything below is grounded in that reality.

**Standing rules (full detail in CLAUDE.md):**
- `ultracode` default — start at Fable @ max, downgrade when work is pattern-replication
- Tests ship with every feature. Gates: `pint --test && php artisan test --parallel 2>&1 | tail -30`
- Commit identity: `Tommy Huynh <tommy@saturnhq.net>` with `-s` (DCO). No AI co-author trailers.
- Strict clean-room — never copy code or UI from reference forums
- Progressive enhancement — no Baseline feature may hard-depend on Redis/Reverb/Meili
- Reversible, non-destructive migrations only
- Ask before destructive operations or stack-changing dependencies

---

## 2. Current State — What's Already Done

Read PROJECT-STATE.md for the full picture. Short form:

- **Design-Polish Program** (6 branches) — built, gated green, **not yet merged to main**.
  Branches: `claude/polish-m0-prose-scope`, `claude/polish-p1-design-system`,
  `claude/polish-p4-toolbar`, `claude/polish-p2-acp`, `claude/polish-p3-member`,
  `claude/polish-p4-attachments`

- **v1.x Feature Program** (14 branches) — built and merged to local main, pushed to
  `origin/main` at `37c9cb8`. Includes: ACP member table, per-member admin view,
  warnings ACP, quote-reply, subscriptions (APEX), unread/excerpt/slug, canned replies,
  email templates (APEX), analytics charts.

- **v1.x Polish-2 + a11y** — fully merged and pushed. 42 files changed, 2128 tests passing.

- **UI-AUDIT-FIX-SPEC.md** — 21 diagnosed bugs with root causes confirmed against the
  codebase, exact file:line references, fix specs, test requirements, and model-rung
  assignments. The P0 (giant gear icon on admin section landing) has not been fixed yet
  and blocks all admin navigation. **This is the highest-priority item.**

- **Live beta feedback** sourced from https://novfora.com/community/forums/beta-feedback —
  bugs reported by active tester "dan" including: notification read-state not auto-updating,
  DM 403 error, lock thread showing to users without permission, mobile portrait nav
  spillover, scheduled reply error.

---

## 3. Key Files to Read Before Each Task

| File | Purpose |
|---|---|
| `CLAUDE.md` | Model routing, hard rules, conventions — read every session |
| `PROJECT-STATE.md` | Current state, merged branches, open follow-ups |
| `UI-AUDIT-FIX-SPEC.md` | 21 diagnosed bugs with file:line roots and fix specs |
| `ARCHITECTURE.md` | System design reference |
| `DECISIONS.md` | ADR log — check before any design decision |

---

## 4. Task Queue — Execute in This Order

---

### TASK 1 — Merge the Design-Polish Program
**Priority: Do this first. These branches are already green.**
**Model rung: Sonnet (mechanical merges) with Opus for the hand-merge.**

The six Design-Polish branches are built, gated, and waiting. Merge them now.

```
Read PROJECT-STATE.md and CLAUDE.md fully before doing anything else.

Six Design-Polish branches are built, gated green, and unmerged:
  claude/polish-m0-prose-scope    (scroll-trap hotfix)
  claude/polish-p1-design-system  (design system foundation)
  claude/polish-p4-toolbar        (editor toolbar)
  claude/polish-p2-acp            (ACP navigability + polish)
  claude/polish-p3-member         (member experience polish)
  claude/polish-p4-attachments    (attachments — APEX, adversarial review on record)

Merge them in dependency order: M0 → P1 → P4-toolbar → P2 → P3 → P4-attachments.
Run the gate after each merge:
  pint --test && php artisan test --parallel 2>&1 | tail -20

Expect a hand-merge between P4-toolbar and P4-attachments — both touch
novfora-editor.js and content-editor.blade.php (toolbar vs file-node/attach-zone).
Keep both sets of changes. Resolve additive; neither side's work should be dropped.

After each successful gate, commit the merge as:
  Tommy Huynh <tommy@saturnhq.net> -s (DCO), no AI trailers.

Report the post-merge test count and confirm origin/main is current before
touching any other work.
```

---

### TASK 2 — Execute UI-AUDIT-FIX-SPEC.md in Full
**Priority: High. BUG-001 blocks all admin navigation.**
**Model rung: Sonnet for most; Opus high for BUG-002, BUG-003, BUG-010, BUG-014.**

The spec already contains the complete diagnosis. This task is execution, not analysis.

```
Read PROJECT-STATE.md, CLAUDE.md, and UI-AUDIT-FIX-SPEC.md fully before writing
a single line. The spec has root causes confirmed against the codebase, exact file:line
references, and test requirements for all 21 findings.

Execute them in the sequencing the spec prescribes:

SLICE 1 — BUG-001 (P0) alone. Ship this first, gate, then continue.
  File: resources/views/admin/section.blade.php
  Root cause: no @extends layout envelope — emits bare <svg> gear icon
  Fix: add @extends('layouts.app', [...]) + @section('content') wrapper
  Mirror dashboard.blade.php exactly. The @php block moves inside @section('content').
  The replacement template is written out verbatim in the spec — use it.
  Test: GET /admin/{section} as admin returns <html>, not bare <svg>.
  Rung: Sonnet.

SLICE 2 — BUG-004 + BUG-005 + BUG-013 + BUG-021 (breadcrumb/title literal cluster).
  BUG-004: admin/structure.blade.php:13 — change title="Forums &amp; structure" → "Forums & structure"
  BUG-005: admin/structure.blade.php:7 — change 'Content' → 'Forums' in breadcrumb
  BUG-013/021: 9 breadcrumb literal edits across 8 view files (full list in spec).
    Rule: breadcrumb root = that section's top-level nav label, no cross-section nesting.
    Files: discovery/trending, whats-new/index, clubs/index, clubs/show, clubs/members,
           clubs/create, clubs/edit, notifications/index, admin/structure
  Rung: Sonnet. One commit per logical file group.

SLICE 3 — BUG-007 + BUG-008 + BUG-015 (pluralization — trans_choice).
  BUG-007: forum/partials/forum-row.blade.php
    lang/en/forum.php: 'topics' => 'topic|topics', 'posts' => 'post|posts'
    Views: trans_choice(__('forum.topics'), $forum->topic_count)
  BUG-008: discovery/partials/topic-line.blade.php
    Replace bare 'views' with: Str::plural('view', (int) $topic->view_count)
  BUG-015: moderation/dashboard.blade.php
    Replace "(s)" patterns with trans_choice strings (full wording in spec).
  Test: 1 topic → "1 topic", 2 → "2 topics". Same for posts, views, reports.
  Rung: Sonnet.

SLICE 4 — BUG-010 (presence/privacy — Who's Online vs badge mismatch).
  File: resources/views/components/⚡members-directory.blade.php
  Root cause: isOnline() ignores show_online_status; the count query requires it.
    Members with show_online_status=false are excluded from the count (correct)
    but still show a green Online badge (wrong — also a privacy leak).
  Fix: gate the badge on BOTH conditions:
    @if ($member->isOnline() && $member->showsOnlineStatus())
  Do NOT change User::isOnline() globally — it affects the topic sidebar badge too.
  Test: user with show_online_status=false + recent last_active_at → no badge AND
    not in count. With true → both show. Counts and badges agree.
  Rung: Opus high — presence/privacy correctness.

SLICE 5 — BUG-011 + BUG-009 (seed-data artifacts — run backfill + harden importer).
  BUG-011: php artisan migrate (runs existing idempotent backfill migration
    2026_06_12_000301_backfill_user_post_count.php).
    Going forward: Eloquent-created posts maintain post_count via model events (already correct).
  BUG-009: View counts are correct in the runtime path (TopicController@show increments).
    Zeros are from seeding. No controller change needed. Harden the importer to set
    view_count at insert if source data is available.
  Rung: Sonnet.

SLICE 6 — BUG-002 + BUG-003 (dual route binding — slug for forums, username for users).
  LOCKED DECISION: non-breaking dual resolver. Both numeric and slug/username forms must
  resolve. Slug/username is canonical for link generation. NO 301 redirects this pass.

  BUG-002: app/Models/Forum.php
    Add resolveRouteBinding(): resolve by id if ctype_digit, else by slug
    Add getRouteKeyName(): return 'slug'
    Update forum-row.blade.php:11 to pass $forum (model), not $forum->id
    Add slug backfill guard for any null-slug rows (booted() hook doesn't auto-generate).

  BUG-003: app/Models/User.php (mirror BUG-002)
    Dual resolver by id (numeric) or username (non-numeric). null-safe.
    getRouteKeyName(): return 'username'
    Update views that pass $member->id to profile routes.
    Username stays READ-ONLY this pass — no username-change input.

  Test: GET /forums/announcements resolves correct board; GET /forums/2 still works.
    GET /users/tommy resolves; GET /users/6 still works. Null username → reachable by id.
  Rung: Opus high — route-model binding on permission-scoped content.

SLICE 7 — BUG-012 + BUG-020 (activity feed limit + widget).
  BUG-012: app/Community/ActivityFeed.php currently hardcodes LIMIT=50.
    Make limit a runtime setting: config('novfora.activity_feed_limit'), default 20.
    ActivityFeed should accept a forLimit(int $limit) method.
    Add a "Recent activity limit" field in ACP Settings.
  BUG-020: add app/Theme/Widgets/RecentActivityWidget.php.
    Mirror the RecentTopicsWidget pattern: key()='recent_activity', a 'count' field
    (1–50, default 20), render() calling ActivityFeed with the clamped count.
    Register in app/Providers/ThemeServiceProvider.php.
  Test: limit setting caps the homepage feed; widget appears in Layout & Widgets.
  Rung: Sonnet (Opus high only if the setting touches cron-baseline cache invalidation).

SLICE 8 — BUG-016 → BUG-017 + BUG-018 → BUG-019 (profile + settings UI).
  BUG-016: resources/views/components/settings/shell.blade.php
    Convert 10 tabs (currently flex-wrap, wraps to 2 rows) to a left sidebar nav.
    grid grid-cols-1 lg:grid-cols-[14rem_1fr] gap-6
    Each settings section → vertical nav link (inactive: text-ink-muted hover:bg-surface-raised;
    active: bg-accent-soft text-accent font-medium). Scoped to shell.blade.php only.
    Do NOT touch the shared x-ui.tabs component.
    Test (Dusk): settings nav renders on single axis at standard desktop width. CI-pending.
    Rung: Sonnet.

  BUG-017 + BUG-018: resources/views/profiles/show.blade.php
    Add tabbed profile layout after the hero card: Activity | Posts | About
    Activity: reuse <livewire:community.activity-feed /> scoped to this user (pass $userId).
    Posts: query the user's non-deleted posts, scoped to forums the viewer can see
      (use existing PermissionResolver — this is Opus high; correctness-load-bearing).
      Pass $posts as a paginated collection from ProfileController::show().
      Render with post-excerpt card: topic title, snippet, date, reply count.
    About: move existing custom fields + signature here as the About tab.
    Use <x-ui.tabs> (existing component — BUG-016 fix should be done first).
    BUG-018: move staff-tools card (Delete account + ban/warn) into:
      <details><summary>Staff tools</summary>...</details>
      Collapsed by default. The gate ($canForceDelete) stays unchanged.
    Test: profile renders 3 tabs; empty states for no posts/activity; staff tools
      hidden from non-staff; present-but-collapsed for staff.
    Rung: Sonnet for view structure; Opus high for posts query (permission scoping).

  BUG-019: display name field in profile settings (LOCKED: display name only; username READ-ONLY).
    resources/views/profiles/edit.blade.php: add Display name input card before signature.
    ProfileController::update(): validate 'display_name' => ['nullable','string','max:100']
      and persist before $user->save().
    Do NOT add username input, cooldown column, or redirect logic.
    Test: update display_name persists; form has no username input.
    Rung: Sonnet.

SLICE 9 — BUG-014 (draft banner on blank editor — reproduce before fixing).
  REPRODUCE FIRST: load a topic you've never typed in as a fresh user.
    If the "Draft restored · Discard" banner shows on a blank form, the bug is confirmed.
    Root cause: TipTap emits {type:'paragraph'} for an empty doc, which passes
    empty($canonical['content']), so saveDraft() persists it and restoreDraft() flags it.
  Fix: add docIsBlank(?array $canonical): bool to app/Forum/Concerns/ManagesDrafts.php
    Returns true if no text node with non-whitespace content anywhere in the TipTap doc.
    Replace empty($canonical['content']) with $this->docIsBlank($canonical) in both
    saveDraft() (don't persist) and restoreDraft() (don't flag).
  Test: focusing editor without typing → no PostDraft created, no banner.
    Typing real text → autosaves → banner on reload. Discard clears it.
  Rung: Opus high — subtle persistence logic.

HONOUR THE RECLASSIFIED SECTION: BUG-006 is NOT a defect. Do not touch reaction
count badge logic. BUG-009 and BUG-011 are data artifacts — runtime paths are correct.

Gate after every slice: pint --test && php artisan test --parallel 2>&1 | tail -30
Commit: Tommy Huynh <tommy@saturnhq.net> -s, no AI trailers, one logical change each.
```

---

### TASK 3 — Profile Page Redesign
**Priority: Medium-high. Builds on BUG-017/018/019 above.**
**Model rung: Sonnet for view structure; Opus high for permission-scoped posts query.**

```
Read CLAUDE.md and the BUG-017/BUG-018/BUG-019 sections of UI-AUDIT-FIX-SPEC.md.
This task extends TASK 2 SLICE 8 with additional profile enhancements.

If SLICE 8 above is already complete, layer these on top:

1. Activity tab polish:
   The ActivityFeed component scoped to $userId should show:
   - Started a topic: topic title + forum name + timestamp
   - Replied: quoted snippet of the reply + topic title + timestamp
   - Reacted: "Reacted [emoji] on [topic title]"
   Each item links to the specific post. Max 20 items per page, paginated.

2. Posts tab (if not already implemented):
   Query: $user->posts()->with(['topic.forum'])->whereNull('deleted_at')
     ->whereHas('topic.forum', fn($q) => $q->whereIn('id', $viewableForum ids))
     ->latest()->paginate(20)
   The viewer's accessible forums are determined via the existing PermissionResolver.
   This is correctness-load-bearing — use Opus high.
   Render: card with topic title as linked heading, 2-line excerpt of post body,
     post date (relative), reply count on the topic.
   Empty state: "No posts yet." with a subtle text-ink-muted style.

3. About tab:
   Display name (from $user->display_name, falling back to $user->username)
   Join date (Member since [date])
   Custom profile fields (the existing $fields/$values loop)
   Signature (existing signature block)
   External links if the user has any in their profile fields

4. Profile hero card improvements:
   - Member since date visible in the hero (below the role badge)
   - Post count (from the backfilled users.post_count) visible as a stat pill
   - Reputation score if the v1.x reputation system is enabled (check config)
   - Follow button (if the follow system exists — check app/Social/ or similar)

Gate: pint + phpstan + php artisan test --parallel | tail -30
Add Pest feature tests for all new profile tab content.
Commit: Tommy Huynh <tommy@saturnhq.net> -s.
```

---

### TASK 4 — Forum Index Modernization
**Priority: Medium. High visual impact for first impressions.**
**Model rung: Sonnet — view-layer Blade/Tailwind only, no PHP logic changes.**

```
Read CLAUDE.md. This is a Blade/Tailwind visual redesign only. Do NOT change any
Livewire component logic, PHP controllers, or data structures.

Files to edit:
  resources/views/forum/index.blade.php
  resources/views/forum/partials/forum-row.blade.php

CURRENT PROBLEM: the forum index renders a stats-dense table per board, phpBB-style.
It looks dated compared to what the v1.0 announcement promises.

TARGET: modern card-based board layout that feels like Linear or Vercel's dashboard
while staying within NovFora's existing dark-theme token system.

Board cards (replace current table rows):
  Container: grid gap-3 (boards within a category stack vertically)
  Card: rounded-lg border border-line bg-surface-raised p-4 hover:border-line-hover
    transition-colors cursor-pointer group
  Board name: linked heading <a href="..."> text-base font-semibold text-ink
    hover:text-accent
  Description: text-sm text-ink-muted leading-relaxed mt-0.5 line-clamp-2
  Bottom row (stats + latest): flex items-center justify-between mt-3
    Left: topic count pill + post count pill
      span class="text-xs text-ink-subtle"
      e.g. "24 topics · 89 posts"
      Use trans_choice for pluralization (confirm BUG-007 is fixed first)
    Right: latest post author avatar (w-5 h-5 rounded-full) + truncated topic title
      (text-xs text-ink-subtle truncate max-w-[200px]) + relative timestamp (text-xs)

Category headers (replace current heavy dividers):
  h2 class="text-xs font-semibold uppercase tracking-wider text-ink-subtle
    pb-2 pt-4 first:pt-0"
  No border, no background. Clean label only.

Page layout:
  grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_280px] gap-6 items-start
  Main: the category + board cards
  Sidebar: Info Center + Who's Online + Recent Activity (leave existing structure;
    only the board cards and category headers change in this pass)

Sidebar Info Center widget:
  Keep the current statistics (Total posts/topics/members/Posts today/Newest member)
  but render them in a 2-column grid inside the widget card for a tighter layout.
  Use text-ink for numbers, text-ink-subtle for labels.

Mobile (below lg breakpoint):
  Single column. Sidebar drops below the board list.
  Board card descriptions remain (don't hide on mobile — they're useful).

Token reference (all already in the design system from the P1 polish branch):
  Backgrounds: bg-surface-base, bg-surface-raised, bg-surface-sunken
  Borders: border-line, border-line-hover
  Text: text-ink, text-ink-muted, text-ink-subtle
  Accent: text-accent, hover:text-accent, bg-accent-soft

Gate: pint --test && php artisan test --parallel 2>&1 | tail -20
(No PHP changes — suite should be unaffected. Visual review on demo after deploy.)
Commit: Tommy Huynh <tommy@saturnhq.net> -s.
```

---

### TASK 5 — Notification Read-State Fix (Beta Bug — High UX Impact)
**Priority: High. Reported by live beta tester.**
**Model rung: Opus high — Livewire reactivity + real-time correctness.**

```
Read CLAUDE.md before starting.

Live beta report (novfora.com/community/topics/31-bug-notifications-read-state-does-not-auto-update):
The notification bell shows new notifications. User clicks and views them. The read state
does not auto-update — items remain marked unread even after being viewed.

Investigate:
1. Find the Livewire component backing the notification bell
   (likely app/Livewire/Notification/ or similar — use an Explore sub-agent to locate).
2. Find where markAsRead() is called (should be on click/view).
3. Identify whether the unread count is reactive (does it re-render after mark-as-read?).
4. Check if there's a Livewire event being dispatched after mark-as-read that the bell
   component listens for. If not, that's the bug.

Fix pattern (whichever applies):
  Option A — Livewire event: after marking notifications read, dispatch a Livewire event
    that the bell component listens to and refreshes its unread count.
  Option B — Polling: if the bell component uses Livewire polling, ensure the read state
    is written before the next poll interval.
  Option C — Eager update: update the local state in the component immediately on click
    without waiting for the server (optimistic UI using wire:click + a property update).

Do NOT change the notification data model or database structure.
Prefer the most minimal fix that makes the read state visually update immediately when
a user views a notification.

Test:
  - User has unread notifications → bell shows count
  - User opens notification panel → items visible
  - After viewing, unread count drops to 0 (or to remaining unread count)
  - On page reload, the viewed notifications remain marked read

Gate: pint + phpstan + php artisan test --parallel | tail -30
Rung: Opus high.
Commit: Tommy Huynh <tommy@saturnhq.net> -s.
```

---

### TASK 6 — Mobile Navigation Fix
**Priority: High. Regression reported by beta tester (topic #21).**
**Model rung: Sonnet — Tailwind responsive classes only.**

```
Read CLAUDE.md.

Beta report (novfora.com/community/topics/21-ux-mobile-portrait-mode-titlemenu-bar-spillover):
The navigation bar overflows to a second row in mobile portrait mode. This is a recent
regression — it was working before. The spillover affects the title/menu bar area.

Find the main navigation component:
  Use an Explore sub-agent: "Find the Blade file containing the top navigation bar
  with the NovFora logo, Forums/Clubs/Trending/Groups links, search, sign-in buttons.
  Return the filename and the relevant flex/grid classes on the nav wrapper."

Audit for the spillover cause:
  1. Check for flex flex-wrap that should be flex flex-nowrap on the nav row
  2. Check for missing overflow-hidden or min-w-0 on the brand/logo container
  3. Check for any recently-added nav items that push the row past 100% width
  4. Check if the mobile hamburger (Alpine x-show) is working and the desktop nav
     links are hidden on mobile (if not, they'll cause overflow)

Fix requirements:
  - Logo stays left on all viewports
  - On mobile (< lg): only logo + hamburger/menu button visible in the nav bar
  - On desktop (>= lg): full nav with all links
  - No second row of nav content at 390px portrait viewport

Do NOT redesign the nav — minimal fix to stop the overflow only.
Test: manual visual check at 390px viewport width after deploy.
Also write a Pest feature test that GET / returns a response with the nav markup that
does not contain the spillover pattern (assert the nav links are behind an x-show
or hidden lg:flex pattern on mobile).
Gate: pint --test && php artisan test --parallel | tail -20
Commit: Tommy Huynh <tommy@saturnhq.net> -s.
```

---

## 5. Parallel Work — Handled by Other Tools

These tasks are being run in parallel by other AI agents. Claude Code should NOT
duplicate them. Reference only if the output needs to be integrated.

**Codex (background PRs — will become PRs for review):**
- Ghost UI: `@can/@cannot` gates on all moderation action buttons in topic/post views
- Pluralization sweep: BUG-007 + BUG-008 + BUG-015 (trans_choice)
- Breadcrumb literals: 9 edits across 8 files (BUG-013/021)
- Display name field in profile settings (BUG-019)

*If any of these PRs are already merged when you start TASK 2, skip the corresponding
slice and note it in your output.*

**Cursor (visual iteration, human-reviewed):**
- Typography pass on topic/post view
- Settings page sidebar nav (BUG-016 visual iteration)

**Antigravity (browser-verified audits):**
- Full screenshot audit of demo.novfora.com/community/ across 6 key pages
- Mobile viewport verification at 390px
- Post-fix verification screenshots

**Manus (research, no code):**
- Competitive UX analysis → will be saved to `docs/research/ux-competitive-brief-2026.md`
- Profile page UX research

---

## 6. Locked Decisions — Do Not Relitigate

These decisions are recorded in DECISIONS.md. Flag concerns but never silently change:

| Decision | What's locked |
|---|---|
| URL routing (BUG-002/003) | Non-breaking dual resolver. Both `/forums/2` and `/forums/announcements` resolve. Slug is canonical for generation. NO 301 redirects this pass. |
| Username editing (BUG-019) | Display name editing only. Username stays read-only. No cooldown column, no redirect logic. |
| Stack | Laravel 13, PHP 8.3, Livewire 4, Alpine.js, Blade, Tailwind. Vite prebuilt assets. No changes. |
| Two-tier architecture | Baseline = shared hosting, cron only. Enhanced = Docker/VPS with Redis/Reverb/Meili. No feature may hard-depend on enhanced services. Detect and degrade gracefully. |
| License | Apache-2.0. SPDX headers on all new files. |
| Clean-room | Never copy code, UI, templates, or assets from any reference forum. |
| Editor | WYSIWYG-first. BBCode is import/compatibility only. |

---

## 7. Model Routing Quick Reference

From CLAUDE.md — apply this for every turn:

| Work type | Model |
|---|---|
| Permission masks, `acl_entries`, `PermissionResolver`, ALLOW/NO/NEVER | **Fable @ max** |
| Concurrency/idempotency on cron-only baseline | **Fable @ max** |
| Untrusted-input / security boundaries | **Fable @ max** |
| Adversarial review workflows | **Fable @ max** |
| Permission/security/concurrency-adjacent work below apex | **Opus 4.8 `xhigh`** |
| Heavy Opus turns (route binding, presence/privacy, posts query) | **Opus 4.8 `high`** |
| CRUD/SFC scaffolding mirroring an existing pattern | **Sonnet 4.6** |
| Migrations, models, factories, config blocks | **Sonnet 4.6** |
| CSS/Tailwind token edits, view boilerplate | **Sonnet 4.6** |
| "Where is X rendered / which files touch Y" sweeps | **Explore sub-agent (Sonnet)** |

---

## 8. Execution Sequence

Work the tasks top-to-bottom. The dependency chain matters.

```
PHASE 1 — Unblock (do immediately)
  TASK 1: Merge 6 Design-Polish branches → unlocks design system tokens for all subsequent work
  TASK 6: Mobile nav fix → regression, affects all page views

PHASE 2 — Systematic audit fixes (TASK 2 in slice order)
  SLICE 1: BUG-001 (P0 — admin giant gear) → unlocks all admin testing
  SLICE 2: BUG-004 + BUG-005 + BUG-013/021 (breadcrumbs/titles)
  SLICE 3: BUG-007 + BUG-008 + BUG-015 (pluralization)
  SLICE 4: BUG-010 (presence/privacy) — Opus high
  SLICE 5: BUG-011 + BUG-009 (backfill + importer)
  SLICE 6: BUG-002 + BUG-003 (route binding) — Opus high
  SLICE 7: BUG-012 + BUG-020 (activity feed limit + widget)
  SLICE 8: BUG-016 + BUG-017 + BUG-018 + BUG-019 (settings + profile)
  SLICE 9: BUG-014 (draft banner — reproduce first) — Opus high

PHASE 3 — Feature enhancements
  TASK 5: Notification read-state fix — Opus high
  TASK 3: Profile page full redesign (tabs, hero stats, posts query) — builds on SLICE 8
  TASK 4: Forum index card redesign — Sonnet, view-layer only

PHASE 4 — Integration & verification
  After TASK 4: deploy to demo.novfora.com backup-first (cron auto-upgrade path)
  Run: scripts/build-release.sh && scripts/verify-release.sh
  Gate: php artisan test --parallel (should be 2128+ tests passing)
```

---

## 9. Key Conventions Reminder

```bash
# Every commit
git commit -s -m "type(scope): description" \
  --author="Tommy Huynh <tommy@saturnhq.net>"

# Never add AI trailers. .claude/settings.json keeps attribution off.

# Gate before every commit
pint --test && php artisan test --parallel 2>&1 | tail -30

# Gate output — always cap it
php artisan test --parallel 2>&1 | tail -30

# Sub-agent pattern for breadth sweeps (don't load 15 files into main context)
Agent(subagent_type: "Explore", model: "sonnet") → returns conclusions only

# Migrations must be reversible
# Never read a file you just edited (harness tracks state)
# No Redis/Reverb/Meili hard-deps on Baseline tier features
```

---

## 10. Open Follow-Ups (Not Part of This Session)

Tracked in PROJECT-STATE.md. Do not address in this session:

- Group clone button on live demo (suspect stale compiled-Blade/opcache on Hostinger)
- `novfora:trust:recompute --user` print terse output
- Pending-member exit-ramp (spec at docs/product/pending-member-review-kickoff.md)
- Pre-existing env-sensitive test reds: SubdirInstallTest + PwaTest subpath cases
- Asset-budget drift (needs npm run build on build host)
- Composer-audit transitive guzzlehttp advisories
- Stripe live keys / OAuth / Web Push / SAML (deferred, tracked in VALIDATE-BEFORE-GO-LIVE)
- Sole-admin/co-owner guard in BanController + WarningService front-end paths (flagged HIGH-class pre-existing)

---

*Document generated 2026-06-27. Source: live forum audit at novfora.com/community/,
codebase review of github.com/getnovfora/novfora, UI-AUDIT-FIX-SPEC.md (402 lines,
21 diagnosed bugs), PROJECT-STATE.md, CLAUDE.md.*
