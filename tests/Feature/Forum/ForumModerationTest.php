<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function modForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('lets a moderator lock and pin a topic, writing the audit log', function () {
    $forum = modForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $mod = Users::inGroups(['moderators']);

    $this->actingAs($mod)->post(route('topics.lock', $topic))->assertRedirect();
    expect($topic->fresh()->status)->toBe('locked');
    expect(AuditLog::where('action', 'topic.locked')->count())->toBe(1);

    $this->actingAs($mod)->post(route('topics.pin', $topic))->assertRedirect();
    expect($topic->fresh()->is_pinned)->toBeTrue();
});

it('forbids a member from moderating', function () {
    $forum = modForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    $this->actingAs(Users::inGroups(['members']))->post(route('topics.lock', $topic))->assertForbidden();
});

it('soft-deletes a topic to the recycle bin and restores it', function () {
    $forum = modForum();
    $mod = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($mod, $forum, 'Disposable', 'tiptap_json', Content::doc('op'));

    $this->actingAs($mod)->delete(route('topics.destroy', $topic))->assertRedirect();
    expect(Topic::find($topic->id))->toBeNull();
    expect(Topic::withTrashed()->find($topic->id)->trashed())->toBeTrue();

    $this->actingAs($mod)->get(route('moderation.recycle-bin'))->assertOk()->assertSee('Disposable');

    $this->actingAs($mod)->post(route('topics.restore', $topic->id))->assertRedirect();
    expect(Topic::find($topic->id))->not->toBeNull();
});

it('enforces own/any on post deletion', function () {
    $forum = modForum();
    $author = Users::inGroups(['members']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('op'));
    $other = Users::inGroups(['members']);
    $reply = app(PostService::class)->reply($other, $topic, 'tiptap_json', Content::doc('reply'));

    // The author of the OP cannot delete someone else's reply…
    $this->actingAs($author)->delete(route('posts.destroy', $reply))->assertForbidden();
    // …but the reply's own author can (post.delete.own)…
    $this->actingAs($other)->delete(route('posts.destroy', $reply))->assertRedirect();
    expect(Post::find($reply->id))->toBeNull();
    // …and a moderator can delete any post (post.delete.any).
    $this->actingAs(Users::inGroups(['moderators']))->delete(route('posts.destroy', $topic->posts()->firstOrFail()))->assertRedirect();
});
