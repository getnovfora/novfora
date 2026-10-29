<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Api\ApiTokenService;
use App\Forum\PostService;
use App\Forum\ReplyGate;
use App\Forum\TopicVisibility;
use App\Models\AclEntry;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use App\Permissions\PermissionResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

// ── Apex-review regressions (NF-03 follow-up) ───────────────────────────────────────────────────────────

it('keeps a forum moderator recused at the THREAD scope out of that held topic on every surface', function () {
    $f = parityFixture();
    $moderator = Users::inGroups(['members', 'moderators']);
    AclEntry::create([
        'permission_key' => 'topic.moderate', 'holder_type' => 'user', 'holder_id' => $moderator->getKey(),
        'scope_type' => 'thread', 'scope_id' => $f['pendingOther']->getKey(), 'value' => -1,
    ]);
    app(PermissionResolver::class)->flushMemo();

    $this->actingAs($moderator)->get(route('topics.show', $f['pendingOther']))->assertNotFound();
    $this->actingAs($moderator)->get(route('forums.show', $f['forum']))->assertOk()->assertDontSee('Held topic by other');
    expect(apiTitles($moderator, $f['forum'], $this))->not->toContain('Held topic by other')
        ->toContain('Held topic by viewer'); // still a moderator everywhere else
});

it('lists a held topic for a member who moderates only that THREAD, matching the topic page', function () {
    $f = parityFixture();
    $threadMod = Users::inGroups(['members', 'tl1']);
    AclEntry::create([
        'permission_key' => 'topic.moderate', 'holder_type' => 'user', 'holder_id' => $threadMod->getKey(),
        'scope_type' => 'thread', 'scope_id' => $f['pendingOther']->getKey(), 'value' => 1,
    ]);
    app(PermissionResolver::class)->flushMemo();

    $this->actingAs($threadMod)->get(route('topics.show', $f['pendingOther']))->assertOk();
    expect(apiTitles($threadMod, $f['forum'], $this))->toContain('Held topic by other')
        ->not->toContain('Held topic by viewer');
});

it('does not resolve thread entries held by OTHER members when listing (bounded query cost)', function () {
    $f = parityFixture();
    $someoneElse = Users::inGroups(['members', 'tl1']);
    $svc = app(PostService::class);
    $extra = collect(range(1, 20))->map(fn (int $i) => $svc->createTopic($f['other'], $f['forum'], "Extra {$i}", 'markdown', ['source' => 'x']));
    $baseline = function () use ($f) {
        app(PermissionResolver::class)->flushMemo();
        cache()->flush();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->actingAs($f['viewer'])->get(route('forums.show', $f['forum']))->assertOk();

        return count(DB::getQueryLog());
    };
    $before = $baseline();

    // 20 thread-scoped forum.view grants held by someone else — none can change THIS viewer's verdict.
    foreach ($extra as $topic) {
        AclEntry::create([
            'permission_key' => 'forum.view', 'holder_type' => 'user', 'holder_id' => $someoneElse->getKey(),
            'scope_type' => 'thread', 'scope_id' => $topic->getKey(), 'value' => 1,
        ]);
    }

    expect($baseline())->toBeLessThanOrEqual($before + 2);
});

it('never treats a guest as the author of an author-less held topic', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl1']), $forum, 'Orphan held', 'markdown', ['source' => 'x']);
    $topic->forceFill(['approved_state' => 'pending', 'user_id' => null])->saveQuietly();

    expect(app(TopicVisibility::class)->canView(User::guest(), $topic))->toBeFalse()
        ->and(app(ReplyGate::class)->check(User::guest(), $topic))->toBe(ReplyGate::HIDDEN);
});
