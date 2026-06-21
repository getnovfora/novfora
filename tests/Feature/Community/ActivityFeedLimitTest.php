<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| BUG-012: the homepage "Recent activity" feed page size is admin-configurable (ACP → Settings → General,
| key general.activity_feed_limit) instead of a hardcoded cap. ActivityFeed::for()/forFollowing() take an
| optional limit (clamped to the cached window); the homepage component reads the setting and passes it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    $this->seed();
});

it('caps the homepage activity feed at the admin-configured limit (BUG-012)', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $posts = app(PostService::class);
    foreach (range(1, 4) as $i) {
        $posts->createTopic($author, $forum, "Topic {$i}", 'tiptap_json', Content::doc("body {$i}"));
    }

    app(Settings::class)->set('general.activity_feed_limit', 2);

    $viewer = Users::inGroups(['members', 'tl1']);
    $html = Livewire::actingAs($viewer)->test('community.activity-feed')->html();

    expect(substr_count($html, 'dusk="activity-row"'))->toBe(2);
});

it('defaults the homepage activity-feed limit to 15 when unset (BUG-012)', function () {
    expect(app(Settings::class)->int('general.activity_feed_limit'))->toBe(15);
});
