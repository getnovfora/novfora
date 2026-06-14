<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Discovery 3.2 — RSS/Atom feeds per forum / topic / user. Public, but only guest-visible content (a private
| forum's feed 404s). Cached, with auto-discovery <link> tags on the matching pages.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Cache::flush();
});

it('serves an Atom feed of a forum’s recent topics', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'General', 'type' => 'forum']);
    Topic::create(['slug' => 't', 'title' => 'Feed Worthy Topic', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);

    $res = $this->get(route('feeds.forum', $forum));

    $res->assertOk();
    expect($res->headers->get('Content-Type'))->toContain('application/atom+xml');
    $res->assertSee('<feed', false)->assertSee('Feed Worthy Topic');
});

it('serves an Atom feed of a topic’s posts', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $author = User::factory()->create(['username' => 'poster']);
    $topic = Topic::create(['slug' => 't', 'title' => 'Discuss This', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);
    Post::create(['topic_id' => $topic->id, 'user_id' => $author->id, 'body_format' => 'tiptap_json', 'body_canonical' => [], 'body_text' => 'a thoughtful reply', 'position' => 1, 'approved_state' => 'approved']);

    $res = $this->get(route('feeds.topic', $topic));

    $res->assertOk()->assertSee('Re: Discuss This')->assertSee('a thoughtful reply')->assertSee('poster');
});

it('serves an Atom feed of a user’s topics', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $author = User::factory()->create();
    Topic::create(['slug' => 't', 'title' => 'My Public Topic', 'forum_id' => $forum->id, 'user_id' => $author->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);

    $this->get(route('feeds.user', $author))->assertOk()->assertSee('My Public Topic');
});

it('404s a feed for a forum the public cannot see', function () {
    $forum = Forum::create(['slug' => 'priv', 'title' => 'Private', 'type' => 'forum']);
    $guests = Group::where('slug', 'guests')->firstOrFail();
    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'group', 'holder_id' => $guests->id,
        'scope_type' => 'forum', 'scope_id' => $forum->id, 'value' => PermissionValue::Never->value,
    ]);

    $this->get(route('feeds.forum', $forum))->assertNotFound();
});

it('advertises feed auto-discovery links on the matching pages', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum']);
    $topic = Topic::create(['slug' => 't', 'title' => 'T', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);

    $this->get(route('forums.show', $forum))->assertOk()->assertSee(route('feeds.forum', $forum), false);
    $this->get(route('topics.show', $topic))->assertOk()->assertSee(route('feeds.topic', $topic), false);
});
