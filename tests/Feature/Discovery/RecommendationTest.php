<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Discovery\RecommendationService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Tag;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionValue;
use App\Permissions\VisibleForumIds;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Discovery 3.3 — lightweight "related topics": share-a-tag, topped up from the same forum, permission-safe.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function recTopic(int $forumId, string $slug, string $title): Topic
{
    return Topic::create(['slug' => $slug, 'title' => $title, 'forum_id' => $forumId, 'approved_state' => 'approved', 'last_posted_at' => now()]);
}

it('recommends topics that share a tag (excluding the source)', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel', 'usage_count' => 2]);
    $source = recTopic($forum->id, 'src', 'Source Topic');
    $related = recTopic($forum->id, 'rel', 'Tagged Sibling');
    $source->tags()->attach($tag->id);
    $related->tags()->attach($tag->id);

    $result = app(RecommendationService::class)->related($source, User::guest(), 5);

    expect($result->pluck('id')->all())->toContain($related->id)->not->toContain($source->id);
});

it('tops up from the same forum when there are no tag matches', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $source = recTopic($forum->id, 'src', 'Source');
    $sibling = recTopic($forum->id, 'sib', 'Same Forum Sibling');

    $result = app(RecommendationService::class)->related($source, User::guest(), 5);

    expect($result->pluck('id')->all())->toContain($sibling->id);
});

it('never recommends a topic in a forum the viewer cannot see', function () {
    $public = Forum::create(['slug' => 'pub', 'title' => 'Pub', 'type' => 'forum']);
    $private = Forum::create(['slug' => 'priv', 'title' => 'Priv', 'type' => 'forum']);
    $guests = Group::where('slug', 'guests')->firstOrFail();
    AclEntry::create(['permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $guests->id, 'scope_type' => 'forum', 'scope_id' => $private->id, 'value' => PermissionValue::Never->value]);

    $tag = Tag::create(['name' => 't', 'slug' => 't', 'usage_count' => 2]);
    $source = recTopic($public->id, 'src', 'Source');
    $hidden = recTopic($private->id, 'hid', 'Hidden Sibling');
    $source->tags()->attach($tag->id);
    $hidden->tags()->attach($tag->id);

    Cache::flush();
    VisibleForumIds::flush();

    $result = app(RecommendationService::class)->related($source, User::guest(), 5);
    expect($result->pluck('id')->all())->not->toContain($hidden->id);
});

it('renders a related-topics section on the topic page', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $tag = Tag::create(['name' => 'x', 'slug' => 'x', 'usage_count' => 2]);
    $source = recTopic($forum->id, 'src', 'The Source');
    $related = recTopic($forum->id, 'rel', 'Recommended Read');
    $source->tags()->attach($tag->id);
    $related->tags()->attach($tag->id);

    $this->get(route('topics.show', $source))->assertOk()->assertSee('Related topics')->assertSee('Recommended Read');
});
