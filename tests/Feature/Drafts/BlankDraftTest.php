<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\PostDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| BUG-014: an "empty" TipTap editor still emits a doc whose content is [{type:'paragraph'}] (a non-empty
| array), so the old empty($canonical['content']) check let saveDraft() persist it and restoreDraft() flag
| it — the "Draft restored · Discard" banner then stuck forever on a blank reply form. The fix inspects the
| doc for REAL content (text or an atomic media node) in both saveDraft() and restoreDraft().
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    $this->forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->member = Users::inGroups(['members', 'tl2'], ['username' => 'blankuser', 'email' => 'blank@draft.test']);
    $this->topic = app(PostService::class)->createTopic($this->member, $this->forum, 'A topic', 'markdown', ['source' => 'Body.']);
});

/** A reply-composer mounted for the member on the seeded topic. */
function composer(): Testable
{
    return Livewire::actingAs(test()->member)->test('forum.reply-composer', ['topicId' => test()->topic->id]);
}

it('does not persist a draft for an empty TipTap paragraph (BUG-014)', function () {
    composer()->call('saveDraft', ['type' => 'doc', 'content' => [['type' => 'paragraph']]]);

    expect(PostDraft::count())->toBe(0);
});

it('does not persist a draft for whitespace-only text (BUG-014)', function () {
    composer()->call('saveDraft', ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => '   ']]],
    ]]);

    expect(PostDraft::count())->toBe(0);
});

it('never flashes the draft-restored banner for a blank stored doc (BUG-014)', function () {
    // Even a legacy blank draft already in the store must not flag the banner on mount.
    PostDraft::create([
        'user_id' => test()->member->id, 'context_type' => 'reply', 'context_id' => test()->topic->id,
        'body_format' => 'tiptap_json', 'body_canonical' => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
        'tenant_id' => null,
    ]);

    composer()->assertSet('draftRestored', false);
});

it('still autosaves and restores a draft with real text (BUG-014 regression)', function () {
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Real reply']]]]];

    composer()->call('saveDraft', $doc);
    expect(PostDraft::count())->toBe(1);

    composer()->assertSet('draftRestored', true)->assertSet('canonicalJson', $doc);
});

it('persists a media-only draft (an image with no text is real content) (BUG-014)', function () {
    composer()->call('saveDraft', ['type' => 'doc', 'content' => [
        ['type' => 'image', 'attrs' => ['src' => 'https://example.test/a.png']],
    ]]);

    expect(PostDraft::count())->toBe(1);
});
