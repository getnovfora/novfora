<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Forum;

use App\AntiSpam\ContentRejectedException;
use App\Models\ScheduledPost;
use App\Models\Topic;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Post scheduling (member tool 2.4). A scheduled reply is HELD (not created in the topic) until its time; the
 * publish cron then creates the REAL post through {@see PostService::reply()} so every side-effect (counters,
 * last-post pointers, notifications, search) fires exactly as for a normal reply — no duplicated pipeline.
 *
 * CRON-TOLERANT (baseline). `publishOne()` runs each item in a transaction that LOCKS the row and proceeds only
 * if still unpublished, so overlapping cron ticks (or a `withoutOverlapping` lapse) can never double-publish.
 * A transient failure throws → the transaction rolls back → the claim is released → the next tick retries. A
 * PERMANENT failure (topic gone/locked, lost permission, content rejected) is marked done with a null post_id
 * so it is skipped, never retried.
 */
final class PostScheduler
{
    public function __construct(private readonly PostService $posts) {}

    /** Queue a reply to publish later. @throws \InvalidArgumentException if the time is not in the future. */
    public function scheduleReply(User $user, Topic $topic, string $format, array $canonical, CarbonInterface $publishAt): ScheduledPost
    {
        if ($publishAt->isPast()) {
            throw new \InvalidArgumentException('The publish time must be in the future.');
        }

        return ScheduledPost::create([
            'user_id' => $user->getKey(),
            'topic_id' => $topic->getKey(),
            'body_format' => $format,
            'body_canonical' => $canonical,
            'publish_at' => $publishAt,
        ]);
    }

    /** Cancel a still-pending scheduled reply (a published one can't be undone here). */
    public function cancel(ScheduledPost $scheduled): bool
    {
        if ($scheduled->published_at !== null) {
            return false;
        }
        $scheduled->delete();

        return true;
    }

    /** @return Collection<int,ScheduledPost> the user's still-pending scheduled replies */
    public function pendingFor(User $user)
    {
        return ScheduledPost::query()
            ->where('user_id', $user->getKey())
            ->whereNull('published_at')
            ->with('topic')
            ->orderBy('publish_at')
            ->get();
    }

    /** Publish every scheduled reply now due. Returns how many real posts were created. */
    public function publishDue(int $limit = 200): int
    {
        $ids = ScheduledPost::query()
            ->whereNull('published_at')
            ->where('publish_at', '<=', now())
            ->orderBy('publish_at')
            ->limit(max(1, $limit))
            ->pluck('id');

        $created = 0;
        foreach ($ids as $id) {
            if ($this->publishOne((int) $id)) {
                $created++;
            }
        }

        return $created;
    }

    private function publishOne(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            // Atomic claim: lock the row and proceed only if still unpublished.
            $scheduled = ScheduledPost::query()->whereKey($id)->whereNull('published_at')->lockForUpdate()->first();
            if (! $scheduled instanceof ScheduledPost) {
                return false;
            }

            $topic = Topic::find($scheduled->topic_id);
            $user = User::find($scheduled->user_id);

            // No longer publishable → mark done with a null post (skip, never retry). post.create resolves the
            // same at the topic's (thread) scope as at its forum's — the thread inherits the forum's grants.
            if (! $topic instanceof Topic || ! $user instanceof User || $topic->status === 'locked'
                || ! $user->canDo('post.create', $topic->permissionScope())) {
                $scheduled->update(['published_at' => now(), 'post_id' => null]);

                return false;
            }

            try {
                $post = $this->posts->reply($user, $topic, (string) $scheduled->body_format, (array) $scheduled->body_canonical);
            } catch (ContentRejectedException) {
                // Rejected by moderation/anti-spam — retrying won't help; record as skipped.
                $scheduled->update(['published_at' => now(), 'post_id' => null]);

                return false;
            }

            $scheduled->update(['published_at' => now(), 'post_id' => $post->getKey()]);

            return true;
        });
    }
}
