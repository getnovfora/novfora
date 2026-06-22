<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\TopicRead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Batch 2026-06-21 · Branch 2 — a long-time active member ("Dan") stops being held the moment a moderator
| approves a post (eager trust re-eval), an operator can diagnose WHY any user is held, and the queue shows it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['novfora.antispam.new_user_moderation.posts' => 2]);
    $this->seed();
});

function papForum(): Forum
{
    return Forum::create(['slug' => 'pap-general', 'title' => 'General', 'type' => 'forum']);
}

function papPosts(): PostService
{
    return app(PostService::class);
}

/** N approved posts by the user (bulk insert; the trust engine only counts rows). */
function papGivePosts(User $user, int $n): void
{
    $forum = Forum::create(['slug' => 'pap-f'.$user->getKey(), 'title' => 'F', 'type' => 'forum']);
    $topic = Topic::create(['slug' => 'pap-t'.$user->getKey(), 'title' => 'T', 'forum_id' => $forum->id, 'user_id' => $user->getKey()]);
    $now = now();
    $rows = [];
    for ($i = 0; $i < $n; $i++) {
        $rows[] = [
            'topic_id' => $topic->id, 'user_id' => $user->getKey(), 'body_format' => 'tiptap_json',
            'body_canonical' => json_encode(['type' => 'doc', 'content' => []]),
            'body_html_cache' => '', 'body_text' => '', 'approved_state' => 'approved',
            'position' => $i, 'created_at' => $now, 'updated_at' => $now,
        ];
    }
    Post::insert($rows);
}

/** Record that the user has READ $n distinct topics (the §2.3 engagement signal). */
function papGiveReads(User $user, int $n): void
{
    $forum = Forum::create(['slug' => 'pap-rf'.$user->getKey(), 'title' => 'RF', 'type' => 'forum']);
    for ($i = 0; $i < $n; $i++) {
        $topic = Topic::create(['slug' => 'pap-rt'.$user->getKey().'-'.$i, 'title' => 'RT', 'forum_id' => $forum->id, 'user_id' => $user->getKey()]);
        TopicRead::create(['user_id' => $user->getKey(), 'topic_id' => $topic->id, 'last_read_at' => now()]);
    }
}

it('promotes an eligible TL0 author the moment a post is approved — not an hour later', function () {
    $forum = papForum();
    $author = Users::inGroups(['members', 'tl0']);
    $author->forceFill(['created_at' => now()->subDays(3)])->save(); // tl1 min_days = 1
    papGivePosts($author, 5);                                        // + the held reply = 6 ≥ tl1 min_posts (5)
    papGiveReads($author, 5);                                        // tl1 min_topics_read = 5

    $mod = Users::inGroups(['moderators']);
    $topic = papPosts()->createTopic($mod, $forum, 'Open', 'tiptap_json', Content::doc('op'));

    // Still TL0 (no recompute has run) → the reply is held by the new-user gate.
    $reply = papPosts()->reply($author->fresh(), $topic, 'tiptap_json', Content::doc('held reply'));
    expect($reply->approved_state)->toBe('pending')
        ->and((int) $author->fresh()->trust_level)->toBe(0);

    // Approving the post eagerly re-evaluates trust → the author graduates to TL1 immediately.
    $this->actingAs($mod)->post(route('posts.approve', $reply->id))->assertRedirect();

    $author->refresh();
    expect((int) $author->trust_level)->toBe(1)
        ->and($author->groups()->where('slug', 'tl1')->exists())->toBeTrue()
        ->and($author->groups()->where('slug', 'tl0')->exists())->toBeFalse();
});

it('breaks the circular trap: the new-user hold releases once approvals reach the limit', function () {
    $forum = papForum();
    $author = Users::inGroups(['members', 'tl0']); // no tenure/reads → never crosses TL1, isolating the count gate
    $mod = Users::inGroups(['moderators']);
    $topic = papPosts()->createTopic($mod, $forum, 'Open', 'tiptap_json', Content::doc('op'));

    // Reply #1 held → approve (1/2 approved, still held).
    $r1 = papPosts()->reply($author->fresh(), $topic, 'tiptap_json', Content::doc('one'));
    expect($r1->approved_state)->toBe('pending');
    $this->actingAs($mod)->post(route('posts.approve', $r1->id))->assertRedirect();

    // Reply #2 held → approve (2/2 approved).
    $r2 = papPosts()->reply($author->fresh(), $topic, 'tiptap_json', Content::doc('two'));
    expect($r2->approved_state)->toBe('pending');
    $this->actingAs($mod)->post(route('posts.approve', $r2->id))->assertRedirect();

    // Reply #3 is NOT held — the gate released without waiting on the cron.
    $r3 = papPosts()->reply($author->fresh(), $topic, 'tiptap_json', Content::doc('three'));
    expect($r3->approved_state)->toBe('approved');
});

it('approving a held reply notifies the OP author exactly once (no double-notify from the PostCreated dispatch)', function () {
    $forum = papForum();
    $opAuthor = Users::inGroups(['members', 'tl2'], ['trust_level' => 2]); // established → its topic isn't held
    $topic = papPosts()->createTopic($opAuthor, $forum, 'Topic', 'tiptap_json', Content::doc('op'));

    $tl0 = Users::inGroups(['members', 'tl0']);
    $reply = papPosts()->reply($tl0, $topic, 'tiptap_json', Content::doc('a held reply'));
    expect($reply->approved_state)->toBe('pending')
        ->and($opAuthor->notifications()->count())->toBe(0); // nothing sent while held

    $mod = Users::inGroups(['moderators']);
    $this->actingAs($mod)->post(route('posts.approve', $reply->id))->assertRedirect();

    // The reply notification fires ONCE — dispatchPostNotifications sends it; the new PostCreated dispatch adds
    // none (its listeners are activity/badges/reputation/auto-promote, never reply/mention notifications).
    expect($opAuthor->notifications()->count())->toBe(1)
        ->and($opAuthor->notifications()->first()->data['event'])->toBe('reply');
});

it('novfora:trust:recompute --user prints the reason for eligible, below-threshold, and frozen users', function () {
    // Eligible → promotes.
    $eligible = Users::inGroups(['members', 'tl0']);
    $eligible->forceFill(['created_at' => now()->subDays(3)])->save();
    papGivePosts($eligible, 6);
    papGiveReads($eligible, 5);
    $this->artisan('novfora:trust:recompute', ['--user' => (string) $eligible->id])
        ->expectsOutputToContain('eligible → promoted to TL1')
        ->expectsOutputToContain('applied: TL0 → TL1')
        ->assertSuccessful();

    // Below threshold → stays, reason shows the gap.
    $below = Users::inGroups(['members', 'tl0']);
    $this->artisan('novfora:trust:recompute', ['--user' => (string) $below->id])
        ->expectsOutputToContain('below threshold for TL1')
        ->assertSuccessful();

    // Frozen by a NON-active status — looked up by USERNAME (not id). (A live warning no longer freezes the
    // TL0→TL1 graduation, ADR-0092, so a suspended account is the durable "frozen" diagnostic case.)
    $frozen = Users::inGroups(['members', 'tl0'], ['status' => 'suspended']);
    $frozen->forceFill(['username' => 'dan_stuck', 'created_at' => now()->subDays(3)])->save();
    papGivePosts($frozen, 6);
    papGiveReads($frozen, 5);
    $this->artisan('novfora:trust:recompute', ['--user' => 'dan_stuck'])
        ->expectsOutputToContain('frozen: status != active')
        ->assertSuccessful();
});

it('shows a per-item hold reason in the moderation queue', function () {
    $forum = papForum();
    $mod = Users::inGroups(['moderators']);
    $topic = papPosts()->createTopic($mod, $forum, 'Open', 'tiptap_json', Content::doc('op'));

    $tl0 = Users::inGroups(['members', 'tl0']);
    $reply = papPosts()->reply($tl0, $topic, 'tiptap_json', Content::doc('held'));
    expect($reply->approved_state)->toBe('pending');

    $this->actingAs($mod)->get(route('moderation.queue'))
        ->assertOk()
        ->assertSee('New user — 0 of 2 posts approved.');
});
