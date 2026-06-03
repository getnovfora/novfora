<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The moderator control-panel baseline (MCP, security §3) and the in-thread report entry point.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('shows the MCP dashboard to a moderator but forbids a member', function () {
    $this->actingAs(Users::inGroups(['moderators']))->get(route('moderation.dashboard'))
        ->assertOk()->assertSee('Moderator control panel');

    $this->actingAs(Users::inGroups(['members']))->get(route('moderation.dashboard'))->assertForbidden();
});

it('offers a report action in the thread view for a signed-in member', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), $forum, 'A topic', 'tiptap_json', Content::doc('the post'));

    $this->actingAs(Users::inGroups(['members']))->get(route('topics.show', $topic))->assertOk()->assertSee('Report');
});
