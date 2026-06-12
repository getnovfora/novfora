<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Community\FollowService;
use App\Models\NotificationPreference;
use App\Models\UserRelationship;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Users;

/*
| The follow notification (P2-M5): FollowService fires Followed → the queued SendFollowNotification wires
| the REAL emitter onto the 'follow' vocab seated in M2 Half-A. Delivery honours the followee's ignore
| graph and per-event prefs; the UNIQUE-keyed idempotent follow means a double-submit never double-notifies.
| (QUEUE_CONNECTION=sync in tests → the queued listener runs inline at dispatch.)
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
});

function followNotificationsFor(int $userId): int
{
    return (int) DB::table('notifications')
        ->where('notifiable_id', $userId)
        ->where('type', 'follow')
        ->count();
}

it('writes the in-app follow notification to the followee, once, even on a double-submit', function () {
    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    $service = app(FollowService::class);

    $service->follow($follower, $followee);
    $service->follow($follower, $followee); // idempotent no-op — no second event, no second row

    expect(followNotificationsFor((int) $followee->id))->toBe(1);

    $data = json_decode((string) DB::table('notifications')->where('notifiable_id', $followee->id)->value('data'), true);
    expect($data['event'])->toBe('follow')
        ->and($data['actors'][0]['username'])->toBe($follower->username);
});

it('keeps the follow edge but delivers no notification when the followee ignores the follower', function () {
    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    // followee (user_id = the ignorer) ignores follower (related_user_id = the ignored).
    UserRelationship::factory()->ignore()->create([
        'user_id' => $followee->id,
        'related_user_id' => $follower->id,
    ]);

    expect(app(FollowService::class)->follow($follower, $followee))->toBeTrue(); // the edge still forms

    expect(followNotificationsFor((int) $followee->id))->toBe(0);
});

it('does not flood the inbox on follow/unfollow cycling — one unread notification per follower', function () {
    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    $service = app(FollowService::class);

    // Cycle within the rate limit: each follow creates a NEW edge and re-fires Followed — the unread
    // dedupe in SendFollowNotification must still collapse it to ONE row (and one mail send).
    $service->follow($follower, $followee);
    $service->unfollow($follower, $followee);
    $service->follow($follower, $followee);
    $service->unfollow($follower, $followee);
    $service->follow($follower, $followee);

    expect(followNotificationsFor((int) $followee->id))->toBe(1);

    // Once read, a genuinely new follow notifies again.
    DB::table('notifications')->where('notifiable_id', $followee->id)->update(['read_at' => now()]);
    $service->unfollow($follower, $followee);
    $service->follow($follower, $followee);

    expect((int) DB::table('notifications')->where('notifiable_id', $followee->id)->where('type', 'follow')->count())->toBe(2);
});

it('honours the followee per-event database-channel preference', function () {
    $follower = Users::inGroups(['members', 'tl1']);
    $followee = Users::inGroups(['members', 'tl1']);
    NotificationPreference::create([
        'user_id' => $followee->id, 'event_type' => 'follow', 'channel' => 'database', 'enabled' => false,
    ]);
    NotificationPreference::create([
        'user_id' => $followee->id, 'event_type' => 'follow', 'channel' => 'mail', 'enabled' => false,
    ]);

    app(FollowService::class)->follow($follower, $followee);

    expect(followNotificationsFor((int) $followee->id))->toBe(0);
});
