<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| users.post_count is the denormalised per-author post tally shown in the topic poster sidebar. It is moved
| atomically by ±1 on post create / soft-delete / restore (Post model), mirroring forums.post_count: a post
| counts the moment it exists (approval state aside) and uncounts when (soft-)deleted, so a held-then-rejected
| post nets to zero. The count is floored at zero.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function pcForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('increments the author post_count for a new topic and reply', function () {
    $author = Users::inGroups(['moderators']);
    expect((int) $author->fresh()->post_count)->toBe(0);

    $topic = app(PostService::class)->createTopic($author, pcForum(), 'Hello', 'tiptap_json', Content::doc('op body'));
    expect((int) $author->fresh()->post_count)->toBe(1);

    app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc('a reply'));
    expect((int) $author->fresh()->post_count)->toBe(2);
});

it('counts a held (pending) post the same as an approved one', function () {
    $tl0 = Users::inGroups(['members', 'tl0']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['moderators']), pcForum(), 'Open', 'tiptap_json', Content::doc('op'));

    $reply = app(PostService::class)->reply($tl0, $topic, 'tiptap_json', Content::doc('a held reply'));

    expect($reply->approved_state)->toBe('pending');
    expect((int) $tl0->fresh()->post_count)->toBe(1);
});

it('decrements on soft-delete and re-counts on restore', function () {
    $author = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($author, pcForum(), 'Open', 'tiptap_json', Content::doc('op'));
    $reply = app(PostService::class)->reply($author, $topic, 'tiptap_json', Content::doc('reply'));
    expect((int) $author->fresh()->post_count)->toBe(2);

    $reply->delete();
    expect((int) $author->fresh()->post_count)->toBe(1);

    $reply->restore();
    expect((int) $author->fresh()->post_count)->toBe(2);
});

it('never drives the count below zero', function () {
    $author = Users::inGroups(['moderators']);
    $topic = app(PostService::class)->createTopic($author, pcForum(), 'Open', 'tiptap_json', Content::doc('op'));
    $op = $topic->posts()->firstOrFail();
    expect((int) $author->fresh()->post_count)->toBe(1);

    $op->adjustAuthorPostCount(-1);
    $op->adjustAuthorPostCount(-1); // floored: a second decrement past zero is a no-op

    expect((int) $author->fresh()->post_count)->toBe(0);
});
