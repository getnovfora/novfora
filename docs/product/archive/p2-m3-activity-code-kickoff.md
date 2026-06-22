<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M3 — Activity feed & community-feel pack — Claude Code kickoff

> Paste the block below into **Claude Code** to build **Phase 2 · M3 (Core items only)**.
> The Held items (follow half, reputation, badges) stay held — see §5 of the implementation plan.
> The activity feed introduces two new seams that have no direct precedent in the codebase:
> (1) a **`VisibleForumIds` resolver** for per-viewer permission-filtered queries and
> (2) a **short ADR-0025 addendum** to `AccountDeletionService` (the `activities` table didn't
> exist when that service was built). Get those right. The rest is Sonnet scaffolding.
>
> Authoritative specs: [phase-2-implementation-plan.md](../phase-2-implementation-plan.md) §2 (P2-M3)
> + §1 (engineering contract); ADR-0025 (deletion cascade — the addendum must honour it);
> ADR-0006 (permission engine).

---

```
Begin Phase 2 — M3 (Activity feed + community-feel pack). Core items only; Held items (follow,
reputation, badges, staff notes) stay held per §5. The activity feed has two correctness-critical
seams — the VisibleForumIds permission-filter and the AccountDeletionService addendum; everything
else is Sonnet scaffolding. Opus xhigh on those two seams and the cache boundary; Sonnet on the
migration/model/observer/SFC/stats/middleware scaffolding.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md, docs/product/phase-2-implementation-plan.md
§2 (P2-M3) + §1 (engineering contract). Then read:
  • app/Permissions/PermissionResolver.php — key methods: can(User, string, Scope): bool and
    explain(); request-scoped memo (flushMemo). This is what VisibleForumIds wraps.
  • app/Http/Controllers/ForumController.php — the view-side forum-visibility filter (line ~30).
    This is the CURRENT pattern; VisibleForumIds generalises it to a query-level WHERE clause.
  • app/Forum/ReactionService.php lines 183–222 — the RH-9 cache-primitive pattern to mirror
    (version-keyed key, scalar-only cache, rehydrate AFTER the boundary).
  • app/Account/AccountDeletionService.php — you will add one line to step (b).
Branch claude/p2-m3-activity off main AFTER account-deletion has merged. Commit identity per
CLAUDE.md (Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before
starting.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: (a) VisibleForumIds resolver design + the feed's WHERE-clause
    permission filter without N+1 per activity row; (b) the RH-9 cache boundary for the global
    feed (what to cache, what key, what TTL, where rehydration happens); (c) the
    AccountDeletionService addendum (pseudonymise activities.actor_id — privacy boundary).
  • Sonnet 4.6 — activities migration + model + factory + observers; ⚡activity-feed SFC;
    last_active_at middleware (throttled); throttled view-count increment; forum stats display;
    community-feel pack wiring.

KEY CODEBASE FACTS (verified — drives the design):
  • NO VisibleForumIds resolver exists. ForumController filters forums view-side per row via
    $viewer->canDo('forum.view', $forum->permissionScope()). The feed needs a query-level
    WHERE scope_forum_id IN (?visibleIds?) filter built from this same check. This is the new seam.
  • activities table and Activity model do NOT exist — greenfield.
  • AccountDeletionService does NOT pseudonymise activities.actor_id — the table didn't exist
    when it was written. Add the UPDATE in step (b) before the users row drops.
  • users.last_active_at column EXISTS (migration 2026_06_02_000101), not yet wired.
  • topics.view_count EXISTS (migration 2026_06_04_000202), no increment code yet.
  • forums.topic_count and forums.post_count EXIST (migration 2026_06_04_000201). Verify whether
    PostObserver / TopicObserver currently maintain them; if not, add the increments here.
  • user_relationships table (from M2 Half-B) has the follow type column but the follow half is
    NOT wired — do not touch it; the feed works without follow.

BUILD:

1) SCHEMA (reversible):

   activities(
     id,
     actor_id    unsignedBigInteger  nullable,  -- NO FK; pseudonymisable per ADR-0025 (mirror posts.user_id)
     verb        varchar(50)         NOT NULL,   -- 'topic.created' | 'post.created' | 'react.given'
     subject_type varchar(100)       NOT NULL,   -- polymorphic (morphs)
     subject_id   unsignedBigInteger NOT NULL,
     object_type  varchar(100)       nullable,   -- optional secondary target (e.g. the post for a reaction)
     object_id    unsignedBigInteger nullable,
     scope_forum_id unsignedBigInteger nullable  -- constrained(forums)->nullOnDelete
                                                 -- NULL = "no forum scope / visible to all"
                                                 -- NOTE: if a forum is hard-deleted, its scoped
                                                 -- activities become scope_forum_id = NULL and are
                                                 -- then visible to all. Flag this as a known edge
                                                 -- case in DECISIONS; it is acceptable for M3.
                                                 -- A future ForumObserver can delete-cascade them.
     created_at
   )
   Indexes: (actor_id, created_at), (scope_forum_id, created_at), (subject_type, subject_id, created_at).
   NO updated_at — append-only log. NO SoftDeletes.
   Verb constants as class constants on Activity.

2) ACCOUNT DELETION ADDENDUM (⚙ — app/Account/AccountDeletionService.php):

   In cascade(), step (b) "PSEUDONYMISE authored content", add ONE line:
     DB::table('activities')->where('actor_id', $target->id)->update(['actor_id' => null]);
   Position: after the reports pseudonymise, before the explicit-deletes in step (c). Same
   transaction. Add one regression test to the existing cascade test: after deletion,
   activities authored by the deleted user have actor_id = NULL; the verb/subject fields are
   intact; activities authored by OTHER users are unaffected.

3) VERB OBSERVERS (◻):

   Activity::record() static helper (wraps Activity::create, called after commit only — use
   Laravel's $dispatcher->afterCommit or wrap in a TransactionCommitted check so a rolled-back
   topic/post does not log an activity).

   Wire three event listeners (auto-discovered, queued or afterCommit):
     • TopicCreated (or TopicObserver::created) →
         Activity::record('topic.created', $topic, scope: $topic->forum_id)
     • PostCreated (new reply, not the topic-first post — check $post->position > 1 or equivalent) →
         Activity::record('post.created', $post, scope: $post->topic->forum_id)
     • Reacted (the existing domain event from M1) →
         Activity::record('react.given', $reaction->post, scope: $reaction->post->topic->forum_id)
         (subject = post; actor = reaction->user_id)

   DO NOT log PM/conversation activity — PMs are private by design; they have no scope_forum_id
   and must not appear in a public feed.

4) VISIBLE FORUM IDS (⚙ — app/Permissions/VisibleForumIds.php):

   Purpose: return the flat array of forum IDs the viewer can see, for use in the feed
   WHERE clause. This generalises the per-row view-side check in ForumController.

   public static function for(User $viewer): array<int>

   Implementation:
     • Load the full forum tree (Forum::with('children')->whereNull('parent_id')->get() —
       same as the index; the tree is already memo'd by the forum index on most requests).
     • Recursively call PermissionResolver::can($viewer, 'forum.view',
       $forum->permissionScope()) per node. The resolver's own request-scoped memo means
       this does NOT cause N+1 DB hits on a warm request.
     • Return the flat array of visible IDs.
     • If the viewer is a superadmin (or the array would cover ALL forums), return null
       rather than a huge IN list, and let the feed query omit the scope filter (or use a
       sentinel value). Decide and document the sentinel.
     • Cache result in a static property for the lifetime of the current request (NOT across
       requests — permissions can change).

   This seam is forward-compatible with M4 search facets and must be built correctly even
   though M3 is its first consumer.

5) ACTIVITY FEED SFC (◐ — resources/views/components/community/⚡activity-feed.blade.php):

   Query:
     $visibleIds = VisibleForumIds::for($viewer);  // array|null
     Activity::query()
       ->when($visibleIds !== null,
           fn($q) => $q->where(fn($q2) =>
               $q2->whereNull('scope_forum_id')
                  ->orWhereIn('scope_forum_id', $visibleIds ?: [-1]) // -1 guard if empty
           )
       )
       ->latest()
       ->limit(50)
       ->get();

   IMPORTANT: if $visibleIds is an empty array (user can see NO forums), return empty
   immediately — do NOT run an IN() query with an empty set.

   RH-9 cache discipline (mirror ReactionService pattern):
     • Cache key: "novfora.activities.feed.v{$globalVersion}" where $globalVersion is a
       simple integer stored in Cache (or config) and incremented on each Activity::created
       model event. A short TTL (60 s) is acceptable as belt-and-braces.
     • Cache ONLY the raw primitive arrays (scalar fields). Do NOT cache Eloquent models,
       related objects, or the permission filter result.
     • Resolve subject models (Topic, Post) and actor User AFTER the cache boundary.
       Use eager-loading (whereIn on the distinct subject/actor IDs from the cached rows),
       not per-row lazy loads.
     • Add a cache-HIT test: load feed twice; assert query count on the second load equals
       the rehydration queries only (no re-fetch of the raw activities rows).

   Display rules:
     • NULL actor → '[Deleted]' + guest avatar (reuses the x-ui.user-name :fallback component
       added in the account-deletion build — confirm it exists before wiring).
     • Subject model soft-deleted or missing → show a tombstone row, never throw.
     • No route to a deleted subject (check ->trashed() or ->exists() before linking).

   Query budget: feed page ≤ 20 queries (add to QueryBudgetTest).

6) LAST_ACTIVE_AT MIDDLEWARE (◻):

   App\Http\Middleware\ThrottledLastActive (web middleware group, authenticated only):
     If now() - $user->last_active_at > 5 minutes:
       DB::table('users')->where('id', $user->id)->update(['last_active_at' => now()]);
     No model hydration (avoid triggering observers). Throttle prevents a write per request.

   User model: add isOnline(): bool { return $this->last_active_at?->gt(now()->subMinutes(15)) ?? false; }
   Online indicator: wire into ⚡activity-feed actor display (small green dot) + any profile
   header that shows the user. A dedicated x-ui.online-badge component is fine (Sonnet).

7) VIEW-COUNT INCREMENT (◻):

   In the topic show controller (or a dedicated Livewire action), after auth/policy check:
     $cacheKey = "topic.viewed.u{$viewerId}.t{$topic->id}";  // for authenticated users
     $cacheKey = "topic.viewed.s{$sessionId}.t{$topic->id}"; // for guests (session id)
     if (Cache::add($cacheKey, 1, now()->addHour())) {
         DB::table('topics')->where('id', $topic->id)->increment('view_count');
     }
   No model hydration. Add a test: first view increments; second view (same window) does not.

8) FORUM STATS DISPLAY (◻):

   Verify that PostObserver / TopicObserver currently maintain forums.topic_count and
   forums.post_count. If they DO: wire the display into the forum index card (Sonnet, no new
   schema). If they DO NOT: add the increments in the relevant observer(s) and test them.
   Either way, the columns exist and displaying them is low-risk.

DEFINITION OF DONE (binding):
  • activities migration + model + factory + verb constants.
  • Verb observers wired: topic.created, post.created, react.given logged correctly; PM activity
    NOT logged.
  • VisibleForumIds unit tests: user with no forum.view on a forum excluded from returned IDs;
    superadmin / no-restriction user returns null sentinel; empty-forum-list case handled.
  • Feed query correct: scope_forum_id filter; empty-visibleIds guard; NULL-actor tombstone; soft-
    deleted subject tombstone; subject models rehydrated from cache, not N+1 lazy-loaded.
  • QueryBudgetTest extended: feed page ≤ 20 queries.
  • RH-9 cache-HIT test: second load of feed hits zero activity-row re-queries (primitives served
    from cache; only rehydration queries run).
  • ADR-0025 addendum: activities.actor_id = NULL after cascade; verb/subject intact; other users'
    activities unaffected; all in one transaction.
  • last_active_at: isOnline() returns true < 15 min, false after (mock now()).
  • View-count: first view increments, repeat in window does not (Cache::add idempotency test).
  • Forum stats: forums.topic_count / post_count displayed on forum index; maintained by
    observers (add tests if not already maintained).
  • Dusk journey: load forum index → activity feed visible; post a reply → appears in feed;
    view a topic → view_count increments; online dot appears for active user.
    Light/dark × mobile/desktop screenshots (p2m3-*).
  • Pint / Larastan L5 / composer+npm audit / assets-fresh green.
  • Full suite green; PROJECT-STATE + DECISIONS updated (cache-key design, sentinel choice,
    scope_forum_id nullOnDelete edge-case). Small conventional DCO commits.

SCOPE FENCE — activity feed (global, permission-filtered, Core) + VisibleForumIds seam +
AccountDeletionService addendum + last_active_at middleware + throttled view-count + forum stats
display ONLY. HELD (do not wire even if tempting): follow half of user_relationships (table
exists from M2 Half-B — do NOT wire follow-based personalisation; the feed works globally without
it); reputation/points; badges; staff notes; 2nd theme. VisibleForumIds is built for the feed but
not connected to M4 search (forward-seam only — don't expand its consumers here). Reversible
migrations only (no expected schema changes to existing tables beyond the one addendum line in
AccountDeletionService). Strict clean-room. If a needed mechanism isn't in the engine, FLAG it.
When Core items land runnable + tested, report back here.
```

---

## When M3 reports back

Cowork reviews: (1) does the **VisibleForumIds resolver** correctly exclude forums the viewer
lacks `forum.view` on — verified by a unit test, not just by inspection? (2) is the activity feed
**cache boundary clean** — primitives only in the cache, models rehydrated after, cache-HIT test
passes with zero activity-row re-queries? (3) is the **ADR-0025 addendum** in the same transaction
as the rest of the cascade, and does it run BEFORE the users row drops? (4) does the **view-count
increment stay idempotent** — repeated views in the same window do not increment? (5) is **PM
activity absent from the feed** (no conversation/message verbs logged)? Then updates PROJECT-STATE.

## After this

- **M3 Held items** (follow-based feed personalisation, reputation, badges) — greenlight
  individually when ready; `user_relationships` follow half and `reputation_events` seam are
  both in place.
- **M4** — moderation depth: cross-page bulk select, merge/split topics, search facets,
  consolidated preferences. VisibleForumIds from M3 unblocks search facets.
- **M5** — theme proof, beta polish, public-beta milestone.
