<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\AttachmentService;
use App\Forum\PostService;
use App\Models\Attachment;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| NF-02 (NOV-145) — a file is never more public than its parent post. The moderation fences the topic page
| applies (a pending topic → author + moderators only; a pending reply → its author + moderators only) must
| hold on the attachment stream too. Matrix: pending TOPIC and pending REPLY attachments × guest, unrelated
| member, author/uploader, moderator. Approved content stays readable to everyone who can read the forum.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Storage::fake('local');
});

function fileOn(Post $post, User $uploader): Attachment
{
    $attachment = app(AttachmentService::class)->store($uploader, UploadedFile::fake()->image('held.png'));
    $attachment->forceFill(['post_id' => $post->id])->save();

    return $attachment;
}

/** @return array{0:Topic,1:User} a public-forum topic and its author */
function publicTopic(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Topic', 'tiptap_json', Content::doc('opening'));

    return [$topic, $author];
}

it('hides a pending TOPIC\'s attachment from guests and unrelated members, but not its author or a moderator', function () {
    [$topic, $author] = publicTopic();
    $op = $topic->posts()->firstOrFail();
    $file = fileOn($op, $author);

    // Held by moderation: the topic inherits its opening post's pending state (ADR-0007 §2.4).
    $op->forceFill(['approved_state' => 'pending'])->saveQuietly();
    $topic->forceFill(['approved_state' => 'pending'])->saveQuietly();

    $this->get(route('attachments.show', $file))->assertForbidden();                                   // guest
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('attachments.show', $file))->assertForbidden(); // unrelated
    $this->actingAs($author)->get(route('attachments.show', $file))->assertOk();                        // author/uploader
    $this->actingAs(Users::inGroups(['members', 'moderators']))->get(route('attachments.show', $file))->assertOk(); // moderator
});

it('hides a pending REPLY\'s attachment from guests and unrelated members, but not its author or a moderator', function () {
    [$topic] = publicTopic();
    $replier = Users::inGroups(['members', 'tl1']);
    $reply = app(PostService::class)->reply($replier, $topic, 'tiptap_json', Content::doc('a held reply'));
    $file = fileOn($reply, $replier);
    $reply->forceFill(['approved_state' => 'pending'])->saveQuietly();

    $this->get(route('attachments.show', $file))->assertForbidden();
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('attachments.show', $file))->assertForbidden();
    $this->actingAs($replier)->get(route('attachments.show', $file))->assertOk();
    $this->actingAs(Users::inGroups(['members', 'moderators']))->get(route('attachments.show', $file))->assertOk();
});

it('does not let the TOPIC author read another member\'s pending reply attachment', function () {
    [$topic, $author] = publicTopic();
    $replier = Users::inGroups(['members', 'tl1']);
    $reply = app(PostService::class)->reply($replier, $topic, 'tiptap_json', Content::doc('a held reply'));
    $file = fileOn($reply, $replier);
    $reply->forceFill(['approved_state' => 'pending'])->saveQuietly();

    $this->actingAs($author)->get(route('attachments.show', $file))->assertForbidden();
});

it('keeps a rejected post\'s attachment hidden from non-authors', function () {
    [$topic] = publicTopic();
    $replier = Users::inGroups(['members', 'tl1']);
    $reply = app(PostService::class)->reply($replier, $topic, 'tiptap_json', Content::doc('rejected reply'));
    $file = fileOn($reply, $replier);
    $reply->forceFill(['approved_state' => 'rejected'])->saveQuietly();

    $this->get(route('attachments.show', $file))->assertForbidden();
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('attachments.show', $file))->assertForbidden();
});

it('still serves an approved post\'s attachment to guests and members of a public forum', function () {
    [$topic, $author] = publicTopic();
    $file = fileOn($topic->posts()->firstOrFail(), $author);

    $this->get(route('attachments.show', $file))->assertOk();
    $this->actingAs(Users::inGroups(['members', 'tl1']))->get(route('attachments.show', $file))->assertOk();
});
