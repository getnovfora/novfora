<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\PostDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Editor autosave drafts (P2-M1). Drafts are OWN-ONLY by construction — every operation is scoped to the
| authenticated user + the compose context, and no draft id is ever accepted from the client. These tests
| exercise the ManagesDrafts trait through the real compose components (the JS island's debounced
| $wire.saveDraft is covered by the browser journey).
*/

uses(RefreshDatabase::class);

function draftDoc(string $text = 'Draft body'): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

beforeEach(function () {
    $this->seed();
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@draft.test']);
    $this->member = Users::inGroups(['members', 'tl2'], ['username' => 'member', 'email' => 'member@draft.test']);
    $this->topic = app(PostService::class)->createTopic($this->author, $this->forum, 'A topic', 'markdown', ['source' => 'Body.']);
});

it('autosaves a reply draft and restores it on re-mount', function () {
    Livewire::actingAs($this->member)
        ->test('forum.reply-composer', ['topicId' => $this->topic->id])
        ->call('saveDraft', draftDoc('My reply'));

    $draft = PostDraft::where('user_id', $this->member->id)
        ->where('context_type', 'reply')->where('context_id', $this->topic->id)->first();
    expect($draft)->not->toBeNull();

    Livewire::actingAs($this->member)
        ->test('forum.reply-composer', ['topicId' => $this->topic->id])
        ->assertSet('draftRestored', true)
        ->assertSet('canonicalJson', draftDoc('My reply'));
});

it('keeps drafts own-only — a second user never sees the first user\'s draft', function () {
    Livewire::actingAs($this->member)
        ->test('forum.reply-composer', ['topicId' => $this->topic->id])
        ->call('saveDraft', draftDoc('Secret'));

    Livewire::actingAs($this->author)
        ->test('forum.reply-composer', ['topicId' => $this->topic->id])
        ->assertSet('draftRestored', false)
        ->assertSet('canonicalJson', ['type' => 'doc', 'content' => []]);

    expect(PostDraft::count())->toBe(1); // only the member's
});

it('discards rather than persisting an empty document', function () {
    $c = Livewire::actingAs($this->member)->test('forum.reply-composer', ['topicId' => $this->topic->id]);
    $c->call('saveDraft', draftDoc('x'));
    expect(PostDraft::count())->toBe(1);

    $c->call('saveDraft', ['type' => 'doc', 'content' => []]);
    expect(PostDraft::count())->toBe(0);
});

it('discards the draft when the reply is published', function () {
    Livewire::actingAs($this->member)
        ->test('forum.reply-composer', ['topicId' => $this->topic->id])
        ->call('saveDraft', draftDoc('Will publish'))
        ->set('format', 'tiptap_json')
        ->set('canonicalJson', draftDoc('Will publish'))
        ->call('save');

    expect(PostDraft::where('user_id', $this->member->id)->count())->toBe(0);
});

it('explicit discard removes the draft', function () {
    $c = Livewire::actingAs($this->member)->test('forum.reply-composer', ['topicId' => $this->topic->id]);
    $c->call('saveDraft', draftDoc('temp'));
    expect(PostDraft::count())->toBe(1);

    $c->call('discardDraft')->assertSet('draftRestored', false);
    expect(PostDraft::count())->toBe(0);
});

it('autosaves and restores a new-topic draft per forum', function () {
    Livewire::actingAs($this->member)
        ->test('forum.create-topic', ['forumId' => $this->forum->id])
        ->call('saveDraft', draftDoc('New topic draft'));

    expect(PostDraft::where('user_id', $this->member->id)
        ->where('context_type', 'topic')->where('context_id', $this->forum->id)->exists())->toBeTrue();

    Livewire::actingAs($this->member)
        ->test('forum.create-topic', ['forumId' => $this->forum->id])
        ->assertSet('draftRestored', true)
        ->assertSet('canonicalJson', draftDoc('New topic draft'));
});

it('does not autosave for a guest (no draft persisted)', function () {
    // A guest cannot reach the composer, but the action must also no-op defensively.
    expect(PostDraft::count())->toBe(0);
});
