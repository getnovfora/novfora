<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Unread / "what's new" (data-model §9): the per-user read watermark surfaces topics with activity since the
| user last opened them, and clears them on read; never leaks topics from forums they cannot see.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('lists unread topics and clears them once read', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $user->forceFill(['created_at' => now()->subDay()])->save();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Fresh news here', 'tiptap_json', Content::doc('op'));

    $this->actingAs($user)->get(route('whats-new'))->assertOk()->assertSee('Fresh news here');

    // Opening the topic records the read watermark → it drops out of what's-new.
    $this->actingAs($user)->get(route('topics.show', $topic))->assertOk();
    $this->actingAs($user)->get(route('whats-new'))->assertOk()->assertDontSee('Fresh news here');
});

it('omits topics from forums the user cannot see', function () {
    $acl = Acl::make();
    $forum = Forum::findOrFail($acl->forum->id);
    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Hidden news item', 'tiptap_json', Content::doc('op'));
    $acl->grant('members', 'forum.view', $acl->forumScope, V::Never);

    $viewer = $acl->user(['members']);
    $viewer->forceFill(['created_at' => now()->subDay()])->save();

    $this->actingAs($viewer)->get(route('whats-new'))->assertOk()->assertDontSee('Hidden news item');
});
