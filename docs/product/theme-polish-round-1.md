<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# Theme polish — round 1 (owner visual feedback) — Claude Code kickoff

> Owner feedback from the first themed deploy (screenshots in `1st_Visual_Feedback/`, gitignored-local).
> Reference feel: ProBoards board view (sub-boards box + info-dense thread table), SMF/phpBB/XenForo info
> richness. **Part A executes NOW on the open PR #3 branch (`claude/default-theme`).** Part B is the
> triaged functionality backlog — do NOT build it in this round.

---

## Part A — polish round on `claude/default-theme` (binding)

```
Execute owner polish round 1 on the existing claude/default-theme branch (PR #3 stays open — do not
merge). Presentation only; every gate from theme-phase-kickoff.md still applies. The data model already
holds everything needed (topics: reply_count, view_count, last_post_user_id, last_posted_at; forums:
last_topic_id, last_posted_at, children) — no new columns, no new behavior.

STEP 0: checkout claude/default-theme; confirm clean + up to date with origin. Commit identity per
CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution).

1) TOPIC VIEW — classic LEFT poster sidebar (owner-chosen default):
   • Desktop (≥ md): poster info in a left column per post — avatar (larger), display name, a staff/role
     badge where derivable from already-loaded data, joined date / post count ONLY if available without
     new queries (eager-loaded author attributes). Post body right of it.
   • Mobile: collapses to the current top-bar layout (avatar+name+time above body).
   • Preserve the Dusk selectors and .novfora-prose contract; moderation controls keep working.
   • The admin-switchable top/left/right option is BACKLOG (Part B) — build only the left default.

2) BOARD VIEW (forum/show) — info-dense classic topic table (ProBoards reference):
   • Desktop: a real table with headers — Subject · Replies · Views · Last Post. Subject cell = topic
     title + "by <starter>" beneath; Last Post cell = last poster's name + relative time, linking to the
     topic's latest page. Tabular numerals; pinned/locked badges stay on the subject cell.
   • view_count renders as stored (incrementing it is a behavior change — Part B).
   • Mobile: reflow to stacked rows (subject + meta line) — no horizontal scrolling (brief hard rule).

3) SUB-BOARDS BLOCK (board view): when the forum has children, render a "Sub-boards" card ABOVE the
   topic table (ProBoards-style): each child = name, description, topic/post counts, last activity.
   Permission-filter children with the same forum.view check the index uses.

4) FORUM INDEX rows — add last-post info: right-aligned "latest activity" per forum row on desktop
   (last_posted_at + last topic link where loadable without N+1 — the forums table carries
   last_topic_id/last_posted_at). Counts stay. Collapses cleanly on mobile.

5) BREADCRUMBS: make the trail a prominent bar directly under the header on board + topic pages (the
   ProBoards nav-tree placement) — consistent placement, not buried.

GATES (unchanged from the theme kickoff): tokens only (no raw hex/palette steps) · AA in both modes ·
CSS budget reported (≤ ~50 KB gz) · full Pest + Dusk green (selectors may be UPDATED, never weakened) ·
assets rebuilt + committed (assets-fresh passes) · REFRESH the screenshot set (board table + sub-boards +
left-sidebar posts, light/dark × mobile/desktop) and update the PR description · rebuild
scripts/build-release.sh + verify; report new bundle size + sha256.

DELIVER: push to the branch; summarize per-item what changed; updated screenshots in the PR. Do not merge
— the owner re-reviews first.
```

## Part B — triaged backlog (functionality; do NOT build in this round)

**"Community-feel pack" — proposed as the first Phase 2 slice** (these are what make the index feel like
SMF/MyBB's living communities):
1. **Info center / statistics block** on the forum index: totals (topics, posts, members), newest member,
   latest posts list. Requires queries/widgets — and **who's-online** requires presence tracking (real
   feature: session-based, guest counting, bot filtering).
2. **Staff/group name colors** — admin-pickable color per group (admin/global-mod/mod), rendered on
   usernames in posts, online lists, member lists.
3. **View-count incrementing** — topics.view_count exists but nothing increments it yet; add rate-limited,
   bot-aware view tracking so the new Views column is live data.
4. **Poster-info position option** (top / left / right) — admin setting; left ships as default this round.

**Phase 3 (configurator, ADR-0009 — already planned):** admin layout presets (info-rich vs minimal),
per-site accent color, layout/widget arrangement — the screenshots' "admins decide layout" asks land here.

**Direction note (continuous):** benchmark the community *feel* against the reference boards
(simplemachines.org/community, MyBB community) each phase — density, information scent, "where do I post
next" affordances. The goal is a forum that invites replies, not a dashboard.
