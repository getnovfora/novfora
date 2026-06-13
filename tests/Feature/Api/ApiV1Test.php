<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\ApiToken;
use App\Models\Forum;
use App\Models\Post;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| The versioned REST API (ADR-0033). The boundary pins: a bad/expired/inactive token is a clean 401; a valid
| token acts AS its user and every endpoint authorizes through the EXISTING permission engine (a NEVER on the
| user denies the read filter / the write), so the API can never exceed the user's rights. Plus pagination meta.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function issueToken(User $user): string
{
    return app(ApiTokenService::class)->issue($user, 'test token')['plaintext'];
}

function denyGlobally(User $user, string $permission): void
{
    AclEntry::create([
        'permission_key' => $permission, 'holder_type' => 'user', 'holder_id' => $user->getKey(),
        'scope_type' => 'global', 'scope_id' => null, 'value' => -1, // NEVER (absolute)
    ]);
    app(PermissionResolver::class)->flushMemo();
}

it('rejects a request with no token, a garbage token, and an expired token', function () {
    $this->getJson('/api/v1/me')->assertStatus(401);
    $this->withToken('nvf_not-a-real-token')->getJson('/api/v1/me')->assertStatus(401);

    $user = Users::inGroups(['members', 'tl1']);
    $token = issueToken($user);
    ApiToken::query()->update(['expires_at' => now()->subDay()]);
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('rejects a token whose owner is no longer active', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $token = issueToken($user);
    $user->forceFill(['status' => 'banned'])->saveQuietly();

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('returns the authenticated user from /me with a valid token', function () {
    $user = Users::inGroups(['members', 'tl1'], ['username' => 'apiuser']);
    $this->withToken(issueToken($user))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.username', 'apiuser')
        ->assertJsonPath('data.id', $user->id);
});

it('lists forums the user may view and excludes those the engine denies', function () {
    $user = Users::inGroups(['members', 'tl1']);
    Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    $this->withToken(issueToken($user))->getJson('/api/v1/forums')
        ->assertOk()->assertJsonFragment(['slug' => 'general']);

    // A global NEVER on forum.view filters every forum out — the read goes through the permission engine.
    denyGlobally($user, 'forum.view');
    $this->withToken(issueToken($user))->getJson('/api/v1/forums')
        ->assertOk()->assertJsonCount(0, 'data');
});

it('paginates a topic\'s posts and returns meta', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Hello', 'markdown', ['source' => 'opening']);
    app(PostService::class)->reply($author, $topic, 'markdown', ['source' => 'a reply']);

    $this->withToken(issueToken($author))->getJson("/api/v1/topics/{$topic->id}?per_page=10")
        ->assertOk()
        ->assertJsonPath('data.id', $topic->id)
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonStructure(['data', 'posts', 'meta' => ['current_page', 'per_page', 'total', 'last_page']]);
});

it('creates a reply through the engine-authorized write endpoint', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Hello', 'markdown', ['source' => 'opening']);

    $this->withToken(issueToken($author))
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'a reply via the API'])
        ->assertCreated()
        ->assertJsonPath('data.topic_id', $topic->id);

    expect(Post::where('topic_id', $topic->id)->where('body_text', 'like', '%reply via the API%')->exists())->toBeTrue();
});

it('forbids reading a forum\'s topics when the engine denies forum.view (no bypass)', function () {
    $user = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    denyGlobally($user, 'forum.view');

    // The list endpoint filters it out AND the per-forum topics endpoint denies it — both go through the engine.
    $this->withToken(issueToken($user))->getJson("/api/v1/forums/{$forum->id}/topics")->assertStatus(403);
});

it('forbids a write the permission engine denies (no post.create)', function () {
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'Hello', 'markdown', ['source' => 'opening']);
    denyGlobally($author, 'post.create');

    $this->withToken(issueToken($author))
        ->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'should be blocked'])
        ->assertStatus(403);
});
