<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\ReputationService;
use App\Events\TopicCreated;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Optional fixed reputation award per topic created (P2-M5, owner-tunable, DEFAULT 0 = off). The topic is
 * the UNIQUE ledger source (idempotent under replays); shouldQueue() keeps the create-topic hot path free
 * of even a jobs-row insert until an owner opts in (config novfora.reputation.awards.topic_created).
 */
final class AwardTopicCreationReputation implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly ReputationService $reputation) {}

    public function shouldQueue(TopicCreated $event): bool
    {
        return (int) config('novfora.reputation.awards.topic_created', 0) > 0;
    }

    public function handle(TopicCreated $event): void
    {
        $weight = (int) config('novfora.reputation.awards.topic_created', 0);
        $authorId = $event->topic->user_id !== null ? (int) $event->topic->user_id : null;
        if ($weight <= 0 || $authorId === null) {
            return;
        }

        $author = User::find($authorId);
        if ($author instanceof User) {
            $this->reputation->award($author, $event->topic, $weight);
        }
    }
}
