<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\ContentRejectedException;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\WordFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Post-time moderation (ADR-0007 §2.4): the new-user queue holds a TL0 author's first posts, word filters
| replace/flag/block, the content scanner flags suspicious text, and pending content is hidden from others.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function moderationForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

function posts(): PostService
{
    return app(PostService::class);
}

it('holds a new (TL0) author’s first post for moderation, and the topic with it', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = posts()->createTopic($tl0, moderationForum(), 'My first post', 'tiptap_json', Content::doc('hello everyone'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending');
    expect($topic->fresh()->approved_state)->toBe('pending');
});

it('stops holding once the new user has N approved posts', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = posts()->createTopic(Users::inGroups(['moderators']), moderationForum(), 'Open', 'tiptap_json', Content::doc('op'));

    // Two already-approved posts by the TL0 user (the configured limit is 2).
    foreach (range(1, 2) as $i) {
        Post::create([
            'topic_id' => $topic->id, 'user_id' => $tl0->id, 'body_format' => 'tiptap_json',
            'body_canonical' => ['type' => 'doc', 'content' => []], 'body_html_cache' => '', 'body_text' => '', 'approved_state' => 'approved',
        ]);
    }

    $reply = posts()->reply($tl0, $topic, 'tiptap_json', Content::doc('my third contribution'));

    expect($reply->approved_state)->toBe('approved');
});

it('holds a post that trips a flag word filter', function () {
    WordFilter::create(['pattern' => 'spammy', 'action' => 'flag', 'is_active' => true, 'whole_word' => true]);
    $member = Users::inGroups(['members']); // not TL0 — isolates the word-filter effect

    $topic = posts()->createTopic($member, moderationForum(), 'A topic', 'tiptap_json', Content::doc('this is spammy content'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending');
});

it('rejects a post that trips a block word filter', function () {
    WordFilter::create(['pattern' => 'forbidden', 'action' => 'block', 'is_active' => true, 'whole_word' => true]);
    $member = Users::inGroups(['members']);

    expect(fn () => posts()->createTopic($member, moderationForum(), 'A topic', 'tiptap_json', Content::doc('a forbidden phrase')))
        ->toThrow(ContentRejectedException::class);

    expect(Topic::where('title', 'A topic')->exists())->toBeFalse(); // transaction rolled back
});

it('rewrites text via a replace word filter (display only)', function () {
    WordFilter::create(['pattern' => 'badword', 'replacement' => '****', 'action' => 'replace', 'is_active' => true, 'whole_word' => true]);
    $member = Users::inGroups(['members']);

    $op = posts()->createTopic($member, moderationForum(), 'A topic', 'tiptap_json', Content::doc('a badword here'))->posts()->firstOrFail();

    expect($op->body_html_cache)->toContain('****')->not->toContain('badword');
});

it('holds a post the content scanner finds suspicious', function () {
    config(['hearth.antispam.content.suspicious_phrases' => ['buy now cheap']]);
    $member = Users::inGroups(['members']);

    $topic = posts()->createTopic($member, moderationForum(), 'A topic', 'tiptap_json', Content::doc('hey buy now cheap deals'));

    expect($topic->posts()->firstOrFail()->approved_state)->toBe('pending');
});

it('hides a pending topic from guests but shows it to a moderator', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $forum = moderationForum();
    posts()->createTopic($tl0, $forum, 'Held Topic Title', 'tiptap_json', Content::doc('awaiting review'));

    $this->get(route('forums.show', $forum))->assertDontSee('Held Topic Title');                               // guest
    $this->actingAs(Users::inGroups(['moderators']))->get(route('forums.show', $forum))->assertSee('Held Topic Title');
});

it('hides a pending reply from other members but shows it to its author and staff', function () {
    $forum = moderationForum();
    $topic = posts()->createTopic(Users::inGroups(['moderators']), $forum, 'Open thread', 'tiptap_json', Content::doc('the opening post'));
    $tl0 = Users::inGroups(['members', 'tl0']);
    $reply = posts()->reply($tl0, $topic, 'tiptap_json', Content::doc('a held pending reply'));

    expect($reply->approved_state)->toBe('pending');
    $this->actingAs(Users::inGroups(['members']))->get(route('topics.show', $topic))->assertDontSee('a held pending reply');
    $this->actingAs($tl0)->get(route('topics.show', $topic))->assertSee('a held pending reply');
    $this->actingAs(Users::inGroups(['moderators']))->get(route('topics.show', $topic))->assertSee('a held pending reply');
});
