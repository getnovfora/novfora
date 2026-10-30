<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| NF-06 (NOV-149) — one posting budget per member across the web composer AND the REST API, plus two request-rate
| layers on the API: a coarse per-IP ceiling ahead of token auth (bounds unauthenticated / invalid-token floods)
| and a per-member ceiling after it (keyed by the token's owner, so it holds from any IP).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    cache()->flush(); // RateLimiter buckets live in the cache store
});

/** @return array{0:Topic,1:User,2:string} a topic, a TL1 replier, and the replier's API token */
function rateFixture(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Rate topic', 'markdown', ['source' => 'op']);
    $replier = Users::inGroups(['members', 'tl1']);

    return [$topic, $replier, app(ApiTokenService::class)->issue($replier, 'nf06')['plaintext']];
}

/** Each production request is a fresh app; the test app keeps the auth guard's user, so drop it between calls. */
function freshAuth(): void
{
    app('auth')->forgetGuards();
}

function rateDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
}

it('shares ONE trust-based posting budget between the web composer and the API', function () {
    config(['novfora.antispam.rate_limits' => ['tl1' => 2, 'default' => 2]]);
    [$topic, $replier, $token] = rateFixture();

    $this->withToken($token)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api one'])->assertCreated();
    freshAuth();
    Livewire::actingAs($replier)->test('forum.reply-composer', ['topicId' => $topic->id])
        ->set('canonicalJson', rateDoc('web two'))->call('save')->assertHasNoErrors();

    // The budget is spent across both surfaces: the API refuses with 429 + Retry-After…
    $this->withToken($token)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api three'])
        ->assertStatus(429)->assertHeader('Retry-After');
    // …and so does the composer.
    Livewire::actingAs($replier)->test('forum.reply-composer', ['topicId' => $topic->id])
        ->set('canonicalJson', rateDoc('web four'))->call('save')->assertHasErrors('body');

    expect(Post::where('topic_id', $topic->id)->where('user_id', $replier->id)->count())->toBe(2);
});

it('does not spend the posting budget on a request that fails validation', function () {
    config(['novfora.antispam.rate_limits' => ['tl1' => 1, 'default' => 1]]);
    [$topic, , $token] = rateFixture();

    $this->withToken($token)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => ''])->assertStatus(422);
    $this->withToken($token)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'valid'])->assertCreated();
});

it('bounds invalid-token floods by IP before the token lookup', function () {
    config(['novfora.api.rate_limits.ip' => 3, 'novfora.api.rate_limits.user' => 1000]);

    foreach (range(1, 3) as $i) {
        $this->withToken('nvf_garbage-'.$i)->getJson('/api/v1/me')->assertStatus(401);
    }
    $this->withToken('nvf_garbage-4')->getJson('/api/v1/me')->assertStatus(429);

    // A different source address has its own coarse bucket.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withToken('nvf_garbage-5')->getJson('/api/v1/me')->assertStatus(401);
});

it('limits an authenticated member by identity, from any IP, without affecting other members', function () {
    config(['novfora.api.rate_limits.ip' => 1000, 'novfora.api.rate_limits.user' => 2]);
    [, $replier, $token] = rateFixture();
    $other = app(ApiTokenService::class)->issue(Users::inGroups(['members', 'tl1']), 'other')['plaintext'];

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.1'])->withToken($token)->getJson('/api/v1/me')->assertOk();
    freshAuth();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.2'])->withToken($token)->getJson('/api/v1/me')->assertOk();
    freshAuth();
    // Third request from yet another IP: the member's bucket is spent regardless of address.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.3'])->withToken($token)->getJson('/api/v1/me')->assertStatus(429);
    freshAuth();

    // A second token for the SAME member shares the bucket (identity, not token).
    $second = app(ApiTokenService::class)->issue($replier, 'second')['plaintext'];
    $this->withToken($second)->getJson('/api/v1/me')->assertStatus(429);
    freshAuth();

    // Another member is unaffected — on the very first request too (no stale guard user to lean on).
    $this->withToken($other)->getJson('/api/v1/me')->assertOk();
});

it('applies the per-member ceiling to the admin API too, in the same bucket', function () {
    config(['novfora.api.rate_limits.ip' => 1000, 'novfora.api.rate_limits.user' => 1]);
    [, , $token] = rateFixture();

    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
    freshAuth();
    $this->withToken($token)->getJson('/api/admin/v1/openapi.json')->assertStatus(429);
});
