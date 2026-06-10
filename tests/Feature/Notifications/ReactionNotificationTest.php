<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Forum\PostService;
use App\Forum\ReactionService;
use App\Mail\NotificationMail;
use App\Models\DigestPreference;
use App\Models\DigestQueueItem;
use App\Models\Forum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Users;

/*
| P2-M2 Half-A — reaction notifications wired END-TO-END from the P2-M1 Reacted seam through the Notifier:
| immediate-cadence authors get a mail now; batched-cadence authors have it staged into their digest; a
| self-reaction or a toggle-off notifies no one.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    $this->seed();
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $this->author = Users::inGroups(['members', 'tl2'], ['username' => 'author', 'email' => 'author@react.test']);
    $this->reactor = Users::inGroups(['members', 'tl2'], ['username' => 'reactor', 'email' => 'reactor@react.test']);
    $topic = app(PostService::class)->createTopic($this->author, $forum, 'A topic', 'markdown', ['source' => 'Opening.']);
    $this->post = $topic->posts()->first();
    $this->service = app(ReactionService::class);
});

it('notifies the post author end-to-end on an immediate-cadence author (in-app + mail)', function () {
    $this->service->toggle($this->reactor, $this->post, 'like');

    expect(DatabaseNotification::where('notifiable_id', $this->author->getKey())->where('type', 'reaction')->count())->toBe(1);
    Mail::assertQueued(NotificationMail::class, fn (NotificationMail $m) => $m->event === 'reaction' && $m->hasTo('author@react.test'));
});

it('stages a reaction into a batched-cadence author\'s digest, sending no immediate mail', function () {
    DigestPreference::create(['user_id' => $this->author->getKey(), 'cadence' => DigestPreference::DAILY]);

    $this->service->toggle($this->reactor, $this->post, 'like');

    Mail::assertNotQueued(NotificationMail::class);
    expect(DigestQueueItem::where('user_id', $this->author->getKey())->where('event_type', 'reaction')->count())->toBe(1);
    // The in-app notification still lands regardless of cadence.
    expect(DatabaseNotification::where('notifiable_id', $this->author->getKey())->where('type', 'reaction')->count())->toBe(1);
});

it('does not notify on a self-reaction', function () {
    $this->service->toggle($this->author, $this->post, 'like');

    expect(DatabaseNotification::where('type', 'reaction')->count())->toBe(0);
    Mail::assertNotQueued(NotificationMail::class);
});

it('emits no new notification when a reaction is toggled off', function () {
    $this->service->toggle($this->reactor, $this->post, 'like');   // add → notifies
    $this->service->toggle($this->reactor, $this->post, 'like');   // off → Reacted not dispatched

    expect(DatabaseNotification::where('type', 'reaction')->count())->toBe(1);
    Mail::assertQueued(NotificationMail::class, 1);
});
