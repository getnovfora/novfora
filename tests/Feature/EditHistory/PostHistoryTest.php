<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| Edit-history visibility (P2-M1). The ⚡post-history action is public (Livewire), so it re-asserts: the
| author may always view their own post's history; everyone else needs post.history.view at the forum scope.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@hist.test']);
    $this->other = Users::inGroups(['members', 'tl2'], ['username' => 'other', 'email' => 'other@hist.test']);
    $this->mod = Users::inGroups(['moderators', 'tl4'], ['username' => 'mod', 'email' => 'mod@hist.test']);

    $posts = app(PostService::class);
    $topic = $posts->createTopic($this->author, $forum, 'A topic', 'markdown', ['source' => 'Body.']);
    $this->post = $topic->posts()->first();
    $posts->editPost($this->author, $this->post, 'markdown', ['source' => 'Body edited once.'], 'fix typo');
    $posts->editPost($this->author, $this->post->fresh(), 'markdown', ['source' => 'Body edited twice.'], 'clarify');
    $this->post = $this->post->fresh();
});

function historyArgs($post): array
{
    return ['postId' => $post->id, 'topicId' => $post->topic_id, 'editCount' => $post->edit_count];
}

it('lets the author open their own post history', function () {
    Livewire::actingAs($this->author)
        ->test('forum.post-history', historyArgs($this->post))
        ->call('open')
        ->assertHasNoErrors()
        ->assertSet('open', true);
});

it('lets staff with post.history.view open any post history', function () {
    Livewire::actingAs($this->mod)
        ->test('forum.post-history', historyArgs($this->post))
        ->call('open')
        ->assertSet('open', true);
});

it('forbids a non-author, non-staff member from opening history', function () {
    Livewire::actingAs($this->other)
        ->test('forum.post-history', historyArgs($this->post))
        ->call('open')
        ->assertStatus(403);
});

it('builds one diff block per edit, newest first', function () {
    $component = Livewire::actingAs($this->author)
        ->test('forum.post-history', historyArgs($this->post))
        ->call('open');

    $edits = $component->get('edits');
    expect($edits)->toHaveCount(2)
        ->and($edits[0]['reason'])->toBe('clarify')   // newest edit first
        ->and($edits[1]['reason'])->toBe('fix typo');
});

it('grants post.history.view to staff but not to plain members (mask)', function () {
    $acl = Acl::make();
    $acl->assertDecision($acl->user(['moderators']), 'post.history.view', $acl->forumScope, true);
    $acl->assertDecision($acl->user(['members']), 'post.history.view', $acl->forumScope, false, 'default');
});
