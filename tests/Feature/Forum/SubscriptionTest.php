<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\SubscriptionService;
use App\Jobs\NotifySubscribersJob;
use App\Models\ContentSubscription;
use App\Models\Forum;
use App\Models\NotificationPreference;
use App\Models\Topic;
use App\Models\User;
use App\Notifications\Notifier;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| M2 — topic/forum follow-subscribe with a BOUNDED + QUEUED notification fan-out (ADR-0097, apex). Covers the
| subscribe toggle, notify-on-reply (topic) + notify-on-new-topic (forum), the already-notified exclusions, the
| per-recipient VISIBILITY fence, the fan-out CAP, the prefs honour, and that the fan-out is QUEUED not inline.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    Mail::fake(); // Notifier queues NotificationMail on the mail channel — fake it; we assert the DB notification.
});

function subForum(): Forum
{
    return Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
}

/** Count of 'subscription'-event database notifications a user holds. */
function subNotifs(User $u): int
{
    return $u->notifications()->get()->filter(fn ($n) => ($n->data['event'] ?? null) === 'subscription')->count();
}

// ── Toggle ───────────────────────────────────────────────────────────────────────────────────────────────

it('subscribes, reports, and unsubscribes a member to a topic', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), subForum(), 'T', 'tiptap_json', Content::doc('op'));
    $user = Users::inGroups(['members']);
    $svc = app(SubscriptionService::class);

    expect($svc->isSubscribed($user, $topic))->toBeFalse();
    expect($svc->toggle($user, $topic))->toBeTrue();
    expect($svc->isSubscribed($user, $topic))->toBeTrue();
    expect($svc->toggle($user, $topic))->toBeFalse();
    expect(ContentSubscription::count())->toBe(0);
});

it('toggles via the subscribe-button component', function () {
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), subForum(), 'T', 'tiptap_json', Content::doc('op'));

    Livewire::actingAs(Users::inGroups(['members']))
        ->test('forum.subscribe-button', ['kind' => 'topic', 'targetId' => $topic->id])
        ->assertSet('subscribed', false)
        ->call('toggle')->assertSet('subscribed', true)
        ->call('toggle')->assertSet('subscribed', false);
});

// ── Fan-out: notify on reply / new topic ─────────────────────────────────────────────────────────────────

it('notifies a topic follower on a new reply, but not the author / OP / non-followers', function () {
    $forum = subForum();
    $op = Users::inGroups(['members', 'tl4']);
    $topic = app(PostService::class)->createTopic($op, $forum, 'T', 'tiptap_json', Content::doc('op'));

    $follower = Users::inGroups(['members', 'tl4']);
    $nonFollower = Users::inGroups(['members', 'tl4']);
    app(SubscriptionService::class)->subscribe($follower, $topic);
    app(SubscriptionService::class)->subscribe($op, $topic); // the OP also follows — must NOT be double-notified

    $replier = Users::inGroups(['members', 'tl4']);
    app(PostService::class)->reply($replier, $topic, 'tiptap_json', Content::doc('a reply'));

    expect(subNotifs($follower))->toBe(1)        // the follower is notified
        ->and(subNotifs($nonFollower))->toBe(0)  // a non-follower is not
        ->and(subNotifs($replier))->toBe(0)      // the author is not
        ->and(subNotifs($op))->toBe(0);          // the OP got 'reply', excluded from the subscription fan-out
});

it('notifies a forum follower on a new topic', function () {
    $forum = subForum();
    $follower = Users::inGroups(['members', 'tl4']);
    app(SubscriptionService::class)->subscribe($follower, $forum);

    app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'Brand new', 'tiptap_json', Content::doc('hello'));

    expect(subNotifs($follower))->toBe(1);
});

// ── Visibility fence ─────────────────────────────────────────────────────────────────────────────────────

it('never notifies a follower who cannot see the forum (forum.view NEVER)', function () {
    $acl = Acl::make();
    $forum = $acl->forum; // a board forum
    // Trusted OP + replier so the posts are APPROVED (the fan-out only fires for approved content) — this
    // isolates the test to the VISIBILITY gate, not the approval gate.
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    // A follower in a group that is hard-denied forum.view at this forum.
    $blockedGroup = $acl->group('blocked', ['priority' => 5]);
    $acl->grant($blockedGroup, 'forum.view', $acl->forumScope, V::Never);
    $blocked = $acl->user(['blocked']);
    app(SubscriptionService::class)->subscribe($blocked, $topic);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl4']), $topic, 'tiptap_json', Content::doc('reply'));

    expect(subNotifs($blocked))->toBe(0); // the privacy fence skipped them
});

// ── Bounded fan-out (cap) ────────────────────────────────────────────────────────────────────────────────

it('caps the subscription fan-out to the configured limit', function () {
    config(['novfora.subscriptions.fanout_cap' => 2]);
    $forum = subForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    $followers = collect(range(1, 5))->map(function () use ($topic) {
        $u = Users::inGroups(['members', 'tl4']);
        app(SubscriptionService::class)->subscribe($u, $topic);

        return $u;
    });

    app(PostService::class)->reply(Users::inGroups(['members', 'tl4']), $topic, 'tiptap_json', Content::doc('reply'));

    expect($followers->filter(fn (User $u) => subNotifs($u) > 0)->count())->toBe(2); // only the cap were notified
});

// ── Preferences honoured ─────────────────────────────────────────────────────────────────────────────────

it('respects a follower who opted out of subscription notifications', function () {
    $forum = subForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $follower = Users::inGroups(['members', 'tl4']);
    app(SubscriptionService::class)->subscribe($follower, $topic);
    NotificationPreference::create([
        'user_id' => $follower->id, 'event_type' => 'subscription', 'channel' => 'database', 'enabled' => false,
    ]);

    app(PostService::class)->reply(Users::inGroups(['members', 'tl4']), $topic, 'tiptap_json', Content::doc('reply'));

    expect(subNotifs($follower))->toBe(0);
});

// ── Fail-closed when the forum is gone (apex-review defense-in-depth) ─────────────────────────────────────

it('does not fan out when the topic forum cannot be loaded (fail-closed)', function () {
    Queue::fake(); // suppress the inline sync run so we control when the job executes
    $forum = subForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));
    $follower = Users::inGroups(['members', 'tl4']);
    app(SubscriptionService::class)->subscribe($follower, $topic);
    $reply = app(PostService::class)->reply(Users::inGroups(['members', 'tl4']), $topic, 'tiptap_json', Content::doc('r'));

    // The forum is soft-deleted by the time the queued job runs — Forum::find() (default scope) now returns
    // null for it, the exact race the visibility fence must fail closed on.
    $forum->delete();
    (new NotifySubscribersJob($reply->id, $topic->getMorphClass(), $topic->id, []))
        ->handle(app(SubscriptionService::class), app(Notifier::class));

    expect(subNotifs($follower))->toBe(0);
});

// ── Bounded + QUEUED (not synchronous) ───────────────────────────────────────────────────────────────────

it('dispatches the fan-out as a QUEUED job (never a synchronous in-request loop)', function () {
    Queue::fake();
    $forum = subForum();
    $topic = app(PostService::class)->createTopic(Users::inGroups(['members', 'tl4']), $forum, 'T', 'tiptap_json', Content::doc('op'));

    app(PostService::class)->reply(Users::inGroups(['members', 'tl4']), $topic, 'tiptap_json', Content::doc('reply'));

    Queue::assertPushed(NotifySubscribersJob::class);
});
