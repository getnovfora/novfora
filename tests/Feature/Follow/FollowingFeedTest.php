<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ActivityFeed;
use App\Community\FollowService;
use App\Events\Followed;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The FOLLOWING feed (P2-M5 ⚙): the ActivityFeed variant filtered to followed actors. The headline
| invariant: it is STILL threaded through VisibleForumIds — a followed user's activity in a forum the
| viewer lacks forum.view on stays hidden (one permission path, never bypassed). Plus the RH-9 cache
| discipline on the id-set-hash-keyed window and the empty-follow-set fallback hint.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function followingFeedForum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

it('shows only followed actors, still filtered by forum visibility (the permission headline)', function () {
    Event::fake([Followed::class]);
    $public = followingFeedForum('town-square');
    $secret = followingFeedForum('staff-room');

    $followed = Users::inGroups(['members', 'tl1']);
    $stranger = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($followed, $public, 'Followed public topic', 'tiptap_json', Content::doc('b'));
    app(PostService::class)->createTopic($followed, $secret, 'Followed secret topic', 'tiptap_json', Content::doc('b'));
    app(PostService::class)->createTopic($stranger, $public, 'Stranger topic', 'tiptap_json', Content::doc('b'));

    $viewer = Users::inGroups(['members', 'tl1']);
    app(FollowService::class)->follow($viewer, $followed);

    // Deny forum.view on the secret forum for THIS viewer only (user-holder NEVER).
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $viewer->id,
        'scope_type' => 'forum', 'scope_id' => $secret->id, 'value' => -1,
    ]);
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    Cache::flush();

    $ids = app(FollowService::class)->followingIds($viewer);
    $titles = array_map(fn ($i) => $i->title(), app(ActivityFeed::class)->forFollowing($viewer, $ids));

    expect($titles)->toContain('Followed public topic')      // followed + visible → shown
        ->and($titles)->not->toContain('Followed secret topic') // followed but INVISIBLE forum → hidden
        ->and($titles)->not->toContain('Stranger topic');        // visible but not followed → hidden
});

it('serves the following window from cache on the second load — no activities re-query (RH-9)', function () {
    config(['cache.default' => 'database']); // serialising store — object caching would break here
    Event::fake([Followed::class]);

    $forum = followingFeedForum('general');
    $followed = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($followed, $forum, 'A topic', 'tiptap_json', Content::doc('b'));

    $viewer = Users::inGroups(['members', 'tl1']);
    app(FollowService::class)->follow($viewer, $followed);
    $ids = app(FollowService::class)->followingIds($viewer);

    $svc = app(ActivityFeed::class);
    expect($svc->forFollowing($viewer, $ids))->not->toBeEmpty(); // warm the id-set-hash-keyed window

    $activityQueries = 0;
    DB::listen(function ($q) use (&$activityQueries) {
        if (str_contains($q->sql, 'activities')) {
            $activityQueries++;
        }
    });
    expect($svc->forFollowing($viewer, $ids))->not->toBeEmpty(); // second load

    expect($activityQueries)->toBe(0); // primitives served from cache; only rehydration queries ran
});

it('re-keys the cached window immediately when the follow set changes (no stale graph)', function () {
    Event::fake([Followed::class]);
    $forum = followingFeedForum('general');
    $a = Users::inGroups(['members', 'tl1']);
    $b = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($a, $forum, 'Topic by A', 'tiptap_json', Content::doc('b'));
    app(PostService::class)->createTopic($b, $forum, 'Topic by B', 'tiptap_json', Content::doc('b'));

    $viewer = Users::inGroups(['members', 'tl1']);
    $follows = app(FollowService::class);
    $feed = app(ActivityFeed::class);

    $follows->follow($viewer, $a);
    $titles = array_map(fn ($i) => $i->title(), $feed->forFollowing($viewer, $follows->followingIds($viewer)));
    expect($titles)->toBe(['Topic by A']);

    $follows->follow($viewer, $b); // the id-set hash changes → a fresh window, within the same TTL
    $titles = array_map(fn ($i) => $i->title(), $feed->forFollowing($viewer, $follows->followingIds($viewer)));
    expect($titles)->toContain('Topic by A')->toContain('Topic by B');
});

it('falls back to the global feed with the personalisation hint when the follow set is empty', function () {
    $forum = followingFeedForum('general');
    $author = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($author, $forum, 'Global topic', 'tiptap_json', Content::doc('b'));

    $viewer = Users::inGroups(['members', 'tl1']); // follows no one
    $this->actingAs($viewer);

    Livewire::test('community.activity-feed')
        ->call('setMode', 'following')
        ->assertSee('Global topic')                       // the global fallback still shows activity
        ->assertSee('not following anyone yet');          // …with the hint (dusk feed-follow-hint)
});

it('never exposes the following tab state to guests (setMode falls back to all)', function () {
    $forum = followingFeedForum('general');
    $author = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($author, $forum, 'Guest-visible topic', 'tiptap_json', Content::doc('b'));

    Livewire::test('community.activity-feed')
        ->call('setMode', 'following')
        ->assertSet('mode', 'all')      // a guest cannot have a follow graph
        ->assertSee('Guest-visible topic');
});
