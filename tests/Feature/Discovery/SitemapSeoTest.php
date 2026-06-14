<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Tag;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

/*
| Discovery 3.4 — sitemap depth (trending + tag pages added) + SEO polish (canonical + OG on boards).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Cache::flush();
});

it('includes the discovery landing pages and tag pages in the sitemap', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'G', 'type' => 'forum', 'topic_count' => 1]);
    $topic = Topic::create(['slug' => 't', 'title' => 'T', 'forum_id' => $forum->id, 'approved_state' => 'approved', 'last_posted_at' => now()]);
    Tag::create(['name' => 'popular', 'slug' => 'popular', 'usage_count' => 9]);

    $res = $this->get(route('sitemap'));

    $res->assertOk();
    $res->assertSee(route('trending.index'), false)
        ->assertSee(route('tags.index'), false)
        ->assertSee(route('tags.show', 'popular'), false)
        ->assertSee(route('forums.show', $forum), false)
        ->assertSee(route('topics.show', $topic), false);
});

it('does not list an unused tag in the sitemap', function () {
    Tag::create(['name' => 'unused', 'slug' => 'unused-tag', 'usage_count' => 0]);

    $this->get(route('sitemap'))->assertOk()->assertDontSee(route('tags.show', 'unused-tag'), false);
});

it('emits canonical + Open Graph on a board page', function () {
    $forum = Forum::create(['slug' => 'g', 'title' => 'The Lounge', 'type' => 'forum', 'description' => 'Chat here']);

    $this->get(route('forums.show', $forum))->assertOk()
        ->assertSee('rel="canonical"', false)
        ->assertSee('property="og:title"', false)
        ->assertSee('The Lounge');
});
