<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Events\PostCreated;
use App\Forum\PostService;
use App\Forum\TopicCounters;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Tests\Support\Users;

/*
| NF-08 (NOV-151) — the reply write path: one transaction for the post and every durable write tied to it,
| notifications/events only after the OUTERMOST commit, per-topic locked position allocation, and atomic
| aggregates that always agree with the authoritative recompute. (True multi-process concurrency is exercised
| against MySQL/PostgreSQL by scripts/stress/concurrent-replies.php — see the CI "Pest (MySQL)" job.)
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{0:Forum,1:Topic,2:User} a forum, a topic in it (by its author), and the author */
function nf08Topic(string $slug = 'general'): array
{
    $forum = Forum::create(['slug' => $slug, 'title' => ucfirst($slug), 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);

    return [$forum, app(PostService::class)->createTopic($author, $forum, 'NF-08 topic', 'markdown', ['source' => 'op']), $author];
}

function nf08Reply(User $user, Topic $topic, string $text): Post
{
    return app(PostService::class)->reply($user, $topic, 'markdown', ['source' => $text]);
}

/** The stored aggregates, then the same columns after the authoritative recompute — they must be identical. */
function nf08Parity(Topic $topic): void
{
    $cols = fn () => [
        DB::table('topics')->where('id', $topic->id)->first(['reply_count', 'first_post_id', 'last_post_id', 'last_post_user_id', 'last_posted_at']),
        DB::table('forums')->where('id', $topic->forum_id)->first(['post_count', 'topic_count', 'last_post_id', 'last_topic_id', 'last_posted_at']),
    ];
    $stored = $cols();
    app(TopicCounters::class)->recomputeTopic((int) $topic->id);
    app(TopicCounters::class)->recomputeForum((int) $topic->forum_id);

    expect($cols())->toEqual($stored);
}

it('keeps topic + forum aggregates identical to the authoritative recompute across replies, trash and restore', function () {
    [$forum, $topic, $author] = nf08Topic();
    $other = Users::inGroups(['members', 'tl1']);

    $a = nf08Reply($other, $topic, 'first reply');
    $this->travel(1)->seconds();
    $b = nf08Reply($author, $topic, 'second reply');
    nf08Parity($topic);

    expect($topic->fresh()->reply_count)->toBe(2)
        ->and($topic->fresh()->last_post_id)->toBe($b->id)
        ->and($forum->fresh()->post_count)->toBe(3)
        ->and($forum->fresh()->last_post_id)->toBe($b->id);

    $b->delete();
    nf08Parity($topic);
    $b->restore();
    nf08Parity($topic);
    expect($a->fresh()->position)->toBeLessThan($b->fresh()->position);
});

it('moves the forum\'s last post to whichever topic was replied to most recently', function () {
    [$forum, $one] = nf08Topic();
    $two = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Second topic', 'markdown', ['source' => 'op2']);

    $this->travel(1)->seconds();
    $reply = nf08Reply(Users::inGroups(['members', 'tl1']), $one, 'bump topic one');

    expect($forum->fresh()->last_topic_id)->toBe($one->id)
        ->and($forum->fresh()->last_post_id)->toBe($reply->id)
        ->and($forum->fresh()->topic_count)->toBe(2);
    nf08Parity($two);
});

it('breaks a same-second tie by post id, the order the recompute uses', function () {
    Carbon::setTestNow('2026-10-30 12:00:00');
    [, $topic] = nf08Topic();
    $member = Users::inGroups(['members', 'tl1']);

    $first = nf08Reply($member, $topic, 'same second one');
    $second = nf08Reply($member, $topic, 'same second two');

    expect($topic->fresh()->last_post_id)->toBe($second->id)->and($second->id)->toBeGreaterThan($first->id);
    nf08Parity($topic);
    Carbon::setTestNow();
});

it('never reuses the position of a trashed post', function () {
    [, $topic] = nf08Topic();
    $member = Users::inGroups(['members', 'tl1']);

    $trashed = nf08Reply($member, $topic, 'to be trashed');
    $trashed->delete();
    $next = nf08Reply($member, $topic, 'after the trash');

    expect($next->position)->toBeGreaterThan($trashed->position);
    $trashed->restore();
    expect(Post::where('topic_id', $topic->id)->pluck('position')->duplicates())->toBeEmpty();
});

it('rolls back the WHOLE reply — post, aggregates, author count — when a later write in it fails', function () {
    [$forum, $topic] = nf08Topic();
    $member = Users::inGroups(['members', 'tl1']);
    $before = [$topic->fresh()->reply_count, $forum->fresh()->post_count, (int) $member->fresh()->post_count];
    Notification::fake();
    Event::fake([PostCreated::class]);

    // Fail AFTER the insert and its aggregate updates have run, inside the reply's transaction.
    Post::created(function (Post $post) {
        if (str_contains((string) $post->body_text, 'doomed')) {
            throw new RuntimeException('injected failure after the insert');
        }
    });

    expect(fn () => nf08Reply($member, $topic, 'doomed reply'))->toThrow(RuntimeException::class);

    expect(Post::where('body_text', 'like', '%doomed%')->exists())->toBeFalse()
        ->and([$topic->fresh()->reply_count, $forum->fresh()->post_count, (int) $member->fresh()->post_count])->toBe($before);
    Notification::assertNothingSent();
    Event::assertNotDispatched(PostCreated::class);
});

it('notifies and broadcasts only after the OUTERMOST transaction commits', function () {
    [, $topic, $author] = nf08Topic();
    $member = Users::inGroups(['members', 'tl1']);
    Event::fake([PostCreated::class]);

    // Rolled back by an outer transaction (as a failing caller would): nothing is announced. Event::fake
    // records a dispatch even if its transaction later rolls back, so this proves the deferral.
    try {
        DB::transaction(function () use ($member, $topic) {
            nf08Reply($member, $topic, 'inside a doomed outer transaction');
            throw new RuntimeException('outer failure');
        });
    } catch (RuntimeException) {
    }
    Event::assertNotDispatched(PostCreated::class);
    expect($author->notifications()->count())->toBe(0);

    // Committed by the outer transaction: announced once, after commit; the topic author is notified.
    DB::transaction(fn () => nf08Reply($member, $topic, 'inside a committed outer transaction'));
    Event::assertDispatchedTimes(PostCreated::class, 1);
    expect($author->notifications()->count())->toBe(1);
});
