<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Community-feel pack (P2-M3): throttled topic view-count, the online heuristic + ThrottledLastActive
| middleware, and the (already-maintained) forum topic/post counters.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function cpForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('increments a topic view_count once per viewer per window — not on repeat views', function () {
    $forum = cpForum();
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('b'));
    $viewer = Users::inGroups(['members', 'tl1']);

    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk();
    expect((int) $topic->fresh()->view_count)->toBe(1);

    $this->actingAs($viewer)->get(route('topics.show', $topic))->assertOk(); // repeat in the same window
    expect((int) $topic->fresh()->view_count)->toBe(1);                       // not double-counted
});

it('reports a user online within 15 minutes and offline after (or when never seen)', function () {
    $user = Users::inGroups(['members', 'tl1']);

    $user->forceFill(['last_active_at' => now()->subMinutes(5)])->save();
    expect($user->fresh()->isOnline())->toBeTrue();

    $user->forceFill(['last_active_at' => now()->subMinutes(20)])->save();
    expect($user->fresh()->isOnline())->toBeFalse();

    $user->forceFill(['last_active_at' => null])->save();
    expect($user->fresh()->isOnline())->toBeFalse();
});

it('stamps last_active_at via the throttled middleware and does not rewrite within 5 minutes', function () {
    cpForum();
    $user = Users::inGroups(['members', 'tl1']);
    $user->forceFill(['last_active_at' => null])->save();

    $this->actingAs(User::find($user->id))->get(route('forums.index'))->assertOk();
    $stamped = User::find($user->id)->last_active_at;
    expect($stamped)->not->toBeNull();

    // A fresh request (re-loaded user, recent last_active_at) within 5 min must NOT rewrite the stamp.
    $this->actingAs(User::find($user->id))->get(route('forums.index'))->assertOk();
    expect(User::find($user->id)->last_active_at->equalTo($stamped))->toBeTrue();
});

it('maintains forum topic_count and post_count as content is created', function () {
    $forum = cpForum();
    $author = Users::inGroups(['members', 'tl1']);
    expect((int) $forum->fresh()->topic_count)->toBe(0)
        ->and((int) $forum->fresh()->post_count)->toBe(0);

    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('op'));
    expect((int) $forum->fresh()->topic_count)->toBe(1)
        ->and((int) $forum->fresh()->post_count)->toBe(1); // the opening post counts

    app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc('reply'));
    expect((int) $forum->fresh()->post_count)->toBe(2);
});

it('displays the forum topic and post counts on the forum index', function () {
    $forum = cpForum();
    $author = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('op'));

    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('forums.index'))
        ->assertOk()
        ->assertSee('topics')
        ->assertSee('posts');
});
