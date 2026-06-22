<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| M1 — per-post quote-reply. A ?quote={id} pre-fills the composer with a canonical-JSON blockquote + an
| attribution link to the source, links the reply via parent_post_id, and renders through the existing
| canonical→sanitise pipeline. Security: a quote is only honoured for an APPROVED post in the SAME topic.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function quoteForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('pre-fills a blockquote + attribution and links the reply to the quoted post', function () {
    $forum = quoteForum();
    $op = Users::inGroups(['members', 'tl4']);
    $topic = app(PostService::class)->createTopic($op, $forum, 'Topic', 'tiptap_json', Content::doc('the original insight'));
    $quoted = $topic->posts()->firstOrFail();

    $component = Livewire::actingAs(Users::inGroups(['members', 'tl4']))
        ->test('forum.reply-composer', ['topicId' => $topic->id, 'quote' => $quoted->id]);

    $component->assertSet('replyToPostId', $quoted->id);
    $doc = $component->get('canonicalJson');
    expect(collect($doc['content'])->pluck('type'))->toContain('blockquote');

    // Add the reply line, then post.
    $doc['content'][] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'my response']]];
    $component->set('canonicalJson', $doc)->call('save')->assertRedirect();

    $reply = Post::where('topic_id', $topic->id)->where('id', '!=', $quoted->id)->latest('id')->firstOrFail();
    expect((int) $reply->parent_post_id)->toBe($quoted->id)            // linked to the source
        ->and($reply->body_html_cache)->toContain('<blockquote>')      // rendered + sanitised
        ->and($reply->body_text)->toContain('the original insight');   // the excerpt carried through
});

it('ignores a quote of a post from another topic (no cross-topic content pull)', function () {
    $forum = quoteForum();
    $u = Users::inGroups(['members', 'tl4']);
    $topicA = app(PostService::class)->createTopic($u, $forum, 'A', 'tiptap_json', Content::doc('alpha'));
    $topicB = app(PostService::class)->createTopic($u, $forum, 'B', 'tiptap_json', Content::doc('secret beta content'));
    $foreign = $topicB->posts()->firstOrFail();

    $component = Livewire::actingAs($u)
        ->test('forum.reply-composer', ['topicId' => $topicA->id, 'quote' => $foreign->id]);

    $component->assertSet('replyToPostId', null);
    expect($component->get('canonicalJson'))->toBe(['type' => 'doc', 'content' => []]);
});

it('nulls a forged cross-topic parent at the service boundary (defense-in-depth)', function () {
    $forum = quoteForum();
    $u = Users::inGroups(['members', 'tl4']);
    $topicA = app(PostService::class)->createTopic($u, $forum, 'A', 'tiptap_json', Content::doc('alpha'));
    $topicB = app(PostService::class)->createTopic($u, $forum, 'B', 'tiptap_json', Content::doc('beta'));
    $foreign = $topicB->posts()->firstOrFail();

    $reply = app(PostService::class)->reply($u, $topicA, 'tiptap_json', Content::doc('reply'), $foreign->id);
    expect($reply->parent_post_id)->toBeNull();
});

it('does not quote a held (unapproved) post', function () {
    $forum = quoteForum();
    $op = Users::inGroups(['members', 'tl4']);
    $topic = app(PostService::class)->createTopic($op, $forum, 'Topic', 'tiptap_json', Content::doc('op'));
    $held = $topic->posts()->firstOrFail();
    $held->update(['approved_state' => 'pending']); // simulate a held post

    $component = Livewire::actingAs(Users::inGroups(['members', 'tl4']))
        ->test('forum.reply-composer', ['topicId' => $topic->id, 'quote' => $held->id]);

    $component->assertSet('replyToPostId', null);
});
