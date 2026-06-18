<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\PostCreated;
use App\Events\TopicCreated;
use App\Groups\GroupAutoPromoter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Eagerly re-evaluate AND/OR auto-promotion for an author when their post count moves (ACP v3 · v3-e,
 * ADR-0083). PostCreated fires for replies, TopicCreated for opening posts — both for APPROVED content only —
 * so both handle* methods (auto-discovered by type-hint) funnel into one evaluation. QUEUED off the posting
 * hot path; idempotent (the promoter skips groups the user is already in), so a replayed event is a no-op.
 * The hourly cron sweep is the backstop, so a dropped job never leaves a user permanently un-promoted.
 */
final class AutoPromoteOnContent implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly GroupAutoPromoter $promoter) {}

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
            $this->promoter->promote($author);
        }
    }
}
