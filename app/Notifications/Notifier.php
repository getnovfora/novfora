<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Notifications;

use App\Mail\NotificationMail;
use App\Models\EmailSuppression;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * The notification dispatcher (data-model §7). Two channels: `database` (in-app, polled on baseline) and
 * `mail` (queued, cron-drained — ADR-0011). Database notifications are MERGE-AWARE — a new reply/mention
 * folds into the recipient's existing UNREAD same-thread notification ("X and N others …") instead of
 * stacking rows. Per-event × per-channel preferences are honoured (default on); the suppression list and
 * self-notification are skipped. All channels degrade gracefully — a mail failure never breaks the post.
 */
final class Notifier
{
    private const MAX_ACTORS = 5;

    /**
     * @param  array{thread_id?:int, topic_title?:string, post_id?:int, url?:string}  $payload
     */
    public function send(User $recipient, string $event, User $actor, array $payload): void
    {
        if (! $recipient->getKey() || (int) $recipient->getKey() === (int) $actor->getKey()) {
            return; // never notify yourself
        }

        if ($this->prefers($recipient, $event, 'database')) {
            $this->writeDatabase($recipient, $event, $actor, $payload);
        }

        if ($this->prefers($recipient, $event, 'mail') && $recipient->email && ! $this->suppressed($recipient->email)) {
            // Queued (DB queue on baseline, drained by cron). A transport failure must not break the request.
            try {
                Mail::to($recipient->email)->queue(new NotificationMail($event, $actor->username ?? 'Someone', $payload));
            } catch (\Throwable) {
                // best-effort baseline email (ADR-0014) — swallow; the in-app notification still landed.
            }
        }
    }

    private function writeDatabase(User $recipient, string $event, User $actor, array $payload): void
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

            return;
        }

        DatabaseNotification::create([
            'id' => (string) Str::uuid(),
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

    private function suppressed(string $email): bool
    {
        // Case-insensitive: the suppression list is stored lower-cased (App\Deliverability\Suppressor), so a
        // mixed-case recipient (Alice@Example.com) must still match a suppressed alice@example.com. Mirrors
        // the LOWER(email) idiom in SuppressionGate / Suppressor.
        return EmailSuppression::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->exists();
    }
}
