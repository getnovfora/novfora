<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\CannedReply;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| T1 — the reply-composer canned-reply insert. A ?canned={id} param pre-fills the composer with the canned
| body, but ONLY for a staff (bans.manage) viewer and only for an ACTIVE reply — a member can't pull one in.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function crForumTopic(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    return [$forum, $topic];
}

it('pre-fills the composer from a ?canned param for a staff viewer', function () {
    [, $topic] = crForumTopic();
    $canned = CannedReply::create(['title' => 'Greeting', 'body_canonical' => CannedReply::textToDoc("Hello there\nHow can I help?"), 'is_active' => true]);

    Livewire::actingAs(Users::inGroups(['admins']))
        ->test('forum.reply-composer', ['topicId' => $topic->id, 'canned' => $canned->id])
        ->assertSet('canonicalJson', $canned->body_canonical);
});

it('ignores ?canned for a non-staff member (no insert)', function () {
    [, $topic] = crForumTopic();
    $canned = CannedReply::create(['title' => 'Greeting', 'body_canonical' => CannedReply::textToDoc('hello'), 'is_active' => true]);

    Livewire::actingAs(Users::inGroups(['members']))
        ->test('forum.reply-composer', ['topicId' => $topic->id, 'canned' => $canned->id])
        ->assertSet('canonicalJson', ['type' => 'doc', 'content' => []]);
});

it('ignores an inactive canned reply', function () {
    [, $topic] = crForumTopic();
    $canned = CannedReply::create(['title' => 'Old', 'body_canonical' => CannedReply::textToDoc('hello'), 'is_active' => false]);

    Livewire::actingAs(Users::inGroups(['admins']))
        ->test('forum.reply-composer', ['topicId' => $topic->id, 'canned' => $canned->id])
        ->assertSet('canonicalJson', ['type' => 'doc', 'content' => []]);
});

it('does not show the picker options to a non-staff member', function () {
    [, $topic] = crForumTopic();
    CannedReply::create(['title' => 'Greeting', 'body_canonical' => CannedReply::textToDoc('hello'), 'is_active' => true]);

    Livewire::actingAs(Users::inGroups(['members']))
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->assertDontSee('Insert a canned reply');
});
