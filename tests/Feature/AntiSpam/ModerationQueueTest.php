<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The moderation queue + approval flow (security §3 / ADR-0007 §2.4): staff review and approve/reject the
| content the anti-spam layer held, all gated through the permission engine (topic.moderate) and audited.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function queueForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('lists pending content for a moderator', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    app(PostService::class)->createTopic($tl0, queueForum(), 'Held Topic Title', 'tiptap_json', Content::doc('please review'));

    $this->actingAs(Users::inGroups(['moderators']))->get(route('moderation.queue'))
        ->assertOk()->assertSee('Held Topic Title');
});

it('approves a pending topic and its opening post', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl0']), queueForum(), 'Held', 'tiptap_json', Content::doc('op body'));

    $this->actingAs(Users::inGroups(['moderators']))->post(route('topics.approve', $topic))->assertRedirect();

    expect($topic->fresh()->approved_state)->toBe('approved');
    expect($topic->posts()->firstOrFail()->approved_state)->toBe('approved');
});

it('approves a pending reply', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), queueForum(), 'Open', 'tiptap_json', Content::doc('op'));
    $reply = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('a held reply'));
    expect($reply->approved_state)->toBe('pending');

    $this->actingAs(Users::inGroups(['moderators']))->post(route('posts.approve', $reply))->assertRedirect();

    expect($reply->fresh()->approved_state)->toBe('approved');
});

it('rejects a pending reply, soft-deleting it', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), queueForum(), 'Open', 'tiptap_json', Content::doc('op'));
    $reply = app(PostService::class)->reply(Users::inGroups(['members', 'tl0']), $topic, 'tiptap_json', Content::doc('a spam reply'));

    $this->actingAs(Users::inGroups(['moderators']))->post(route('posts.reject', $reply))->assertRedirect();

    expect(Post::find($reply->id))->toBeNull();
    expect(Post::withTrashed()->find($reply->id)->approved_state)->toBe('rejected');
});

it('forbids a non-moderator from approving', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl0']), queueForum(), 'Held', 'tiptap_json', Content::doc('op'));

    $this->actingAs(Users::inGroups(['members']))->post(route('topics.approve', $topic))->assertForbidden();
});
