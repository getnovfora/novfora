<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostScheduler;
use App\Forum\PostService;
use App\Forum\ReplyGate;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| NF-01 (NOV-144) — one reply-authorization policy across every write path. The regression this pins: a
| composer opened while replying was allowed, then a THREAD-level restriction (NEVER on post.create, or NEVER on
| forum.view) is applied, then the stale composer submits without reloading → rejected, no post created. The
| same restriction must hold over the REST API and the scheduled-publish cron, and the pending-topic moderation
| fence must hold for replies exactly as it does for viewing.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{0:Topic,1:User} a board topic by its author, plus a separate replying member */
function gateFixture(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Gate topic', 'markdown', ['source' => 'opening post']);
    $replier = Users::inGroups(['members', 'tl1']);

    return [$topic, $replier];
}

function neverOnThread(User $user, Topic $topic, string $permission): void
{
    AclEntry::create([
        'permission_key' => $permission, 'holder_type' => 'user', 'holder_id' => $user->getKey(),
        'scope_type' => 'thread', 'scope_id' => $topic->getKey(), 'value' => -1, // NEVER (absolute)
    ]);
    app(PermissionResolver::class)->flushMemo();
}

function gateDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

function replyExists(Topic $topic, string $needle): bool
{
    return Post::where('topic_id', $topic->id)->where('body_text', 'like', "%{$needle}%")->exists();
}

it('rejects a stale composer after a thread-level NEVER on post.create is applied', function () {
    [$topic, $replier] = gateFixture();

    // The composer mounts while the reply is allowed…
    $composer = Livewire::actingAs($replier)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->assertOk();

    // …then a thread-scoped NEVER lands (the parent forum still grants post.create)…
    neverOnThread($replier, $topic, 'post.create');

    // …and the submit, without reloading, is refused and creates nothing.
    $composer->set('canonicalJson', gateDoc('stale composer reply'))
        ->call('save')
        ->assertStatus(403);

    expect(replyExists($topic, 'stale composer reply'))->toBeFalse();
});

it('rejects a stale composer after a thread-level NEVER on forum.view is applied', function () {
    [$topic, $replier] = gateFixture();

    $composer = Livewire::actingAs($replier)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->assertOk();

    neverOnThread($replier, $topic, 'forum.view');

    $composer->set('canonicalJson', gateDoc('cannot see but replies'))
        ->call('save')
        ->assertStatus(403);

    expect(replyExists($topic, 'cannot see but replies'))->toBeFalse();
});

it('refuses to schedule from a stale composer once the thread forbids replying', function () {
    [$topic, $replier] = gateFixture();

    $composer = Livewire::actingAs($replier)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->assertOk();

    neverOnThread($replier, $topic, 'post.create');

    $composer->set('canonicalJson', gateDoc('scheduled after revoke'))
        ->set('publishAt', now()->addDay()->format('Y-m-d\TH:i'))
        ->call('save')
        ->assertStatus(403);

    expect(ScheduledPost::count())->toBe(0);
});

it('applies the thread-level restriction to the REST API reply endpoint', function () {
    [$topic, $replier] = gateFixture();
    $token = app(ApiTokenService::class)->issue($replier, 'nf01')['plaintext'];

    neverOnThread($replier, $topic, 'post.create');
    $this->withToken($token)
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api reply after thread revoke'])
        ->assertStatus(403);

    expect(replyExists($topic, 'api reply after thread revoke'))->toBeFalse();
});

it('applies a thread-level forum.view NEVER to the REST API reply endpoint', function () {
    [$topic, $replier] = gateFixture();
    $token = app(ApiTokenService::class)->issue($replier, 'nf01')['plaintext'];

    neverOnThread($replier, $topic, 'forum.view');
    $this->withToken($token)
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api reply without view'])
        ->assertStatus(403);

    expect(replyExists($topic, 'api reply without view'))->toBeFalse();
});

it('skips a due scheduled reply when the thread forbids replying at publish time', function () {
    [$topic, $replier] = gateFixture();
    $scheduled = ScheduledPost::create([
        'user_id' => $replier->id, 'topic_id' => $topic->id, 'body_format' => 'tiptap_json',
        'body_canonical' => gateDoc('queued before revoke'), 'publish_at' => now()->subMinute(),
    ]);

    neverOnThread($replier, $topic, 'post.create');

    expect(app(PostScheduler::class)->publishDue())->toBe(0);
    expect(replyExists($topic, 'queued before revoke'))->toBeFalse();
    expect($scheduled->fresh()->published_at)->not->toBeNull()
        ->and($scheduled->fresh()->post_id)->toBeNull(); // permanently skipped, never retried
});

it('skips a due scheduled reply to a topic that is now pending moderation (not the author, not staff)', function () {
    [$topic, $replier] = gateFixture();
    ScheduledPost::create([
        'user_id' => $replier->id, 'topic_id' => $topic->id, 'body_format' => 'tiptap_json',
        'body_canonical' => gateDoc('reply into held topic'), 'publish_at' => now()->subMinute(),
    ]);
    $topic->forceFill(['approved_state' => 'pending'])->saveQuietly();

    expect(app(PostScheduler::class)->publishDue())->toBe(0);
    expect(replyExists($topic, 'reply into held topic'))->toBeFalse();
});

it('enforces the pending-topic fence on replies: 404 for others, allowed for the author and moderators', function () {
    [$topic, $replier] = gateFixture();
    $topic->forceFill(['approved_state' => 'pending'])->saveQuietly();
    $gate = app(ReplyGate::class);

    // An unrelated member gets the same no-disclosure 404 the topic page gives them.
    expect($gate->check($replier, $topic))->toBe(ReplyGate::HIDDEN);
    $this->withToken(app(ApiTokenService::class)->issue($replier, 'nf01')['plaintext'])
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'into a held topic'])
        ->assertStatus(404);
    Livewire::actingAs($replier)->test('forum.reply-composer', ['topicId' => $topic->id])->assertStatus(404);

    // The author can still reply to their own held topic; so can a moderator.
    $author = User::findOrFail($topic->user_id);
    expect($gate->check($author, $topic))->toBe(ReplyGate::ALLOWED);
    $moderator = Users::inGroups(['members', 'moderators']);
    expect($gate->check($moderator, $topic))->toBe(ReplyGate::ALLOWED);

    expect(replyExists($topic, 'into a held topic'))->toBeFalse();
});

it('refuses replies to a moved/merged shadow topic', function () {
    [$topic, $replier] = gateFixture();
    $forum = Forum::firstOrFail();
    $target = app(PostService::class)->createTopic($replier, $forum, 'Target', 'markdown', ['source' => 'target']);
    $topic->forceFill(['moved_to_topic_id' => $target->id])->saveQuietly();

    expect(app(ReplyGate::class)->check($replier, $topic))->toBe(ReplyGate::DENIED);
});

it('hides the composer on the topic page when a thread-level NEVER forbids replying', function () {
    [$topic, $replier] = gateFixture();

    $this->actingAs($replier)->get(route('topics.show', $topic))
        ->assertOk()->assertSee('id="reply-composer"', false);

    neverOnThread($replier, $topic, 'post.create');
    $this->actingAs($replier)->get(route('topics.show', $topic))
        ->assertOk()->assertDontSee('id="reply-composer"', false);
});

it('still accepts a normal reply on every path when nothing is restricted', function () {
    [$topic, $replier] = gateFixture();

    Livewire::actingAs($replier)
        ->test('forum.reply-composer', ['topicId' => $topic->id])
        ->set('canonicalJson', gateDoc('composer happy path'))
        ->call('save')
        ->assertHasNoErrors();

    $this->withToken(app(ApiTokenService::class)->issue($replier, 'nf01')['plaintext'])
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api happy path'])
        ->assertCreated();

    expect(replyExists($topic, 'composer happy path'))->toBeTrue()
        ->and(replyExists($topic, 'api happy path'))->toBeTrue();
});
