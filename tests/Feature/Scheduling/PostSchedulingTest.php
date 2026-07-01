<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubMembershipService;
use App\Clubs\ClubService;
use App\Forum\AttachmentService;
use App\Forum\PostScheduler;
use App\Forum\PostService;
use App\Models\Attachment;
use App\Models\Forum;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| Member tool 2.4 — post scheduling. A scheduled reply is held until its time; the cron publishes it by
| creating the REAL post through PostService (full side-effects). Cron-tolerant: an atomic per-row claim
| means an overlapping/coarse run never double-publishes; a not-yet-publishable item is skipped, not retried.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{0:Forum,1:Topic} */
function schedFixture(): array
{
    $forum = Forum::create(['slug' => 'g', 'title' => 'General', 'type' => 'forum']);
    $author = User::factory()->create();
    $topic = Topic::create(['slug' => 't', 'title' => 'Topic', 'forum_id' => $forum->id, 'user_id' => $author->id, 'last_posted_at' => now()]);

    return [$forum, $topic];
}

function schedDoc(): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'scheduled hello']]]]];
}

/** Create a DUE scheduled reply directly (past publish_at — bypassing the future-only public API). */
function dueScheduled(Topic $topic, User $user): ScheduledPost
{
    return ScheduledPost::create([
        'user_id' => $user->id, 'topic_id' => $topic->id, 'body_format' => 'tiptap_json',
        'body_canonical' => schedDoc(), 'publish_at' => now()->subMinute(),
    ]);
}

it('schedules a reply without creating a post yet', function () {
    [, $topic] = schedFixture();
    $user = Users::inGroups(['members']);

    $sp = app(PostScheduler::class)->scheduleReply($user, $topic, 'tiptap_json', schedDoc(), now()->addHour());

    expect(ScheduledPost::count())->toBe(1)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(0)
        ->and($sp->published_at)->toBeNull();
});

it('refuses a publish time that is not in the future', function () {
    [, $topic] = schedFixture();
    app(PostScheduler::class)->scheduleReply(Users::inGroups(['members']), $topic, 'tiptap_json', schedDoc(), now()->subMinute());
})->throws(InvalidArgumentException::class);

it('publishes a due reply into the topic as a real post', function () {
    [, $topic] = schedFixture();
    $sp = dueScheduled($topic, Users::inGroups(['members']));

    $created = app(PostScheduler::class)->publishDue();

    expect($created)->toBe(1)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(1)
        ->and($sp->fresh()->published_at)->not->toBeNull()
        ->and($sp->fresh()->post_id)->not->toBeNull();
});

it('publishes a scheduled reply with a reserved attachment and binds it to the created post', function () {
    Storage::fake('local');
    [, $topic] = schedFixture();
    $user = Users::inGroups(['members', 'tl4']);
    $attachment = app(AttachmentService::class)->store(
        $user,
        UploadedFile::fake()->createWithContent('note.txt', 'scheduled attachment'),
    );
    $doc = ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'scheduled with attachment']]],
        ['type' => 'file', 'attrs' => ['src' => route('attachments.show', $attachment), 'name' => 'note.txt']],
    ]];
    $scheduled = ScheduledPost::create([
        'user_id' => $user->id,
        'topic_id' => $topic->id,
        'body_format' => 'tiptap_json',
        'body_canonical' => $doc,
        'publish_at' => now()->subMinute(),
    ]);

    expect(app(PostScheduler::class)->publishDue())->toBe(1);

    $postId = $scheduled->fresh()->post_id;
    expect($postId)->not->toBeNull()
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(1)
        ->and($attachment->fresh()->post_id)->toBe($postId)
        ->and(Attachment::whereNull('post_id')->count())->toBe(0);

    $this->get(route('topics.show', $topic))->assertOk()->assertSee('note.txt');
    $this->get(route('attachments.show', $attachment->fresh()))->assertOk();
});

it('never double-publishes on a second run (claim-guarded)', function () {
    [, $topic] = schedFixture();
    dueScheduled($topic, Users::inGroups(['members']));

    app(PostScheduler::class)->publishDue();
    app(PostScheduler::class)->publishDue(); // second tick

    expect(Post::where('topic_id', $topic->id)->count())->toBe(1);
});

it('leaves a not-yet-due reply untouched', function () {
    [, $topic] = schedFixture();
    app(PostScheduler::class)->scheduleReply(Users::inGroups(['members']), $topic, 'tiptap_json', schedDoc(), now()->addDay());

    expect(app(PostScheduler::class)->publishDue())->toBe(0)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(0);
});

it('skips (and does not retry) a reply whose topic became locked', function () {
    [, $topic] = schedFixture();
    $sp = dueScheduled($topic, Users::inGroups(['members']));
    $topic->update(['status' => 'locked']);

    expect(app(PostScheduler::class)->publishDue())->toBe(0)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(0)
        ->and($sp->fresh()->published_at)->not->toBeNull() // marked done…
        ->and($sp->fresh()->post_id)->toBeNull();           // …but no post, and won't retry
});

it('skips a scheduled club reply when the author is no longer an active club member', function () {
    $owner = Users::inGroups(['members', 'tl2'], ['email' => 'scheduled-club-owner@example.test']);
    $club = app(ClubService::class)->create($owner, ['name' => 'Scheduled Club', 'privacy' => 'public']);
    $member = Users::inGroups(['members', 'tl2'], ['email' => 'scheduled-club-member@example.test']);
    app(ClubMembershipService::class)->join($club, $member);
    $topic = app(PostService::class)->createTopic($owner, $club->forum, 'Club schedule', 'tiptap_json', schedDoc());
    $sp = dueScheduled($topic, $member);

    app(ClubMembershipService::class)->leave($club, $member);

    expect(app(PostScheduler::class)->publishDue())->toBe(0)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(1)
        ->and($sp->fresh()->published_at)->not->toBeNull()
        ->and($sp->fresh()->post_id)->toBeNull();
});

it('cancels a pending scheduled reply (but not a published one)', function () {
    [, $topic] = schedFixture();
    $sp = app(PostScheduler::class)->scheduleReply(Users::inGroups(['members']), $topic, 'tiptap_json', schedDoc(), now()->addHour());

    expect(app(PostScheduler::class)->cancel($sp))->toBeTrue()
        ->and(ScheduledPost::count())->toBe(0);
});

it('the publish-scheduled command publishes due items', function () {
    [, $topic] = schedFixture();
    dueScheduled($topic, Users::inGroups(['members']));

    $this->artisan('novfora:posts:publish-scheduled')->assertSuccessful();

    expect(Post::where('topic_id', $topic->id)->count())->toBe(1);
});

it('schedules from the reply composer instead of posting immediately', function () {
    [, $topic] = schedFixture();
    $user = Users::inGroups(['members']);

    Livewire::actingAs($user)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->set('canonicalJson', schedDoc())
        ->set('publishAt', now()->addHour()->format('Y-m-d\TH:i'))
        ->call('save');

    expect(ScheduledPost::where('topic_id', $topic->id)->count())->toBe(1)
        ->and(Post::where('topic_id', $topic->id)->count())->toBe(0);
});

it('lists and cancels scheduled replies from the management view', function () {
    [, $topic] = schedFixture();
    $user = Users::inGroups(['members']);
    $sp = app(PostScheduler::class)->scheduleReply($user, $topic, 'tiptap_json', schedDoc(), now()->addHour());

    $this->actingAs($user)->get(route('scheduled.index'))->assertOk()->assertSee('Topic');

    Livewire::actingAs($user)->test('forum.scheduled-posts')->call('cancel', $sp->id);
    expect(ScheduledPost::count())->toBe(0);
});
