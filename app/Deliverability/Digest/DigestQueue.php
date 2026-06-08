<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Digest;

use App\Deliverability\SuppressionGate;
use App\Models\DigestQueueItem;
use App\Models\User;

/**
 * Spike P2 — the reference ingestion seam. This is what P2-M2 would call from the notification path to
 * STAGE a pending notification for a future digest (instead of sending immediately) when the recipient's
 * cadence is batched. The spike does NOT wire it into App\Notifications\Notifier — tests and the reference
 * call it directly; the live immediate path is untouched.
 *
 * Idempotent on (notification_id, cadence): re-staging the same source notification is a no-op. Stores only
 * a payload snapshot, never rendered HTML.
 */
final class DigestQueue
{
    public function __construct(private readonly SuppressionGate $gate) {}

    /**
     * Stage a notification for the user's digest. Returns the item, or null when the user is not in a
     * batched cadence (immediate/off — handled by the live path / silenced), so callers can fall back to
     * immediate send.
     *
     * @param  array<string,mixed>  $payload  {thread_id?, topic_title?, post_id?, url?}
     */
    public function enqueue(User $recipient, string $event, ?User $actor, array $payload, ?string $notificationId = null): ?DigestQueueItem
    {
        $cadence = $this->gate->cadence($recipient);
        if (! in_array($cadence, \App\Models\DigestPreference::BATCHED, true)) {
            return null; // immediate / off — not the digest path
        }

        $attributes = [
            'user_id' => $recipient->getKey(),
            'event_type' => $event,
            'actor_username' => $actor?->username,
            'payload' => $payload,
            'cadence' => $cadence,
            'notification_id' => $notificationId,
            'created_at' => now(),
        ];

        // Dedupe on the source notification id when we have one. createOrFirst (not firstOrCreate) so a
        // concurrent re-stage races safely on the UNIQUE index instead of throwing. A NULL id is exempt from
        // the unique, so ad-hoc items are always inserted.
        if ($notificationId !== null) {
            return DigestQueueItem::createOrFirst(
                ['notification_id' => $notificationId, 'cadence' => $cadence],
                $attributes,
            );
        }

        return DigestQueueItem::create($attributes);
    }
}
