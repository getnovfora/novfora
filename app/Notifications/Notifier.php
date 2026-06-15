<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Notifications;

use App\Deliverability\Digest\DigestQueue;
use App\Deliverability\SuppressionGate;
use App\Events\NotificationReceived;
use App\Jobs\SendPushNotification;
use App\Mail\NotificationMail;
use App\Models\DigestPreference;
use App\Models\NotificationPreference;
use App\Models\PushSubscription;
use App\Models\User;
use App\Services\Tier\Capability;
use App\Services\Tier\ServiceTier;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The notification dispatcher (data-model §7). Two channels: `database` (in-app, polled on baseline) and
 * `mail` (queued, cron-drained — ADR-0011). Database notifications are MERGE-AWARE — a new reply/mention
 * folds into the recipient's existing UNREAD same-thread notification ("X and N others …") instead of
 * stacking rows. Per-event × per-channel preferences are honoured (default on); the suppression list and
 * self-notification are skipped. All channels degrade gracefully — a mail failure never breaks the post.
 *
 * P2-M2 deliverability wiring: the MAIL channel routes by the recipient's digest cadence (the live default
 * is `immediate`). `daily`/`weekly` recipients are STAGED into the cron digest via {@see DigestQueue} instead
 * of an immediate send; `off` (what 1-click unsubscribe sets) sends no notification mail at all. The
 * immediate path — the default — is unchanged. Suppression is checked through the shared {@see SuppressionGate}
 * so there is ONE send-time suppression gate across the immediate and digest paths (spike-p2-memo §4).
 */
final class Notifier
{
    private const MAX_ACTORS = 5;

    public function __construct(
        private readonly DigestQueue $digestQueue,
        private readonly SuppressionGate $gate,
    ) {}

    /**
     * @param  array{thread_id?:int, topic_title?:string, post_id?:int, url?:string}  $payload
     */
    public function send(User $recipient, string $event, User $actor, array $payload): void
    {
        if (! $recipient->getKey() || (int) $recipient->getKey() === (int) $actor->getKey()) {
            return; // never notify yourself
        }

        // The in-app (database) notification is the live polled channel — written immediately regardless of
        // digest cadence (cadence governs MAIL delivery only). Its id seeds the digest dedupe below.
        $notificationId = null;
        if ($this->prefers($recipient, $event, 'database')) {
            $notificationId = $this->writeDatabase($recipient, $event, $actor, $payload);

            // Realtime ping (Phase 4 · M4.2) — on the enhanced tier only, so the recipient's bell updates
            // instantly over their PRIVATE notifications channel. Guarded so the baseline pays nothing (no
            // extra count query, no event): the bell keeps its 30s poll. Carries only the unread count.
            if (app(ServiceTier::class)->isEnhanced(Capability::Broadcast)) {
                NotificationReceived::dispatch((int) $recipient->getKey(), $recipient->unreadNotifications()->count());
            }
        }

        if ($this->prefers($recipient, $event, 'mail') && $recipient->email && ! $this->gate->suppressed($recipient->email)) {
            $cadence = $this->gate->cadence($recipient);

            if (in_array($cadence, DigestPreference::BATCHED, true)) {
                // Batched cadence (daily/weekly): STAGE for the cron digest instead of sending now. Exactly-once
                // assembly rests on the committed UNIQUE(user,cadence,period) row in the assembler txn — never a
                // lock (spike-p2-memo §4). enqueue() is idempotent on the source notification id when present.
                $this->digestQueue->enqueue($recipient, $event, $actor, $payload, $notificationId);
            } elseif ($cadence === DigestPreference::IMMEDIATE) {
                // Immediate (the default): the UNCHANGED live path — queued on the DB queue, drained by cron.
                // A transport failure must not break the request.
                try {
                    Mail::to($recipient->email)->queue(new NotificationMail($event, $actor->username ?? 'Someone', $payload));
                } catch (\Throwable) {
                    // best-effort baseline email (ADR-0014) — swallow; the in-app notification still landed.
                }
            }
            // else $cadence === DigestPreference::OFF: 1-click unsubscribe — no notification mail at all
            // (neither an immediate send nor a digest). The in-app notification above still landed.
        }

        // Web Push (M3.2) — an OPT-IN additional channel. The opt-in IS having ≥1 device subscription; absent a
        // subscription nothing is dispatched (so push degrades silently to in-app/email). Per-event push
        // preference is honoured (default on once subscribed). Delivery is a queued job, cron-drained on
        // baseline — never on the hot path. A guard query (subscriptions exist) keeps the no-push case cheap.
        if ($this->prefers($recipient, $event, 'push')
            && PushSubscription::query()->where('user_id', $recipient->getKey())->exists()) {
            SendPushNotification::dispatch((int) $recipient->getKey(), $event, $actor->username ?? 'Someone', $payload);
        }
    }

    /** Write (or merge into) the in-app notification; returns its id so the digest can dedupe on it. */
    private function writeDatabase(User $recipient, string $event, User $actor, array $payload): string
    {
        $threadId = $payload['thread_id'] ?? null;
        $existing = $threadId !== null ? $this->existingUnread($recipient, $event, $threadId) : null;

        if ($existing !== null) {
            $data = $existing->data;
            $actors = array_values(array_filter(
                $data['actors'] ?? [],
                fn ($a) => (int) ($a['id'] ?? 0) !== (int) $actor->getKey(),
            ));
            array_unshift($actors, ['id' => (int) $actor->getKey(), 'username' => $actor->username]);
            $data['actors'] = array_slice($actors, 0, self::MAX_ACTORS);
            $data['count'] = (int) ($data['count'] ?? 1) + 1;
            $data['post_id'] = $payload['post_id'] ?? ($data['post_id'] ?? null);
            $data['url'] = $payload['url'] ?? ($data['url'] ?? null);

            $existing->forceFill(['data' => $data])->touch();

            return (string) $existing->getKey();
        }

        $id = (string) Str::uuid();
        DatabaseNotification::create([
            'id' => $id,
            'type' => $event,
            'notifiable_type' => $recipient->getMorphClass(),
            'notifiable_id' => $recipient->getKey(),
            'data' => [
                'event' => $event,
                'count' => 1,
                'actors' => [['id' => (int) $actor->getKey(), 'username' => $actor->username]],
                'thread_id' => $threadId,
                'topic_title' => $payload['topic_title'] ?? null,
                'post_id' => $payload['post_id'] ?? null,
                'url' => $payload['url'] ?? null,
            ],
            'read_at' => null,
        ]);

        return $id;
    }

    private function existingUnread(User $recipient, string $event, int $threadId): ?DatabaseNotification
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $recipient->getMorphClass())
            ->where('notifiable_id', $recipient->getKey())
            ->where('type', $event)
            ->whereNull('read_at')
            ->latest()
            ->get()
            ->first(fn (DatabaseNotification $n) => (int) ($n->data['thread_id'] ?? 0) === $threadId);
    }

    private function prefers(User $user, string $event, string $channel): bool
    {
        $pref = NotificationPreference::query()
            ->where('user_id', $user->getKey())->where('event_type', $event)->where('channel', $channel)
            ->first();

        return $pref ? (bool) $pref->enabled : true; // default on
    }
}
