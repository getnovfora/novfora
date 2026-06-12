<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\BadgeService;
use App\Events\PostCreated;
use App\Events\TopicCreated;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-check post-count badge criteria when content lands (P2-M5). PostCreated fires for replies only;
 * a new topic's opening post arrives via TopicCreated — both feed the same live COUNT, so both handle*
 * methods (auto-discovered) funnel into one evaluation. QUEUED off the posting hot path; idempotent via
 * the user_badges UNIQUE — a replayed event simply re-derives the same truth.
 */
final class AwardPostCountBadges implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly BadgeService $badges) {}

    public function handle(PostCreated $event): void
    {
        $this->evaluateFor($event->post->user_id);
    }

    public function handleTopicCreated(TopicCreated $event): void
    {
        $this->evaluateFor($event->topic->user_id);
    }

    private function evaluateFor(mixed $authorId): void
    {
        if ($authorId === null) {
            return;
        }

        $author = User::find((int) $authorId);
        if ($author instanceof User) {
            $this->badges->evaluate($author, BadgeService::TRIGGER_POST_COUNT);
        }
    }
}
