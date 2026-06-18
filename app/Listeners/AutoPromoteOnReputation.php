<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ReputationAwarded;
use App\Groups\GroupAutoPromoter;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Eagerly re-evaluate AND/OR auto-promotion when a real reputation award lands (ACP v3 · v3-e, ADR-0083).
 * QUEUED; the fresh reputation_points read at evaluation time + the promoter's idempotent attach make replays
 * harmless. The hourly cron sweep remains the backstop for any dropped job.
 */
final class AutoPromoteOnReputation implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly GroupAutoPromoter $promoter) {}

    public function handle(ReputationAwarded $event): void
    {
        $user = User::find($event->recipient->getKey());

        if ($user instanceof User) {
            $this->promoter->promote($user);
        }
    }
}
