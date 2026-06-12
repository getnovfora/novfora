<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Messaging\ConversationService;
use App\Models\Activity;
use App\Models\Forum;
use App\Models\Topic;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| Activity-feed verb logging (P2-M3): topic.created / post.created / react.given are recorded post-commit by
| auto-discovered listeners; PMs are private and log NOTHING.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function logForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

it('logs topic.created (subject=topic, actor=author, scope=forum) on topic creation', function () {
    $forum = logForum();
    $author = Users::inGroups(['members', 'tl1']);

    $topic = app(PostService::class)->createTopic($author, $forum, 'Hi', 'tiptap_json', Content::doc('body'));

    $a = Activity::where('verb', Activity::VERB_TOPIC_CREATED)->latest('id')->first();
    expect($a)->not->toBeNull()
        ->and($a->subject_type)->toBe((new Topic)->getMorphClass())
        ->and((int) $a->subject_id)->toBe((int) $topic->id)
        ->and((int) $a->actor_id)->toBe((int) $author->id)
        ->and((int) $a->scope_forum_id)->toBe((int) $forum->id);
});

it('logs post.created for a reply only — never for the opening post', function () {
    $forum = logForum();
    $author = Users::inGroups(['members', 'tl1']);
    $replier = Users::inGroups(['members', 'tl1']);

    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('op'));
    expect(Activity::where('verb', Activity::VERB_POST_CREATED)->count())->toBe(0); // OP is not a reply

    $reply = app(PostService::class)->reply($replier, $topic, 'tiptap_json', Content::doc('a reply'));

    $a = Activity::where('verb', Activity::VERB_POST_CREATED)->latest('id')->first();
    expect($a)->not->toBeNull()
        ->and((int) $a->subject_id)->toBe((int) $reply->id)
        ->and((int) $a->actor_id)->toBe((int) $replier->id)
        ->and((int) $a->scope_forum_id)->toBe((int) $forum->id);
});

it('logs react.given (subject=post, actor=reactor, scope=forum) on a reaction', function () {
    $forum = logForum();
    $author = Users::inGroups(['members', 'tl1']);
    $reactor = Users::inGroups(['members', 'tl1']);

    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'tiptap_json', Content::doc('b'));
    $post = $topic->posts()->firstOrFail();
    app(ReactionService::class)->toggle($reactor, $post, 'like');

    $a = Activity::where('verb', Activity::VERB_REACT_GIVEN)->latest('id')->first();
    expect($a)->not->toBeNull()
        ->and((int) $a->subject_id)->toBe((int) $post->id)
        ->and((int) $a->actor_id)->toBe((int) $reactor->id)
        ->and((int) $a->scope_forum_id)->toBe((int) $forum->id);
});

it('logs NO activity for a private message', function () {
    logForum();
    $alice = Users::inGroups(['members', 'tl1']);
    $bob = Users::inGroups(['members', 'tl1']);

    app(ConversationService::class)->startConversation($alice, [$bob->id], 'Hi', 'markdown', ['source' => 'hello bob']);

    expect(Activity::count())->toBe(0);
});
