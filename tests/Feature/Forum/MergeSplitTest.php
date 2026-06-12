<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\MergeTopicsService;
use App\Forum\PostService;
use App\Forum\SplitTopicService;
use App\Forum\TopicCounters;
use App\Forum\TopicModerationException;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Merge / split topics (P2-M4 ⚙). The correctness contract: posts are bulk-moved with a raw UPDATE (no
| per-row syncAggregates storm), counters are re-derived AUTHORITATIVELY from direct SQL after the move, the
| whole mutation is ONE transaction (a mid-step failure commits nothing), and a merged source 301-redirects
| to its target. Counter assertions read the raw columns (DB::table), never a recomputed model, so the test
| proves the persisted denormalised state — not a re-derivation done at read time.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function msForum(string $slug = 'general'): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

/** Create a topic with $replies extra posts; returns [topic, list<postId> in position order]. */
function msTopic(Forum $forum, int $replies, ?User $author = null): array
{
    $author ??= Users::inGroups(['members']);
    $posts = app(PostService::class);
    $topic = $posts->createTopic($author, $forum, 'Topic '.$forum->topics()->count(), 'tiptap_json', Content::doc('op'));
    for ($i = 0; $i < $replies; $i++) {
        $posts->reply($author, $topic, 'tiptap_json', Content::doc("reply {$i}"));
    }
    $ids = Post::where('topic_id', $topic->id)->orderBy('position')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();

    return [$topic->refresh(), $ids];
}

function rawTopic(int $id): object
{
    return DB::table('topics')->where('id', $id)->first();
}

function rawForum(int $id): object
{
    return DB::table('forums')->where('id', $id)->first();
}

// ── MERGE ───────────────────────────────────────────────────────────────────────────────────────────────

it('merges a topic into another: re-parents posts, recounts authoritatively, redirects, audits', function () {
    $forum = msForum();
    [$source, $sourceIds] = msTopic($forum, 2); // 3 posts
    [$target, $targetIds] = msTopic($forum, 1); // 2 posts
    $mod = Users::inGroups(['moderators']);

    app(MergeTopicsService::class)->merge($source, $target, $mod);

    // (b) every source post now belongs to the target — none left on the source.
    expect(Post::where('topic_id', $source->id)->count())->toBe(0)
        ->and(Post::where('topic_id', $target->id)->count())->toBe(5);

    // (e) authoritative recount on the target, asserted from the raw columns.
    $newest = Post::where('topic_id', $target->id)->orderByDesc('created_at')->orderByDesc('id')->first();
    expect((int) rawTopic($target->id)->reply_count)->toBe(4)              // 5 posts - 1
        ->and((int) rawTopic($target->id)->last_post_id)->toBe((int) $newest->id)
        ->and((int) rawTopic($target->id)->last_post_user_id)->toBe((int) $newest->user_id)
        ->and((int) rawTopic($target->id)->first_post_id)->toBe($targetIds[0]); // OP unchanged

    // (c)/(d) source is a soft-deleted redirect shell.
    $rawSource = rawTopic($source->id);
    expect($rawSource->deleted_at)->not->toBeNull()
        ->and((int) $rawSource->moved_to_topic_id)->toBe((int) $target->id)
        ->and($rawSource->status)->toBe('merged');

    // forum counters (same forum): 5 posts, 1 surviving topic.
    expect((int) rawForum($forum->id)->post_count)->toBe(5)
        ->and((int) rawForum($forum->id)->topic_count)->toBe(1);

    // (f) audit trail.
    expect(AuditLog::where('action', 'topic.merged')
        ->where('auditable_id', $source->id)->count())->toBe(1);

    // redirect: the merged source 301s to the target.
    $this->get(route('topics.show', $source->id))
        ->assertStatus(301)
        ->assertRedirect(route('topics.show', $target->id));
});

it('corrects BOTH forum counters when merging across forums', function () {
    $forumA = msForum('aaa');
    $forumB = msForum('bbb');
    [$source] = msTopic($forumA, 2); // forum A: 1 topic, 3 posts
    [$target] = msTopic($forumB, 1); // forum B: 1 topic, 2 posts
    $mod = Users::inGroups(['moderators']);

    app(MergeTopicsService::class)->merge($source, $target, $mod);

    // A loses its only topic + its posts; B keeps its topic and gains A's posts.
    expect((int) rawForum($forumA->id)->topic_count)->toBe(0)
        ->and((int) rawForum($forumA->id)->post_count)->toBe(0)
        ->and((int) rawForum($forumB->id)->topic_count)->toBe(1)
        ->and((int) rawForum($forumB->id)->post_count)->toBe(5);
});

it('rolls back the WHOLE merge when a recompute fails (one transaction)', function () {
    $forum = msForum();
    [$source, $sourceIds] = msTopic($forum, 2);
    [$target] = msTopic($forum, 1);
    $mod = Users::inGroups(['moderators']);

    // A TopicCounters double that throws AFTER the posts have moved + the source is soft-deleted.
    $this->app->bind(TopicCounters::class, fn () => new class extends TopicCounters
    {
        public function recomputeForum(int $forumId): void
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(MergeTopicsService::class)->merge($source, $target, $mod))
        ->toThrow(RuntimeException::class);

    // Nothing committed: posts still under the source, source not trashed, no redirect pointer, no audit.
    expect(Post::where('topic_id', $source->id)->count())->toBe(3)
        ->and(Topic::withTrashed()->find($source->id)->trashed())->toBeFalse()
        ->and(rawTopic($source->id)->moved_to_topic_id)->toBeNull()
        ->and(AuditLog::where('action', 'topic.merged')->count())->toBe(0);
});

it('refuses to merge a topic into itself', function () {
    $forum = msForum();
    [$source] = msTopic($forum, 1);
    $mod = Users::inGroups(['moderators']);

    expect(fn () => app(MergeTopicsService::class)->merge($source, $source, $mod))
        ->toThrow(TopicModerationException::class);
});

it('refuses a merge when the source author out-ranks the actor', function () {
    $forum = msForum();
    [$source] = msTopic($forum, 1, Users::inGroups(['admins']));
    [$target] = msTopic($forum, 1);
    $mod = Users::inGroups(['moderators']);

    $ex = null;
    try {
        app(MergeTopicsService::class)->merge($source, $target, $mod);
    } catch (TopicModerationException $e) {
        $ex = $e;
    }
    expect($ex?->reason)->toBe('outranked')
        ->and(Post::where('topic_id', $source->id)->count())->toBe(2); // untouched
});

it('forbids a non-moderator from merging', function () {
    $forum = msForum();
    [$source] = msTopic($forum, 1);
    [$target] = msTopic($forum, 1);

    expect(fn () => app(MergeTopicsService::class)->merge($source, $target, Users::inGroups(['members'])))
        ->toThrow(TopicModerationException::class);
});

// ── SPLIT ───────────────────────────────────────────────────────────────────────────────────────────────

it('splits selected posts into a new topic with correct recounts', function () {
    $forum = msForum();
    [$source, $ids] = msTopic($forum, 3); // 4 posts: [OP, r0, r1, r2]
    $mod = Users::inGroups(['moderators']);

    $moved = [$ids[1], $ids[2]]; // two middle replies
    $new = app(SplitTopicService::class)->split($source, $moved, 'Spun off', $mod);

    expect(Post::where('topic_id', $new->id)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all())
        ->toBe(collect($moved)->sort()->values()->all());

    // new topic: 2 posts → reply_count 1; first = lowest-position moved, last = newest moved.
    expect((int) rawTopic($new->id)->reply_count)->toBe(1)
        ->and((int) rawTopic($new->id)->first_post_id)->toBe($ids[1])
        ->and((int) rawTopic($new->id)->last_post_id)->toBe($ids[2]);

    // source: 2 posts remain (OP + r2) → reply_count 1; OP still anchors it.
    expect((int) rawTopic($source->id)->reply_count)->toBe(1)
        ->and((int) rawTopic($source->id)->first_post_id)->toBe($ids[0]);

    // forum unchanged in posts, +1 topic.
    expect((int) rawForum($forum->id)->post_count)->toBe(4)
        ->and((int) rawForum($forum->id)->topic_count)->toBe(2);

    expect(AuditLog::where('action', 'topic.split')->where('auditable_id', $source->id)->count())->toBe(1);
});

it('refuses to split away the opening post', function () {
    $forum = msForum();
    [$source, $ids] = msTopic($forum, 2);
    $mod = Users::inGroups(['moderators']);

    $ex = null;
    try {
        app(SplitTopicService::class)->split($source, [$ids[0], $ids[1]], 'Nope', $mod);
    } catch (TopicModerationException $e) {
        $ex = $e;
    }
    expect($ex?->reason)->toBe('cannot_move_op')
        ->and(Topic::where('forum_id', $forum->id)->count())->toBe(1) // no new topic created
        ->and(Post::where('topic_id', $source->id)->count())->toBe(3); // nothing moved
});

it('rolls back the WHOLE split when a recompute fails (one transaction)', function () {
    $forum = msForum();
    [$source, $ids] = msTopic($forum, 3);
    $mod = Users::inGroups(['moderators']);

    $this->app->bind(TopicCounters::class, fn () => new class extends TopicCounters
    {
        public function recomputeForum(int $forumId): void
        {
            throw new RuntimeException('boom');
        }
    });

    expect(fn () => app(SplitTopicService::class)->split($source, [$ids[1]], 'Doomed', $mod))
        ->toThrow(RuntimeException::class);

    // No new topic persisted; the post stayed on the source.
    expect(Topic::where('forum_id', $forum->id)->count())->toBe(1)
        ->and(Post::where('topic_id', $source->id)->count())->toBe(4)
        ->and(AuditLog::where('action', 'topic.split')->count())->toBe(0);
});

it('refuses a split when a selected post out-ranks the actor', function () {
    $forum = msForum();
    [$source, $ids] = msTopic($forum, 1); // OP + 1 reply by a member
    // An admin posts a reply into the source.
    $adminReply = app(PostService::class)->reply(Users::inGroups(['admins']), $source->refresh(), 'tiptap_json', Content::doc('admin reply'));
    $mod = Users::inGroups(['moderators']);

    $ex = null;
    try {
        app(SplitTopicService::class)->split($source, [(int) $adminReply->id], 'Try', $mod);
    } catch (TopicModerationException $e) {
        $ex = $e;
    }
    expect($ex?->reason)->toBe('outranked');
});

// ── redirect regression ─────────────────────────────────────────────────────────────────────────────────

it('still 404s an ordinary soft-deleted topic (not a merge shell)', function () {
    $forum = msForum();
    $mod = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($mod, $forum, 'Gone', 'tiptap_json', Content::doc('op'));
    $topic->delete();

    $this->get(route('topics.show', $topic->id))->assertNotFound();
});

it('404s a merge redirect for a viewer who cannot see the target forum (no id leak)', function () {
    $acl = Acl::make();
    $forum = $acl->forum;
    $admin = Users::inGroups(['admins']);
    $posts = app(PostService::class);
    $source = $posts->createTopic($admin, $forum, 'Src', 'tiptap_json', Content::doc('s'));
    $target = $posts->createTopic($admin, $forum, 'Tgt', 'tiptap_json', Content::doc('t'));
    app(MergeTopicsService::class)->merge($source, $target, $admin);

    $acl->grant('members', 'forum.view', $acl->forumScope, V::Never);
    $restricted = $acl->user(['members', 'tl1']);

    // A viewer who cannot see the target's forum gets 404 — never a 301 that would disclose the target id.
    $this->actingAs($restricted)->get(route('topics.show', $source->id))->assertNotFound();
    // An admin who sees all still gets the 301.
    $this->actingAs($admin)->get(route('topics.show', $source->id))
        ->assertStatus(301)->assertRedirect(route('topics.show', $target->id));
});

it('collapses a merge chain (A→B→C) to its terminus in one 301', function () {
    $forum = msForum();
    $admin = Users::inGroups(['admins']);
    $posts = app(PostService::class);
    $a = $posts->createTopic($admin, $forum, 'A', 'tiptap_json', Content::doc('a'));
    $b = $posts->createTopic($admin, $forum, 'B', 'tiptap_json', Content::doc('b'));
    $c = $posts->createTopic($admin, $forum, 'C', 'tiptap_json', Content::doc('c'));
    app(MergeTopicsService::class)->merge($a, $b, $admin);            // A → B
    app(MergeTopicsService::class)->merge($b->refresh(), $c, $admin); // B → C

    // A request to A resolves transitively to C (the terminus), not one hop to B.
    $this->actingAs($admin)->get(route('topics.show', $a->id))
        ->assertStatus(301)->assertRedirect(route('topics.show', $c->id));
});
