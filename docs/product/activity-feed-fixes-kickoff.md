# Branch 3 — Recent Activity fixes — Build Spec

> Handoff spec (Batch 2026-06-21, Branch 3). Three issues in the activity feed: a **permission-
> visibility leak** (apex-adjacent), an empty/sparse feed for restricted viewers, and the profile tab
> ignoring the configurable limit. **ultracode — xhigh for the visibility filter (it gates what a guest
> can see); Sonnet for the limit/profile bits.** Branch `claude/activity-feed-fixes` off `main`, gated,
> git on the VPS.

## 1. Goal
The Recent Activity feed (a) never shows a viewer activity from a forum they can't see — including
orphaned rows from a deleted private forum, (b) isn't empty for a restricted-but-active viewer, and
(c) respects the admin's configured item limit everywhere.

## 2. Background (from the code)
- **Surfaces:** homepage Livewire `resources/views/components/community/⚡activity-feed.blade.php`
  (reads `Settings::int('general.activity_feed_limit')`, default 15, clamped [1,50]); the sidebar
  `app/Theme/Widgets/RecentActivityWidget.php` (own `count`, [1,50], default 20); the **profile Activity
  tab** (`app/Http/Controllers/ProfileController.php` → `ActivityFeed::forActor($viewer,$user,20)`,
  **hardcoded 20**, ignores the setting).
- **Backing:** `App\Models\Activity` / `activities` table (append-only; `actor_id, verb, subject_*,
  object_*, scope_forum_id (FK, nullOnDelete), created_at`). `app/Community/ActivityFeed.php`:
  `loadWindow()` fetches the latest **100** rows (`orderByDesc('id')->limit(100)`), version-keyed cache
  60s; `page()` clamps to the requested limit and applies the per-viewer permission filter via
  `app/Permissions/VisibleForumIds.php`.
- **The two real problems in `page()`:**
  1. **Visibility leak.** The first-pass filter passes any row where `scope_forum_id === null`. A **hard
     forum delete** nulls `scope_forum_id` (`nullOnDelete`), so that forum's past activity becomes
     `null`-scoped and shows to **everyone, including guests**. For full-visibility viewers
     (`visibleIds === null`) both permission passes are skipped, and for restricted viewers a row whose
     subject was also deleted resolves `topic()?->forum_id` to `null` and still passes. Net: actor names
     (and verb) from a now-deleted **private** forum can surface to guests. DECISIONS.md notes this as an
     accepted "M3" edge case — we're closing it now.
  2. **Window underflow.** The 100-row window is global, then filtered per viewer. A viewer restricted to
     low-traffic forums can have **all 100** window rows be from forums they can't see → an **empty feed**
     even though their forums had recent (but older-than-window) activity.

## 3. Scope / Non-goals
**In scope:** fix the null-scope leak; fix restricted-viewer underflow; make the profile tab honor
`general.activity_feed_limit`. **Non-goals:** no new activity verbs/sources; don't change the
`RecentActivityWidget`'s independent count (that's a deliberate per-widget control — but it MUST inherit
the leak fix since it shares `ActivityFeed`); the server-side `diffForHumans` timezone nicety is optional
(only if trivial). No change to what gets *written* to `activities`.

## 4. The fixes

### 4.1 Close the visibility leak — **xhigh (apex-adjacent)**
A `null` `scope_forum_id` must **not** mean "visible to everyone." In `ActivityFeed::page()`:
- Run the permission filter for **all non-staff viewers**, not only restricted ones (don't skip it for
  `visibleIds === null` if that viewer is a guest/regular member — only a genuine see-everything actor,
  e.g. staff/admin, should bypass).
- For a row whose `scope_forum_id` is null, resolve the **live subject's** `forum_id` and check it
  against the viewer's visible set. If the subject is gone/unresolvable (orphaned tombstone), **exclude
  it for non-staff viewers** rather than showing it. A guest must never see a row that originated in a
  forum they couldn't see.
- Keep legitimate tombstones (e.g. soft-deleted subject still in a *visible* forum) working.
Record the chosen rule in a short `DECISIONS.md` ADR (supersede the "accepted M3 edge case" note). If you
judge a schema fix cleaner (FK `cascadeOnDelete` so a hard forum delete removes its activity rows), note
it as the alternative in the ADR but prefer the **non-destructive filter fix** for this branch.

### 4.2 Fix restricted-viewer underflow
For a restricted viewer, **push the visible-forum constraint into the query** (`whereIn('scope_forum_id',
$visibleIds)` plus the null-handling from 4.1) so the 100-row window is 100 rows the viewer *can* see —
instead of filtering a global window down to nothing. Keep the global window + cache for see-everything
viewers. Because this makes the result viewer-dependent, **include the viewer's visible-forum signature
in the cache key** (or cache only the see-everything window and run restricted viewers through the
filtered query). Acceptance: a viewer whose only visible forum is low-traffic still sees that forum's
recent activity when it exists within a reasonable depth, not an empty feed.

### 4.3 Profile tab honors the limit — Sonnet
`ProfileController::show()`: replace the hardcoded `20` with `Settings::int('general.activity_feed_limit')`
(same clamp as the homepage). Confirm `forActor()` applies the same visibility rules as 4.1 (it should
already be per-actor; verify no leak there either).

## 5. Verification / done
Gates green. Tests (`tests/Feature/Community/ActivityFeedVisibilityTest.php` + an underflow test):
- **Leak:** seed activity in a private forum; hard-delete the forum (nulling `scope_forum_id`); a guest
  and a non-member regular user get an activity feed that **does not** include those rows; a staff/admin
  viewer may still see them. A topic *moved* into a restricted forum after logging is also excluded for a
  non-member.
- **Underflow:** with >100 recent rows dominated by a forum the viewer can't see, plus some older rows in
  a forum they *can*, the restricted viewer's feed is **non-empty** and shows only their visible rows.
- **Limit:** the profile Activity tab returns `general.activity_feed_limit` items (clamped), not a fixed
  20.
- The homepage feed and the sidebar widget both reflect the leak fix.
PR to `main` (do not merge); call out the visibility rule for the adversarial review on the Cowork side.

## 6. Commit
Branch `claude/activity-feed-fixes` off `main`; small conventional commits (visibility filter + ADR →
underflow query → profile-tab limit); `-s`, `Tommy Huynh <tommy@saturnhq.net>`; clean-room. PR to `main`.

Read docs/product/activity-feed-fixes-kickoff.md and execute it.
