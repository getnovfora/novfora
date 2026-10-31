<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostScheduler;
use App\Forum\PostService;
use App\Models\Forum;
use App\Models\Post;
use App\Models\ScheduledPost;
use App\Models\Topic;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\Users;

/*
| NF-07 (NOV-150) — "board offline" gates every way a member can act, not just page loads: an already-open
| Livewire composer (through the REAL update endpoint, where Livewire re-applies the gate to the component's
| originating route), the REST API, and the scheduled-reply publisher. Admins stay the recovery exception.
|
| Livewire skips persistent middleware under Livewire::test(), so the composer cases drive the actual HTTP
| update endpoint with a snapshot harvested from the rendered topic page — exactly what the browser posts.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function offlineTopic(): Topic
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);

    return app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Offline topic', 'markdown', ['source' => 'op']);
}

function boardOffline(bool $offline = true): void
{
    app(Settings::class)->set('general.board_offline', $offline);
}

/** @return array{0:string,1:string} [updatePath, the reply composer's snapshot JSON] from the rendered topic page */
function harvestComposer(mixed $test, User $user, Topic $topic): array
{
    $html = $test->actingAs($user)->get(route('topics.show', $topic))->assertOk()->getContent();

    expect(preg_match('/data-update-uri="([^"]+)"/', $html, $u))->toBe(1);
    $updatePath = (string) parse_url(html_entity_decode($u[1], ENT_QUOTES), PHP_URL_PATH);

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $all);
    foreach ($all[1] as $raw) {
        $snapshot = html_entity_decode($raw, ENT_QUOTES);
        if ((json_decode($snapshot, true)['memo']['name'] ?? null) === 'forum.reply-composer') {
            return [$updatePath, $snapshot];
        }
    }

    throw new RuntimeException('reply composer not rendered on the topic page');
}

function submitComposer(mixed $test, string $updatePath, string $snapshot, string $text): TestResponse
{
    $doc = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];

    return $test->withHeaders(['X-Livewire' => '1'])->postJson($updatePath, [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => ['canonicalJson' => $doc],
            'calls' => [['path' => '', 'method' => 'save', 'params' => []]],
        ]],
    ]);
}

function replied(Topic $topic, string $needle): bool
{
    return Post::where('topic_id', $topic->id)->where('body_text', 'like', "%{$needle}%")->exists();
}

// ── Livewire: an editor opened BEFORE the switch ──────────────────────────────────────────────────────

it('accepts a composer submit through the real update endpoint while online (harness control)', function () {
    $topic = offlineTopic();
    $member = Users::inGroups(['members', 'tl1']);
    [$path, $snapshot] = harvestComposer($this, $member, $topic);

    submitComposer($this, $path, $snapshot, 'online reply')->assertOk();

    expect(replied($topic, 'online reply'))->toBeTrue();
});

it('refuses a member\'s already-open composer once the board goes offline', function () {
    $topic = offlineTopic();
    $member = Users::inGroups(['members', 'tl1']);
    [$path, $snapshot] = harvestComposer($this, $member, $topic);

    boardOffline();
    submitComposer($this, $path, $snapshot, 'stale offline reply')->assertStatus(503);

    expect(replied($topic, 'stale offline reply'))->toBeFalse();
});

it('still lets an admin post through an open composer while the board is offline', function () {
    $topic = offlineTopic();
    $admin = Users::withTwoFactor(Users::inGroups(['members', 'admins']));
    [$path, $snapshot] = harvestComposer($this, $admin, $topic);

    boardOffline();
    submitComposer($this, $path, $snapshot, 'admin offline reply')->assertOk();

    expect(replied($topic, 'admin offline reply'))->toBeTrue();
});

// ── REST API ──────────────────────────────────────────────────────────────────────────────────────────

it('refuses a member API token with a JSON 503 while offline, and lets an admin token through', function () {
    $topic = offlineTopic();
    $member = app(ApiTokenService::class)->issue(Users::inGroups(['members', 'tl1']), 'nf07')['plaintext'];
    $admin = app(ApiTokenService::class)->issue(Users::inGroups(['members', 'admins']), 'nf07-admin')['plaintext'];

    boardOffline();

    $this->withToken($member)->getJson('/api/v1/me')->assertStatus(503)->assertJsonStructure(['message']);
    app('auth')->forgetGuards();
    $this->withToken($member)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'api offline reply'])->assertStatus(503);
    app('auth')->forgetGuards();
    $this->withToken($admin)->postJson("/api/v1/topics/{$topic->id}/posts", ['body' => 'admin api reply'])->assertCreated();

    expect(replied($topic, 'api offline reply'))->toBeFalse()
        ->and(replied($topic, 'admin api reply'))->toBeTrue();
});

it('serves the member API normally once the board is back online', function () {
    $token = app(ApiTokenService::class)->issue(Users::inGroups(['members', 'tl1']), 'nf07')['plaintext'];

    boardOffline();
    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(503);
    boardOffline(false);
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();
});

// ── Scheduled replies ─────────────────────────────────────────────────────────────────────────────────

it('defers (not skips) a member\'s scheduled reply while offline, then publishes it once back online', function () {
    $topic = offlineTopic();
    $member = Users::inGroups(['members', 'tl1']);
    $scheduled = ScheduledPost::create([
        'user_id' => $member->id, 'topic_id' => $topic->id, 'body_format' => 'markdown',
        'body_canonical' => ['source' => 'scheduled while offline'], 'publish_at' => now()->subMinute(),
    ]);

    boardOffline();
    expect(app(PostScheduler::class)->publishDue())->toBe(0)
        ->and($scheduled->fresh()->published_at)->toBeNull();   // still due — deferred, not dropped

    boardOffline(false);
    expect(app(PostScheduler::class)->publishDue())->toBe(1)
        ->and(replied($topic, 'scheduled while offline'))->toBeTrue();
});

it('publishes an admin\'s scheduled reply even while offline', function () {
    $topic = offlineTopic();
    $admin = Users::inGroups(['members', 'admins']);
    ScheduledPost::create([
        'user_id' => $admin->id, 'topic_id' => $topic->id, 'body_format' => 'markdown',
        'body_canonical' => ['source' => 'admin scheduled'], 'publish_at' => now()->subMinute(),
    ]);

    boardOffline();
    expect(app(PostScheduler::class)->publishDue())->toBe(1);
});
