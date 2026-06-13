<!--
SPDX-License-Identifier: Apache-2.0
Copyright 2026 The NovFora Authors
-->
# P2-M4 — Moderation depth, search facets & consolidated preferences — Claude Code kickoff

> Paste the block below into **Claude Code** to build **Phase 2 · M4**. Four Core deliverables:
> (1) **merge / split topics** — the most correctness-critical piece (Opus `xhigh` on the
> transactional recount); (2) **cross-page bulk select** with per-item rank guard;
> (3) **search facets** — author / forum / date / tag / type, wiring the M3 `VisibleForumIds`
> seam into `SearchService`; (4) **consolidated user-preferences** (folding the remaining
> preference surfaces). Staff notes stay **Held**.
>
> Authoritative specs: [phase-2-implementation-plan.md](phase-2-implementation-plan.md) §2
> (P2-M4) + §1 (engineering contract); ADR-0006 (permission engine).

---

```
Begin Phase 2 — M4 (Moderation depth, search facets, consolidated preferences). Four Core
deliverables; staff notes stay Held. Opus xhigh on the merge/split transaction + recount
correctness + bulk rank guard; Sonnet on the bulk-select UI, search facet UI, and preferences
SFC.

START OF SESSION: read PROJECT-STATE.md, CLAUDE.md,
docs/product/phase-2-implementation-plan.md §2 (P2-M4) + §1. Then read:
  • app/Models/Post.php — Post::syncAggregates() (lines 99–133): this is the per-post counter
    updater; merge/split must NOT trigger it per-moved-post (N+1 storm). It owns reply_count,
    last_post_id, last_post_user_id, last_posted_at, and forum.post_count.
  • app/Models/Topic.php — Topic::adjustForumTopicCount() (lines 97–101): owns forum.topic_count.
  • app/Support/ActorRank.php — canActOn(User $actor, User $target): bool. Accepts Users only;
    bulk actions on posts need canActOn($actor, $post->author) — eager-load authors.
  • app/Http/Controllers/SearchController.php + app/Search/SearchService.php — the current
    search (body_text only, no facets, visibility + approved gate, 25 results).
  • app/Permissions/VisibleForumIds.php — the M3 seam; wire it into SearchService for
    forum-scoped search (do NOT rebuild it).
Branch claude/p2-m4-moderation off main AFTER M3 has merged. Commit identity per CLAUDE.md
(Tommy Huynh <tommy@saturnhq.net>, DCO -s, no AI attribution). Suite green before starting.

MODEL/EFFORT (CLAUDE.md routing):
  • Opus 4.8 xhigh — THINK HARD: MergeTopicsService / SplitTopicService transaction (bulk-move
    posts WITHOUT triggering syncAggregates per-row; authoritative recount after; moved_to_topic_id
    redirect; last_post_* pointers on both topics; forum counter correction); the bulk-select rank
    guard (canActOn per post-author, silent-skip ineligible items, never bypass).
  • Sonnet 4.6 — bulk-select Alpine store + SFC UI; search facet form + SearchService WHERE
    clauses; ⚡user-preferences SFC; ACP merge/split trigger UI.

KEY CODEBASE FACTS (verified — drives the design):
  • topics.moved_to_topic_id — EXISTS as bare nullable unsignedBigInteger (no FK). Greenfield
    for move/merge/split implementation.
  • Post::syncAggregates(int $forumDelta) — called from Post::booted() on created/deleted/
    restored. Bulk-moving posts must bypass this observer (use a raw UPDATE + explicit recount
    after) to avoid N+1 writes and double-counting. Add a post_count_recompute() helper or
    reuse the existing recount pattern from ReactionService::recomputeForPosts.
  • MergeTopicsService / SplitTopicService — do NOT exist. Greenfield.
  • ActorRank::canActOn(User, User) — User-only. For bulk post actions, load $post->author
    eagerly, then filter: $posts->reject(fn($p) => !ActorRank::canActOn($actor, $p->author)).
    Silently skip ineligible items (do not error); audit which items were skipped.
  • SearchController + SearchService — exist; Posts-only; body_text; no facets yet.
    Scout driver = database (baseline). toSearchableArray() returns body_text only — add
    user_id, topic_id, forum_id (via relation), created_at for facet WHERE clauses.
  • VisibleForumIds::for(User): ?array — EXISTS (M3). Use it in SearchService to restrict
    results to forums the viewer can see. Do NOT rebuild it.
  • Settings shell — 5 tabs: Profile, Appearance, Notifications, Security, Account.
    ⚡notification-preferences and ⚡delete-account already exist (M2 Half-A + account-deletion).
  • Staff notes (staff_notes table / StaffNote model) — do NOT exist. HELD — do not build.

BUILD:

1) MERGE TOPICS (⚙ — app/Forum/MergeTopicsService.php):

   merge(Topic $source, Topic $target, User $actor): void
   Gate: $actor->canDo('topic.moderate', $source->permissionScope()) AND same for $target.
   ActorRank: the actor must outrank (or equal per config) the source topic's author —
   canActOn($actor, $source->author).

   ONE DB::transaction:
     a. Verify source !== target; both approved and not already merged.
     b. Bulk UPDATE posts SET topic_id = $target->id WHERE topic_id = $source->id.
        Do NOT use $post->save() (triggers syncAggregates per-row → N+1).
        Use DB::table('posts')->where('topic_id', $source->id)->update(['topic_id' => $target->id]).
     c. Set topics.moved_to_topic_id = $target->id on $source.
     d. Soft-delete $source (it becomes a redirect shell — never hard-delete so moved_to_topic_id redirect works).
     e. Authoritative recount on $target: re-derive reply_count, first_post_id, last_post_id,
        last_post_user_id, last_posted_at from posts WHERE topic_id = $target->id. Use direct
        SQL aggregates (no model loading). Update forums.post_count and forums.topic_count for
        both $source->forum and $target->forum (source loses its posts + is removed; target gains).
     f. Audit::log('topic.merged', $source, ['into' => $target->id, 'by' => $actor->id]).
   Redirect: wire the moved_to_topic_id in the topic show route — if a topic has
   moved_to_topic_id set, issue a 301 to the target topic.

2) SPLIT TOPIC (⚙ — app/Forum/SplitTopicService.php):

   split(Topic $source, array $postIds, string $newTitle, User $actor): Topic
   Gate: $actor->canDo('topic.moderate', $source->permissionScope()); canActOn each moved
   post's author.

   ONE DB::transaction:
     a. Validate $postIds are non-empty, all belong to $source, none is the OP (post_id ==
        $source->first_post_id cannot move — it would orphan the original topic).
     b. Create the new Topic ($newTitle, same forum as source, $actor as created_by,
        approved_state='approved').
     c. DB::table('posts')->whereIn('id', $postIds)->update(['topic_id' => $newTopic->id]).
        Again: raw UPDATE, not per-model (avoids N+1 syncAggregates).
     d. Authoritative recount on BOTH $source and $newTopic (same pattern as merge step e).
     e. Audit::log.
   Return $newTopic so the caller can redirect.

3) MERGE / SPLIT TRIGGER UI (◻):

   No full ACP member/topic-management page yet. Mirror the SpamCleaner trigger surface:
   add moderation-toolbar actions on the topic view (lock/pin already implied by topic.moderate;
   add Merge and Split actions). A Livewire-powered inline modal is fine:
     • Merge: pick a target topic (search autocomplete by title, same forum); confirm; call
       MergeTopicsService.
     • Split: checkbox-select posts in the thread; provide new title; confirm; call
       SplitTopicService.
   Both gated by $actor->canDo('topic.moderate', ...) in mount() AND the action.

4) CROSS-PAGE BULK SELECT (◐):

   The selection state persists across Livewire page navigations using an Alpine.js store
   ($store('bulkSelect', { ids: [] })). The store survives wire:navigate within a page session.

   Scope for M4: bulk actions on POSTS (within a topic) and on TOPICS (within a forum listing).

   Post bulk actions (gated post.delete.any / post.edit.any per CLAUDE.md routing):
     • Bulk delete (soft-delete)
     • Bulk hide / unhide (if a hide/unhide status exists — check before implementing; if not,
       skip and note in DECISIONS)
   Topic bulk actions (gated topic.moderate):
     • Bulk lock / unlock
     • Bulk move to another forum
     • Bulk delete (soft-delete)

   RANK GUARD (⚙ — do not skip): for every item in the bulk selection, before applying the
   action, check ActorRank::canActOn($actor, $item->author). Eager-load authors on the
   selected set (whereIn + with('author')). Silently filter out items where the actor cannot
   act; apply the action only to the eligible subset. Audit both the applied set and the
   skipped set. Never throw on an ineligible item — skip and report.

   UI: a "Select" mode toggle on topic-list and post-list views. When active, checkboxes appear
   per row. A floating action bar (Alpine, fixed bottom) shows the count + available actions.
   A "select all on page" checkbox; a "select across pages" link that stores all IDs matching
   the current filter (not just the visible page). Clear selection on action completion.
   #[Locked] on any injected IDs in the Livewire component.

5) SEARCH FACETS (◐):

   Extend SearchService to accept facet parameters. For the DB Scout driver, facets are
   additional WHERE conditions on the Scout query. Add to Post::toSearchableArray():
     user_id, topic_id, created_at
   And add forum_id as a computed field via the topic relation (needed for the forum facet WHERE).

   Facets (all optional, combinable):
     • author — WHERE user_id = ?
     • forum — WHERE forum_id IN (?visibleIds filtered to the selected forum)
     • date — WHERE created_at BETWEEN ? AND ?
     • tag — JOIN taggables WHERE taggable_type='App\Models\Topic' AND tag_id IN (?)
              (tags apply to topics, not posts; join via post→topic→taggables)
     • type — 'post' (default) vs 'topic' (match only first posts, i.e. id = topic.first_post_id)

   VisibleForumIds integration: wrap all search results in the forum visibility gate.
   If the viewer can see only certain forums, add WHERE forum_id IN (?) to every search query.
   Use VisibleForumIds::for($viewer) — already in the codebase (M3). Do NOT rebuild it.
   If $visibleIds === null (sees-all), omit the forum filter.
   If $visibleIds === [], return empty results immediately.

   Forced-absence: the DB driver is the baseline; Meilisearch is enhanced-tier. The facet
   implementation must work on the DB driver. When SCOUT_DRIVER=meilisearch, facets use
   Meilisearch's native filter syntax — add a SearchService abstraction layer so the facet
   parameters translate correctly for each driver. Forced-absence test: search with all facets
   on the DB driver returns correct results.

   UI (◻): extend the search form with collapsible facet controls (Sonnet). Facet state in
   query params (GET, bookmarkable). Result count per facet shown where practical.

6) CONSOLIDATED USER-PREFERENCES (◻):

   The settings shell already has 5 tabs (Profile, Appearance, Notifications, Security, Account).
   ⚡notification-preferences and ⚡delete-account already exist.

   Add a new "Preferences" section (or extend an existing tab) covering:
     • Post display: posts per page (if configurable), thread sort order (oldest/newest first)
     • Forum preferences: default forum sort, show-signatures toggle (if signatures exist)
     • Any other per-user display preferences not currently in Appearance
   If there is nothing meaningful to add beyond what exists, record that in DECISIONS and skip
   this item — do not add a preferences page for its own sake.
   Existing notification-preferences and appearance settings remain in their current tabs
   (do not reshuffle without a clear UX gain — a shuffle for its own sake is churn).

DEFINITION OF DONE (binding):
  • MergeTopicsService: reply_count / last_post_* / forum counters correct after merge
    (assert with direct DB queries, not model reads); moved_to_topic_id redirect returns 301;
    source soft-deleted; audit log entry present; all in ONE transaction (rollback test).
  • SplitTopicService: OP post cannot be split away (assert blocked); new topic has correct
    reply_count; source recount correct; all in ONE transaction (rollback test).
  • Bulk select: rank guard silently skips ineligible items (test: actor cannot bulk-delete
    posts by a higher-ranked user); eligible items receive the action; skipped items are audited.
  • Search facets: author / forum / date / tag / type facets each return correct results on DB
    driver; forum facet respects VisibleForumIds (restricted viewer cannot see posts from
    inaccessible forums); forced-absence test (all facets on DB driver); empty-visibleIds guard.
  • VisibleForumIds NOT re-implemented — existing M3 class used.
  • Consolidated preferences: any new pref surfaces tested (own-only, auth asserted in mount +
    every action); or DECISIONS records that nothing meaningful was added.
  • QueryBudgetTest extended: search results page ≤ 25 queries; topic-with-bulk-select ≤ 35.
  • Dusk journeys: merge two topics → redirect works → reply counts correct; bulk-delete
    eligible posts (skips one ineligible); search with forum + date facets → correct results.
    Light/dark × mobile/desktop screenshots (p2m4-*).
  • Pint / Larastan L5 / composer+npm audit / assets-fresh green; full suite green;
    PROJECT-STATE + DECISIONS updated (bulk-skip audit design; search driver abstraction;
    moved_to_topic_id no-FK rationale; any preferences decisions). Small DCO commits.

SCOPE FENCE — merge/split + bulk-select + search facets + consolidated preferences ONLY.
Staff notes (staff_notes table / StaffNote) are HELD — do not build. No full ACP member-
management page (deferred to post-M4). The VisibleForumIds class is already built — use it,
do not extend its interface. Bulk select covers posts and topics only — not users, not reports.
Reversible migrations only (none expected; all new columns are in new service classes or
additive to toSearchableArray). Strict clean-room. Flag anything not in the engine. When all
four Core items land runnable + tested, report back here.
```

---

## When M4 reports back

Cowork reviews: (1) is the merge/split **counter recompute authoritative** — derived from direct
SQL aggregates, not incremental, inside the transaction — and does the rollback test prove a
mid-merge failure commits nothing? (2) does the bulk-select rank guard **silently skip** ineligible
items (never bypass, never throw) and audit the skipped set? (3) does every search query **respect
`VisibleForumIds`** — a viewer restricted to forum A cannot retrieve posts from forum B via any
facet combination? (4) is `VisibleForumIds` **reused, not rebuilt** — confirm no second
resolver? (5) forced-absence: do all facets return correct results on the **DB driver** (the
baseline)? Then update PROJECT-STATE.

## After this

- **M5 (beta polish):** 2nd example theme (exercises the semver'd override layer), `DemoSeeder`
  refresh (reactions/PMs/feed/polls visible), regression vs. all Phase-2 migrations, public-beta
  milestone.
- **M3 Held** (follow-based feed, reputation, badges) — greenlight individually when ready.
- **GDPR data-export** ("download my data") — the complement to account deletion; its own packet.
- **ACP member-management page** — a proper member surface folding ban/warn/spam-clean/force-delete.
