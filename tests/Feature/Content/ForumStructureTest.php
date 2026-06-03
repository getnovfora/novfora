<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function makePost(Topic $topic, User $author): Post
{
    return Post::create([
        'topic_id' => $topic->id,
        'user_id' => $author->id,
        'body_format' => 'tiptap_json',
        'body_canonical' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'hi']]]]],
    ]);
}

it('maintains forum + topic counters and last-post pointers via model events', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = User::factory()->create();

    expect($forum->fresh()->topic_count)->toBe(0);

    $topic = Topic::create(['forum_id' => $forum->id, 'user_id' => $author->id, 'slug' => 't', 'title' => 'T', 'type' => 'normal', 'status' => 'open']);
    expect($forum->fresh()->topic_count)->toBe(1);

    $op = makePost($topic, $author);
    $topic->refresh();
    $forum->refresh();
    expect($topic->first_post_id)->toBe($op->id);
    expect($topic->last_post_id)->toBe($op->id);
    expect($topic->reply_count)->toBe(0);          // the OP is not a reply
    expect($forum->post_count)->toBe(1);
    expect($forum->last_post_id)->toBe($op->id);

    $reply = makePost($topic, $author);
    $topic->refresh();
    $forum->refresh();
    expect($topic->reply_count)->toBe(1);
    expect($topic->last_post_id)->toBe($reply->id);
    expect($forum->post_count)->toBe(2);
    expect($forum->last_post_id)->toBe($reply->id);

    // Soft-deleting the reply recedes the counters and the last-post pointer.
    $reply->delete();
    $topic->refresh();
    $forum->refresh();
    expect($topic->reply_count)->toBe(0);
    expect($topic->last_post_id)->toBe($op->id);
    expect($forum->post_count)->toBe(1);

    // Soft-deleting the topic recedes the forum topic count.
    $topic->delete();
    expect($forum->fresh()->topic_count)->toBe(0);
});

it('exposes the correct permission scope per node', function () {
    $category = Forum::create(['slug' => 'cat', 'title' => 'Cat', 'type' => 'category']);
    $forum = Forum::create(['slug' => 'f', 'title' => 'F', 'type' => 'forum', 'parent_id' => $category->id]);
    $topic = Topic::create(['forum_id' => $forum->id, 'slug' => 't', 'title' => 'T']);

    expect($category->permissionScope()->key())->toBe('category:'.$category->id);
    expect($forum->permissionScope()->key())->toBe('forum:'.$forum->id);
    expect($topic->permissionScope()->key())->toBe('thread:'.$topic->id);
    expect($forum->permissionScope())->toBeInstanceOf(Scope::class);
});

it('builds the materialised path + depth on create', function () {
    $category = Forum::create(['slug' => 'cat', 'title' => 'Cat', 'type' => 'category']);
    $forum = Forum::create(['slug' => 'f', 'title' => 'F', 'type' => 'forum', 'parent_id' => $category->id]);

    expect($category->fresh()->path)->toBe('/'.$category->id.'/');
    expect($category->fresh()->depth)->toBe(0);
    expect($forum->fresh()->path)->toBe('/'.$category->id.'/'.$forum->id.'/');
    expect($forum->fresh()->depth)->toBe(1);
});
