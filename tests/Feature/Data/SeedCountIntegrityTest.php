<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| BUG-009 / BUG-011 are RECLASSIFIED as seed-data artifacts, not code defects: the demo's zero view/post
| counts came from seeding, while the runtime paths are correct. The importer (ImportRunner) and DemoSeeder
| both write through Eloquent / PostService, so the model events that maintain users.post_count fire; view
| counts accrue on real visits. These tests lock the correct runtime behaviour and the shipped backfill so
| the artifact can't be mistaken for a regression — there is intentionally NO production code change here.
| (Live post_count deltas are covered exhaustively by Forum/PostCountTest; the per-viewer view throttle by
| Community/CommunityPackTest. This file adds the two parts those don't: distinct viewers, and the backfill.)
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function integrityForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('counts each distinct viewer once, and does not double-count a repeat within the window (BUG-009)', function () {
    $posts = app(PostService::class);
    $topic = $posts->createTopic(Users::inGroups(['members', 'tl1']), integrityForum(), 'T', 'tiptap_json', Content::doc('b'));

    $alice = Users::inGroups(['members', 'tl1']);
    $bob = Users::inGroups(['members', 'tl1']);

    $this->actingAs($alice)->get(route('topics.show', $topic))->assertOk();
    expect((int) $topic->fresh()->view_count)->toBe(1);

    $this->actingAs($bob)->get(route('topics.show', $topic))->assertOk();   // a distinct viewer → +1
    expect((int) $topic->fresh()->view_count)->toBe(2);

    $this->actingAs($alice)->get(route('topics.show', $topic))->assertOk();  // repeat in the same hour
    expect((int) $topic->fresh()->view_count)->toBe(2);                       // not double-counted
});

it('the post_count backfill recomputes from live posts, honouring soft-deletes (BUG-011)', function () {
    $author = Users::inGroups(['moderators']);
    $posts = app(PostService::class);

    $topic = $posts->createTopic($author, integrityForum(), 'Hello', 'tiptap_json', Content::doc('op'));
    $posts->reply($author, $topic, 'tiptap_json', Content::doc('r1'));
    $posts->reply($author, $topic, 'tiptap_json', Content::doc('r2'));
    expect((int) $author->fresh()->post_count)->toBe(3);

    // Soft-delete one → the live delta keeps the count correct at 2.
    Post::where('topic_id', $topic->id)->latest('id')->first()->delete();
    expect((int) $author->fresh()->post_count)->toBe(2);

    // Simulate the seed-data artifact: rows whose model events never fired leave post_count stale.
    DB::table('users')->where('id', $author->id)->update(['post_count' => 0]);

    // The shipped, idempotent backfill recomputes it from live (non-deleted) posts — portable correlated UPDATE.
    $migration = require database_path('migrations/2026_06_12_000301_backfill_user_post_count.php');
    $migration->up();

    expect((int) $author->fresh()->post_count)->toBe(2); // the soft-deleted post stays excluded
});
