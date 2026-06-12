<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\ReputationService;
use App\Events\PostCreated;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Optional fixed reputation award per post created (P2-M5, owner-tunable, DEFAULT 0 = off). The post
 * itself is the UNIQUE ledger source, so the award is idempotent under event replays. shouldQueue()
 * gates at dispatch time: with the default 0 weight no job row is ever written, keeping the post hot
 * path untouched until an owner opts in (config novfora.reputation.awards.post_created).
 */
final class AwardContentCreationReputation implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly ReputationService $reputation) {}

    public function shouldQueue(PostCreated $event): bool
    {
        return (int) config('novfora.reputation.awards.post_created', 0) > 0;
    }

    public function handle(PostCreated $event): void
    {
        $weight = (int) config('novfora.reputation.awards.post_created', 0);
        $authorId = $event->post->user_id !== null ? (int) $event->post->user_id : null;
        if ($weight <= 0 || $authorId === null) {
            return;
        }

        $author = User::find($authorId);
        if ($author instanceof User) {
            $this->reputation->award($author, $event->post, $weight);
        }
    }
}
