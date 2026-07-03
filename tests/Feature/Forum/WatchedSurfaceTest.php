<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\SubscriptionService;
use App\Models\Forum;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| U2 (NOV-101) — follow forums/tags + the Watched surface. Following is polymorphic (M2); tags join forums and
| topics as a followable kind. The Watched page is the member home loop, visibility-fenced by VisibleForumIds.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('resolves a tag as a followable subscribable kind (U2)', function () {
    $tag = Tag::create(['name' => 'laravel', 'slug' => 'laravel']);

    expect(app(SubscriptionService::class)->resolve('tag', $tag->id))->toBeInstanceOf(Tag::class)
        ->and(app(SubscriptionService::class)->resolve('tag', 999999))->toBeNull();
});

it('follows a tag and toggles it off idempotently through the shared service (U2)', function () {
    $tag = Tag::create(['name' => 'php', 'slug' => 'php']);
    $user = Users::inGroups(['members', 'tl1']);
    $subs = app(SubscriptionService::class);

    expect($subs->toggle($user, $tag))->toBeTrue()          // now following
        ->and($subs->isSubscribed($user, $tag))->toBeTrue()
        ->and($subs->toggle($user, $tag))->toBeFalse()      // unfollowed
        ->and($subs->isSubscribed($user, $tag))->toBeFalse();
});

it('surfaces recent topics from a followed forum on the Watched page (U2 home loop)', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl2']);
    app(PostService::class)->createTopic($author, $forum, 'A followed-forum topic', 'tiptap_json', Content::doc('body'));

    $follower = Users::inGroups(['members', 'tl1']);
    app(SubscriptionService::class)->subscribe($follower, $forum);

    $this->actingAs($follower)->get(route('watched'))
        ->assertOk()
        ->assertSee('A followed-forum topic')
        ->assertSee($forum->title);
});

it('shows the empty state when the member follows nothing (U2)', function () {
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('watched'))
        ->assertOk()
        ->assertSee(__('watched.empty_title'));
});
