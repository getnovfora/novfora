<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Models\DigestPreference;
use App\Models\DigestQueueItem;
use App\Models\User;

/** Test helpers for the Spike P2 deliverability suite — a user with a cadence, and staged digest items. */
final class Deliverability
{
    /** A user opted into a batched digest cadence (default daily). */
    public static function user(string $cadence = DigestPreference::DAILY, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        DigestPreference::create(['user_id' => $user->getKey(), 'cadence' => $cadence]);

        return $user;
    }

    /** Stage N pending digest items for a user (unclaimed). */
    public static function stage(User $user, int $count, string $cadence = DigestPreference::DAILY, string $event = 'reply'): void
    {
        for ($i = 0; $i < $count; $i++) {
            DigestQueueItem::create([
                'user_id' => $user->getKey(),
                'event_type' => $event,
                'actor_username' => 'actor'.$i,
                'payload' => ['topic_title' => 'Topic '.$i, 'url' => 'https://example.test/t/'.$i],
                'cadence' => $cadence,
                'notification_id' => null,
                'created_at' => now(),
            ]);
        }
    }
}
