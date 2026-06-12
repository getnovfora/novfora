<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\ReputationService;
use App\Events\Reacted;
use App\Models\Reaction;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Award the post author reputation for a received reaction (P2-M5 ⚙ — the amendment-#4 light-up: the
 * per-type config score weights stop being inert here). QUEUED, so the react action's hot path carries
 * only the jobs-row insert (the ≤15 budget holds — mirrors SendReactionNotification); drained by cron.
 *
 * Correctness under replays/races: the listener re-resolves the viewer's CURRENT reaction row (single-
 * choice — at most one per (post,user)) and syncs the ledger to its live type. A double-fired event finds
 * the award already aligned (no-op); a type change re-points the UNIQUE source slot (revoke→award); an
 * unreact before the queue drained leaves no row (skip — ReactionRemoved's revoke handles the ledger).
 * SELF-REACTION awards nothing, and a pseudonymised (deleted) author has no recipient to award.
 */
final class AwardReactionReputation implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly ReputationService $reputation) {}

    public function handle(Reacted $event): void
    {
        $post = $event->post;
        $authorId = $post->user_id !== null ? (int) $post->user_id : null;

        if ($authorId === null || $authorId === (int) $event->actor->getKey()) {
            return; // deleted author, or self-reaction — no rep to self, ever
        }

        $reaction = Reaction::where('post_id', $post->getKey())
            ->where('user_id', $event->actor->getKey())
            ->first();
        if (! $reaction instanceof Reaction) {
            return; // unreacted before the queue drained — nothing to award
        }

        $author = User::find($authorId);
        if (! $author instanceof User) {
            return;
        }

        // The live row's type (not the event's) — stale events for a since-changed reaction converge here.
        $weight = (int) config('novfora.reactions.types.'.$reaction->type.'.score', 0);

        $this->reputation->syncSourceAward($author, $reaction, $weight);
    }
}
