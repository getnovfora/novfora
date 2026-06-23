<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\ActivityFeed;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Activity;
use App\Models\Forum;
use App\Permissions\PermissionResolver;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The activity feed (P2-M3 ⚙): RH-9 cache discipline (primitives cached, rehydrate after the boundary),
| per-viewer permission filtering, [Deleted]-actor + removed-subject tombstones.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    $this->seed();
});

function feedForum(string $slug = 'general'): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

it('serves the global window from cache on the second load — no activities re-query', function () {
    $forum = feedForum();
    $author = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($author, $forum, 'A topic', 'tiptap_json', Content::doc('b'));
    $viewer = Users::inGroups(['members', 'tl1']);

    $svc = app(ActivityFeed::class);
    expect($svc->for($viewer))->not->toBeEmpty(); // warm the version-keyed window + per-request memo

    $sql = [];
    DB::listen(function ($q) use (&$sql) {
        $sql[] = $q->sql;
    });
    $svc->for($viewer); // second load

    $activityQueries = array_values(array_filter($sql, fn (string $s): bool => str_contains($s, 'activities')));
    expect($activityQueries)->toBe([]); // primitives served from cache; only rehydration queries ran
});

it('renders [Deleted] for a removed actor and tombstones a removed subject', function () {
    $forum = feedForum();
    $author = Users::inGroups(['members', 'tl1'], ['display_name' => 'Gone']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Vanishing', 'tiptap_json', Content::doc('b'));

    Activity::query()->update(['actor_id' => null]); // simulate the ADR-0025 actor pseudonymise
    $topic->delete();                                // soft-delete the subject → tombstone
    Cache::flush();
    VisibleForumIds::flush();

    $viewer = Users::inGroups(['members', 'tl1']);
    $items = app(ActivityFeed::class)->for($viewer);

    expect($items)->not->toBeEmpty();
    expect($items[0]->actor)->toBeNull()
        ->and($items[0]->isMissing())->toBeTrue()
        ->and($items[0]->url())->toBeNull();
});

it('excludes feed activity from a forum the viewer cannot see', function () {
    $public = feedForum('general');
    $secret = feedForum('secret');
    $author = Users::inGroups(['members', 'tl1']);
    app(PostService::class)->createTopic($author, $public, 'Public topic', 'tiptap_json', Content::doc('b'));
    app(PostService::class)->createTopic($author, $secret, 'Secret topic', 'tiptap_json', Content::doc('b'));

    $viewer = Users::inGroups(['members', 'tl1']);
    // Deny forum.view on the secret forum for THIS viewer only (user-holder NEVER).
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $viewer->id,
        'scope_type' => 'forum', 'scope_id' => $secret->id, 'value' => -1,
    ]);
    app(PermissionResolver::class)->flushMemo();
    VisibleForumIds::flush();
    Cache::flush();

    $titles = array_map(fn ($i) => $i->title(), app(ActivityFeed::class)->for($viewer));
    expect($titles)->toContain('Public topic')
        ->and($titles)->not->toContain('Secret topic');
});

it('shows the feed on the forum index, with [Deleted] for a removed actor', function () {
    $forum = feedForum();
    $author = Users::inGroups(['members', 'tl1'], ['display_name' => 'OrigPoster']);
    app(PostService::class)->createTopic($author, $forum, 'Indexed topic', 'tiptap_json', Content::doc('b'));
    Activity::query()->update(['actor_id' => null]);
    Cache::flush();

    $viewer = Users::inGroups(['members', 'tl1']);
    $this->actingAs($viewer)->get(route('forums.index'))
        ->assertOk()
        ->assertSee('Recent activity')
        ->assertSee('Indexed topic')
        ->assertSee('[Deleted]'); // the FEED tombstones the pseudonymised actor (its no-leak is proven in
    // isolation above). NOTE: 'OrigPoster' DOES appear on the page now — F6's
    // latest-activity column shows the forum's LIVE last-post author, who was
    // never deleted here (only the activity's actor_id was nulled), so that is
    // correct and not a leak.
});
