<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| U6 (NOV-104) front-of-site moderator toolset — the inline-on-held-content piece. A moderator viewing a
| thread (who already SEES pending replies, TopicController's `unless($canModerate)`) can approve or reject a
| held reply in place via the existing posts.approve/reject routes, governed by the 3A permission-aware
| contract: control shown ⇔ POST works; hidden ⇔ the viewer cannot moderate. Asserted on the form action URL
| (a control-only marker), never a word substring.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    app(PermissionResolver::class)->flushMemo();
});

function u6Forum(string $slug): Forum
{
    return Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
}

it('shows a moderator the inline approve control on a held reply, and approving in-thread works (the pair)', function () {
    $mod = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($mod, u6Forum('u6a'), 'A thread', 'tiptap_json', Content::doc('op'));
    $held = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('held reply'));
    expect($held->approved_state)->toBe('pending');

    $this->actingAs($mod)->get(route('topics.show', $topic))
        ->assertOk()->assertSee('action="'.route('posts.approve', $held).'"', false);

    $this->actingAs($mod)->post(route('posts.approve', $held))->assertRedirect();
    expect($held->fresh()->approved_state)->toBe('approved');
});

it('hides the inline approve control from a plain member AND 403s the forged POST', function () {
    $mod = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($mod, u6Forum('u6b'), 'A thread', 'tiptap_json', Content::doc('op'));
    $held = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('held reply'));

    $member = Users::inGroups(['members', 'tl1']);
    $this->actingAs($member)->get(route('topics.show', $topic))
        ->assertOk()->assertDontSee('action="'.route('posts.approve', $held).'"', false);
    $this->actingAs($member)->post(route('posts.approve', $held))->assertForbidden();
    expect($held->fresh()->approved_state)->toBe('pending');
});
