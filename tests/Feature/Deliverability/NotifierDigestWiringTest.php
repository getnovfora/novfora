<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Mail\NotificationMail;
use App\Models\DigestQueueItem;
use App\Models\EmailSuppression;
use App\Models\User;
use App\Notifications\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Deliverability;

/*
| P2-M2 Half-A — the Notifier→DigestQueue wiring (spike-p2-memo §4). The MAIL channel routes by digest
| cadence: immediate (the default) takes the UNCHANGED live path; daily/weekly STAGE into the cron digest;
| off (1-click unsubscribe) sends no notification mail at all. Suppression goes through the ONE shared
| SuppressionGate. The in-app (database) channel is unaffected by cadence.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => Mail::fake());

function notify(User $recipient, string $event = 'reply', array $payload = ['thread_id' => 1, 'topic_title' => 'T', 'url' => 'https://x.test/t/1']): void
{
    app(Notifier::class)->send($recipient, $event, User::factory()->create(), $payload);
}

it('keeps the live immediate path for an immediate-cadence user (the default) — nothing staged', function () {
    notify(User::factory()->create()); // no DigestPreference row → immediate

    Mail::assertQueued(NotificationMail::class, 1);
    expect(DigestQueueItem::count())->toBe(0);
});

it('stages a batched-cadence user into the digest and sends NO immediate mail', function () {
    $user = Deliverability::user('daily');

    notify($user, 'reply', ['thread_id' => 7, 'topic_title' => 'Hello', 'url' => 'https://x.test/t/7']);

    Mail::assertNotQueued(NotificationMail::class);
    expect(DigestQueueItem::where('user_id', $user->getKey())->count())->toBe(1);
    $item = DigestQueueItem::where('user_id', $user->getKey())->first();
    expect($item->event_type)->toBe('reply')
        ->and($item->cadence)->toBe('daily')
        ->and($item->payload['topic_title'])->toBe('Hello');
});

it('sends NO notification mail to an off-cadence (unsubscribed) user — neither digest nor immediate', function () {
    $user = Deliverability::user('off');

    notify($user);

    Mail::assertNotQueued(NotificationMail::class);
    expect(DigestQueueItem::count())->toBe(0);
});

it('skips a suppressed address through the single shared gate — no immediate mail, no digest staging', function () {
    $immediate = User::factory()->create();
    $daily = Deliverability::user('daily');
    EmailSuppression::create(['email' => $immediate->email, 'reason' => 'bounce', 'created_at' => now()]);
    EmailSuppression::create(['email' => $daily->email, 'reason' => 'complaint', 'created_at' => now()]);

    notify($immediate);
    notify($daily);

    Mail::assertNotQueued(NotificationMail::class);
    expect(DigestQueueItem::count())->toBe(0);
});

it('still writes the in-app notification regardless of digest cadence', function () {
    $user = Deliverability::user('daily');

    notify($user, 'reply', ['thread_id' => 3, 'topic_title' => 'T', 'url' => 'https://x.test/t/3']);

    expect(DatabaseNotification::where('notifiable_id', $user->getKey())->where('type', 'reply')->count())->toBe(1);
});

it('does not double-stage the same merged in-app notification into the digest', function () {
    $user = Deliverability::user('daily');
    $payload = ['thread_id' => 9, 'topic_title' => 'Same thread', 'url' => 'https://x.test/t/9'];

    // Two replies in the SAME thread fold into one unread in-app notification → one source notification id →
    // the digest dedupes on (notification_id, cadence), so the digest carries one line for the thread.
    notify($user, 'reply', $payload);
    notify($user, 'reply', $payload);

    expect(DatabaseNotification::where('notifiable_id', $user->getKey())->where('type', 'reply')->count())->toBe(1)
        ->and(DigestQueueItem::where('user_id', $user->getKey())->count())->toBe(1);
});

it('never notifies the actor themselves', function () {
    $user = Deliverability::user('daily');
    app(Notifier::class)->send($user, 'reply', $user, ['thread_id' => 1]);

    expect(DigestQueueItem::count())->toBe(0)
        ->and(DatabaseNotification::count())->toBe(0);
});
