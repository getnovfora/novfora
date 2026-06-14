<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Discovery\TrendingService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionValue;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Discovery 3.1 — trending / best-of. Ranks topics by engagement from the existing aggregates; "trending"
| windows on recent activity, "best of" is all-time. Permission-safe via VisibleForumIds.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function trendTopic(int $forumId, string $slug, string $title, int $replies, int $views, $lastPosted): Topic
{
    return Topic::create([
        'slug' => $slug, 'title' => $title, 'forum_id' => $forumId, 'reply_count' => $replies,
        'view_count' => $views, 'last_posted_at' => $lastPosted, 'approved_state' => 'approved',
    ]);
}

it('ranks recently-active topics by engagement', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $hot = trendTopic($forum->id, 'hot', 'Hot Topic', 50, 500, now());
    $cold = trendTopic($forum->id, 'cold', 'Cold Topic', 1, 5, now());

    $result = app(TrendingService::class)->trending(User::guest(), 7, 20);

    expect($result->first()->id)->toBe($hot->id)
        ->and($result->pluck('id')->all())->toContain($cold->id);
});

it('windows trending on recent activity while best-of is all-time', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $recent = trendTopic($forum->id, 'recent', 'Recent Topic', 3, 30, now());
    $old = trendTopic($forum->id, 'old', 'Old Classic', 200, 2000, now()->subDays(30));

    $trending = app(TrendingService::class)->trending(User::guest(), 7, 20);
    $bestOf = app(TrendingService::class)->bestOf(User::guest(), 20);

    expect($trending->pluck('id')->all())->toContain($recent->id)->not->toContain($old->id);
    expect($bestOf->pluck('id')->all())->toContain($old->id); // all-time surfaces the old high-engagement one
});

it('excludes topics in a forum the viewer cannot see', function () {
    $public = Forum::create(['slug' => 'pub', 'title' => 'Public', 'type' => 'forum']);
    $private = Forum::create(['slug' => 'priv', 'title' => 'Private', 'type' => 'forum']);

    // Hard-deny forum.view to guests on the private forum.
    $guests = Group::where('slug', 'guests')->firstOrFail();
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $guests->id,
        'scope_type' => 'forum', 'scope_id' => $private->id, 'value' => PermissionValue::Never->value,
    ]);

    $pub = trendTopic($public->id, 'pubt', 'Public Hot', 10, 100, now());
    $priv = trendTopic($private->id, 'privt', 'Private Hot', 99, 999, now());

    Cache::flush();
    VisibleForumIds::flush();

    $result = app(TrendingService::class)->trending(User::guest(), 7, 20);
    expect($result->pluck('id')->all())->toContain($pub->id)->not->toContain($priv->id);
});

it('renders the public trending page', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    trendTopic($forum->id, 'vh', 'Visible Hot Topic', 8, 80, now());

    $this->get(route('trending.index'))->assertOk()->assertSee('Trending')->assertSee('Visible Hot Topic');
});
