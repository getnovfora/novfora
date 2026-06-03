<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\PostRevision;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function aForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('lets a member create a topic with a server-rendered, sanitized opening post', function () {
    $forum = aForum();
    $member = Users::inGroups(['members']);

    Livewire::actingAs($member)
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'My first topic')
        ->set('canonicalJson', Content::doc('Hello <script>alert(1)</script> world'))
        ->call('save')
        ->assertRedirect();

    $topic = Topic::where('title', 'My first topic')->firstOrFail();
    $op = $topic->posts()->firstOrFail();
    expect($op->body_html_cache)->toContain('Hello')->not->toContain('<script');
    expect($op->body_text)->toContain('world');
    expect($forum->fresh()->topic_count)->toBe(1);
});

it('stores Markdown mode and renders it sanitized', function () {
    $forum = aForum();
    $member = Users::inGroups(['members']);

    Livewire::actingAs($member)
        ->test('forum.create-topic', ['forumId' => $forum->id])
        ->set('title', 'Markdown topic')
        ->call('toggleFormat')
        ->set('markdownSource', "# Heading\n\n**bold** <script>x</script>")
        ->call('save')
        ->assertRedirect();

    $op = Topic::where('title', 'Markdown topic')->firstOrFail()->posts()->firstOrFail();
    expect($op->body_format)->toBe('markdown');
    expect($op->body_html_cache)->toContain('<h1>')->toContain('<strong>bold</strong>')->not->toContain('<script');
});

it('lets a member reply and updates the counters', function () {
    $forum = aForum();
    $member = Users::inGroups(['members']);
    $topic = app(PostService::class)->createTopic($member, $forum, 'Topic', 'tiptap_json', Content::doc('op'));

    Livewire::actingAs($member)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->set('canonicalJson', Content::doc('a thoughtful reply'))
        ->call('save')
        ->assertRedirect();

    expect($topic->fresh()->reply_count)->toBe(1);
    expect($topic->posts()->count())->toBe(2);
});

it('lets the author edit their post and snapshots a revision', function () {
    $forum = aForum();
    $member = Users::inGroups(['members']);
    $topic = app(PostService::class)->createTopic($member, $forum, 'Topic', 'tiptap_json', Content::doc('original'));
    $op = $topic->posts()->firstOrFail();

    Livewire::actingAs($member)
        ->test('forum.edit-post', ['postId' => $op->id])
        ->set('canonicalJson', Content::doc('edited body'))
        ->set('reason', 'typo')
        ->call('save')
        ->assertRedirect();

    expect($op->fresh()->body_html_cache)->toContain('edited body');
    expect($op->fresh()->edit_count)->toBe(1);
    expect(PostRevision::where('post_id', $op->id)->count())->toBe(1);
});

it('denies topic.create for a user without the permission (deny-by-default)', function () {
    $forum = aForum();
    $stranger = Users::inGroups([]); // no granted group

    // The same gate the create-topic composer enforces in mount() / save().
    expect($stranger->canDo('topic.create', $forum->permissionScope()))->toBeFalse();
});
