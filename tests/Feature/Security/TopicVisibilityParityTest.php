<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Users;

/*
| NF-03 (NOV-146) — the REST API and the web board listing answer "which topics may this viewer see?" with ONE
| selector. Fixture: a readable forum holding an approved topic, a pending topic by someone else, a pending
| topic by the viewer, and an approved topic the viewer is explicitly denied at the THREAD scope. Rows and
| counts must never disclose a hidden topic, on either surface, for member / author / moderator.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** @return array{forum:Forum,viewer:User,other:User,approved:Topic,pendingOther:Topic,pendingOwn:Topic,denied:Topic} */
function parityFixture(): array
{
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $viewer = Users::inGroups(['members', 'tl1']);
    $other = Users::inGroups(['members', 'tl1']);
    $svc = app(PostService::class);

    $approved = $svc->createTopic($other, $forum, 'Visible approved topic', 'markdown', ['source' => 'a']);
    $pendingOther = $svc->createTopic($other, $forum, 'Held topic by other', 'markdown', ['source' => 'b']);
    $pendingOwn = $svc->createTopic($viewer, $forum, 'Held topic by viewer', 'markdown', ['source' => 'c']);
    $denied = $svc->createTopic($other, $forum, 'Thread denied topic', 'markdown', ['source' => 'd']);

    $pendingOther->forceFill(['approved_state' => 'pending'])->saveQuietly();
    $pendingOwn->forceFill(['approved_state' => 'pending'])->saveQuietly();

    AclEntry::create([
        'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $viewer->getKey(),
        'scope_type' => 'thread', 'scope_id' => $denied->getKey(), 'value' => -1, // NEVER on this one thread
    ]);
    app(PermissionResolver::class)->flushMemo();

    return compact('forum', 'viewer', 'other', 'approved', 'pendingOther', 'pendingOwn', 'denied');
}

/** @return list<string> topic titles the API lists for this user */
function apiTitles(User $user, Forum $forum, mixed $test): array
{
    $token = app(ApiTokenService::class)->issue($user, 'nf03')['plaintext'];
    $res = $test->withToken($token)->getJson("/api/v1/forums/{$forum->id}/topics")->assertOk();

    $titles = collect($res->json('data'))->pluck('title')->sort()->values()->all();
    expect($res->json('meta.total'))->toBe(count($titles)); // the count never includes hidden rows

    return $titles;
}

it('lists only visible topics over the API — no pending-by-others, no thread-denied, own pending kept', function () {
    $f = parityFixture();

    expect(apiTitles($f['viewer'], $f['forum'], $this))->toBe(['Held topic by viewer', 'Visible approved topic']);
});

it('keeps the web board listing and the API in agreement for the same viewer', function () {
    $f = parityFixture();

    $web = $this->actingAs($f['viewer'])->get(route('forums.show', $f['forum']))->assertOk();
    $web->assertSee('Visible approved topic')->assertSee('Held topic by viewer')
        ->assertDontSee('Held topic by other')->assertDontSee('Thread denied topic');

    expect(apiTitles($f['viewer'], $f['forum'], $this))->toBe(['Held topic by viewer', 'Visible approved topic']);
});

it('shows the author their own held topic and an unaffected member only the approved ones', function () {
    $f = parityFixture();
    $bystander = Users::inGroups(['members', 'tl1']);

    // The other author sees their own held topic plus the approved ones (no thread denial applies to them).
    expect(apiTitles($f['other'], $f['forum'], $this))
        ->toBe(['Held topic by other', 'Thread denied topic', 'Visible approved topic']);
    // A bystander sees only approved topics.
    expect(apiTitles($bystander, $f['forum'], $this))->toBe(['Thread denied topic', 'Visible approved topic']);
});

it('shows a moderator every held topic', function () {
    $f = parityFixture();
    $moderator = Users::inGroups(['members', 'moderators']);

    expect(apiTitles($moderator, $f['forum'], $this))
        ->toBe(['Held topic by other', 'Held topic by viewer', 'Thread denied topic', 'Visible approved topic']);
});

it('404s a single-topic API read of someone else\'s held topic, like the web topic page', function () {
    $f = parityFixture();
    $token = app(ApiTokenService::class)->issue($f['viewer'], 'nf03')['plaintext'];

    $this->withToken($token)->getJson("/api/v1/topics/{$f['pendingOther']->id}")->assertNotFound();
    $this->actingAs($f['viewer'])->get(route('topics.show', $f['pendingOther']))->assertNotFound();

    // Own held topic and approved topics stay readable; the thread-denied one is refused.
    $this->withToken($token)->getJson("/api/v1/topics/{$f['pendingOwn']->id}")->assertOk();
    $this->withToken($token)->getJson("/api/v1/topics/{$f['approved']->id}")->assertOk();
    $this->withToken($token)->getJson("/api/v1/topics/{$f['denied']->id}")->assertForbidden();
});

it('hides a topic from a viewer banned at that thread scope', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $viewer = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Banned from this one', 'markdown', ['source' => 'x']);
    Ban::create(['user_id' => $viewer->id, 'scope_type' => 'thread', 'scope_id' => $topic->id, 'reason' => 'test']);
    app(PermissionResolver::class)->flushMemo();

    expect(apiTitles($viewer, $forum, $this))->toBe([]);
});
