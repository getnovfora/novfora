<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed()); // default posture: guests/members can forum.view

it('lets a guest browse the forum index and a forum', function () {
    Forum::create(['slug' => 'general', 'title' => 'General Chat', 'type' => 'forum']);

    $this->get(route('forums.index'))->assertOk()->assertSee('General Chat');
});

it('lets a guest read a topic, rendered from the sanitized cache', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Welcome here', 'tiptap_json', Content::doc('Hello readers'));

    $this->get(route('topics.show', $topic))
        ->assertOk()
        ->assertSee('Welcome here')
        ->assertSee('Hello readers');
});

it('denies viewing a forum where the group has a NEVER on forum.view (per-node authz)', function () {
    $forum = Forum::create(['slug' => 'secret', 'title' => 'Secret', 'type' => 'forum']);
    $member = Users::inGroups(['members']);

    AclEntry::create([
        'permission_key' => 'forum.view',
        'holder_type' => 'group',
        'holder_id' => Group::where('slug', 'members')->value('id'),
        'scope_type' => 'forum',
        'scope_id' => $forum->id,
        'value' => PermissionValue::Never->value,
    ]);

    $this->actingAs($member)->get(route('forums.show', $forum))->assertForbidden();
});
