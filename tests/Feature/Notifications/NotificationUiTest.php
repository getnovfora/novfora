<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Models\Forum;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The in-app notification surface (data-model §7): the list + mark-read, per-event×channel preferences, the
| polled unread-count bell (baseline near-real-time), and the email deliverability self-test.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->seed();
});

function giveNotification(User $author): void
{
    $forum = Forum::create(['slug' => 'f'.$author->getKey(), 'title' => 'F', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'tiptap_json', Content::doc('op'));
    app(PostService::class)->reply(Users::inGroups(['members', 'tl1']), $topic, 'tiptap_json', Content::doc('hi'));
}

it('lists notifications and marks one read', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);
    expect($user->unreadNotifications()->count())->toBe(1);
    $id = $user->notifications()->firstOrFail()->id;

    $this->actingAs($user)->get(route('notifications.index'))->assertOk()->assertSee('replied in');
    $this->actingAs($user)->post(route('notifications.read', $id))->assertRedirect();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('marks all notifications read', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);

    $this->actingAs($user)->post(route('notifications.read-all'))->assertRedirect();

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it('saves per-event×channel preferences', function () {
    $user = Users::inGroups(['members', 'tl1']);

    // Only reply/in-app checked → reply/mail (and everything else) is turned off.
    $this->actingAs($user)->post(route('settings.notifications.save'), ['pref' => ['reply' => ['database' => '1']]])->assertRedirect();

    $pref = fn (string $e, string $c) => NotificationPreference::where('user_id', $user->id)
        ->where('event_type', $e)->where('channel', $c)->first();
    expect($pref('reply', 'database')->enabled)->toBeTrue();
    expect($pref('reply', 'mail')->enabled)->toBeFalse();
});

it('polls the unread count via the bell island', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);

    Livewire::actingAs($user)->test('notification-bell')->assertSet('count', 1);
});

// ── BETA-1 / NOV-85: opening a notification IS reading it ─────────────────────────────────────────────

it('marks a notification read on click-through and redirects to its target', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);
    $n = $user->notifications()->firstOrFail();
    expect($n->read_at)->toBeNull();

    $this->actingAs($user)->get(route('notifications.open', $n->id))
        ->assertRedirect($n->data['url']);

    expect($user->fresh()->unreadNotifications()->count())->toBe(0);

    // Idempotent: opening again still redirects and stays read.
    $this->actingAs($user)->get(route('notifications.open', $n->id))->assertRedirect($n->data['url']);
    expect($user->fresh()->unreadNotifications()->count())->toBe(0);
});

it("404s when opening another user's notification and leaves it unread", function () {
    $owner = Users::inGroups(['members', 'tl1']);
    giveNotification($owner);
    $n = $owner->notifications()->firstOrFail();

    $other = Users::inGroups(['members', 'tl1']);
    $this->actingAs($other)->get(route('notifications.open', $n->id))->assertNotFound();

    expect($owner->fresh()->unreadNotifications()->count())->toBe(1);
});

it('falls back to the notifications index for a missing or foreign-origin target url', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);
    $n = $user->notifications()->firstOrFail();

    // No stored URL → index.
    $n->update(['data' => array_diff_key($n->data, ['url' => true])]);
    $this->actingAs($user)->get(route('notifications.open', $n->id))
        ->assertRedirect(route('notifications.index'));

    // A non-same-origin URL (defence-in-depth: emitters only store route()-built URLs today) → index, never
    // an open redirect. The boundary cases matter: a bare-prefix check would admit a look-alike host whose
    // name starts with ours (no host delimiter after the base). Every one of these must fall back to index.
    $base = rtrim(url('/'), '/'); // e.g. http://localhost
    $foreignUrls = [
        'https://evil.example/phish',
        '//evil.example/phish',              // protocol-relative
        '/\\evil.example/phish',             // backslash protocol-relative (some browsers honour)
        $base.'.evil.example/phish',         // look-alike suffix on the host
        $base.'@evil.example/phish',         // userinfo trick
        $base.'evil.example/phish',          // no delimiter — the exact bare-prefix bypass
    ];
    foreach ($foreignUrls as $foreign) {
        $n->update(['data' => array_merge($n->data, ['url' => $foreign]), 'read_at' => null]);
        $this->actingAs($user)->get(route('notifications.open', $n->id))
            ->assertRedirect(route('notifications.index'));
    }

    // A genuinely same-origin absolute URL (base + '/path') and a rooted-relative path both pass.
    foreach ([$base.'/topics/1', '/topics/1'] as $ok) {
        $n->update(['data' => array_merge($n->data, ['url' => $ok]), 'read_at' => null]);
        $this->actingAs($user)->get(route('notifications.open', $n->id))->assertRedirect($ok);
    }
});

it('renders the bell dropdown items as click-through links and refreshes count with the list', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);
    $n = $user->notifications()->firstOrFail();

    $component = Livewire::actingAs($user)->test('notification-bell')
        ->call('loadRecent')
        ->assertSet('count', 1)
        ->assertSee(route('notifications.open', $n->id), false);

    // Read transition made elsewhere (e.g. the index page, another tab) → reopening the dropdown reloads
    // BOTH the list and the badge (the old first-open latch kept them frozen until a full page load).
    $n->markAsRead();
    $component->call('loadRecent')->assertSet('count', 0);
    expect(collect($component->get('recent'))->firstWhere('id', $n->id)['unread'])->toBeFalse();
});

it('routes the index list items through the click-through link', function () {
    $user = Users::inGroups(['members', 'tl1']);
    giveNotification($user);
    $n = $user->notifications()->firstOrFail();

    $this->actingAs($user)->get(route('notifications.index'))
        ->assertOk()
        ->assertSee(route('notifications.open', $n->id), false);
});

it('runs the email deliverability self-test command', function () {
    $this->artisan('novfora:mail:test', ['email' => 'admin@example.com'])->assertSuccessful();
});
