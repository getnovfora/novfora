<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\Models\ContentSubscription;
use App\Models\Forum;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Topic/forum follow-subscribe (M2, ADR-0097). Following is ungated participation (like bookmarks) — no ACL
 * key gates the toggle; the privacy fence lives in the fan-out (NotifySubscribersJob re-checks per-recipient
 * visibility), so a follow can never leak content the follower can't see.
 */
final class SubscriptionService
{
    public function isSubscribed(User $user, Model $target): bool
    {
        return ContentSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('subscribable_type', $target->getMorphClass())
            ->where('subscribable_id', $target->getKey())
            ->exists();
    }

    public function subscribe(User $user, Model $target): void
    {
        try {
            ContentSubscription::create([
                'user_id' => $user->getKey(),
                'subscribable_type' => $target->getMorphClass(),
                'subscribable_id' => $target->getKey(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // already subscribed (race) — idempotent
        }
    }

    public function unsubscribe(User $user, Model $target): void
    {
        ContentSubscription::query()
            ->where('user_id', $user->getKey())
            ->where('subscribable_type', $target->getMorphClass())
            ->where('subscribable_id', $target->getKey())
            ->delete();
    }

    public function toggle(User $user, Model $target): bool
    {
        if ($this->isSubscribed($user, $target)) {
            $this->unsubscribe($user, $target);

            return false;
        }

        $this->subscribe($user, $target);

        return true;
    }

    /**
     * Subscriber user ids for a subscribable (type+id), minus an already-notified exclude set.
     *
     * @param  list<int>  $exclude
     * @return list<int>
     */
    public function subscriberIds(string $type, int $id, array $exclude = []): array
    {
        return ContentSubscription::query()
            ->where('subscribable_type', $type)
            ->where('subscribable_id', $id)
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('user_id', $exclude))
            ->orderBy('user_id')
            ->pluck('user_id')
            ->map(fn ($v): int => (int) $v)
            ->all();
    }

    /** Map a short kind string to its model — the view never names a class. */
    public function resolve(string $kind, int $id): ?Model
    {
        return match ($kind) {
            'topic' => Topic::find($id),
            'forum' => Forum::find($id),
            default => null,
        };
    }
}
