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

it('runs the email deliverability self-test command', function () {
    $this->artisan('novfora:mail:test', ['email' => 'admin@example.com'])->assertSuccessful();
});
