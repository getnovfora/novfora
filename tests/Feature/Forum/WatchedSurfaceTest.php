<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\SubscriptionService;
use App\Models\Forum;
use App\Models\Tag;
use App\Models\User;
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

it('keeps notification volume bounded: a followed tag adds zero fan-out (pull-only), unlike a followed forum (U2 budget)', function () {
    // The engagement-phase notification-volume guard: the new followable kind (tags) must NOT push. Tag
    // activity is surfaced by PULL (the Watched home loop), so following a tag can never inflate per-event
    // notification volume the way a forum/topic follow does — the bounded fan-out (ADR-0097) stays the sole
    // push path. (Forum/topic fan-out cap + visibility fence are covered in SubscriptionTest.)
    $subNotifs = fn (User $u) => $u->notifications()->get()
        ->filter(fn ($n) => ($n->data['event'] ?? null) === 'subscription')->count();

    $forum = Forum::create(['slug' => 'nvb', 'title' => 'Volume', 'type' => 'forum']);
    $tag = Tag::create(['name' => 'budget', 'slug' => 'budget']);
    $subs = app(SubscriptionService::class);

    $tagFollower = Users::inGroups(['members', 'tl1']);   // follows the TAG only
    $forumFollower = Users::inGroups(['members', 'tl1']); // follows the FORUM (the push path)
    $subs->subscribe($tagFollower, $tag);
    $subs->subscribe($forumFollower, $forum);

    // A new topic in the followed forum, carrying the followed tag: the forum follower is pushed; the tag
    // follower is not — even though the topic bears their tag.
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl2']), $forum, 'Tagged, in a followed forum', 'tiptap_json', Content::doc('body'));
    $topic->tags()->attach($tag->id);

    expect($subNotifs($forumFollower))->toBeGreaterThan(0)
        ->and($subNotifs($tagFollower))->toBe(0);
});
