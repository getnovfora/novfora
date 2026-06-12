<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\BadgeService;
use App\Events\ReputationAwarded;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Re-check reputation-threshold badge criteria when a real ledger award lands (P2-M5). QUEUED; the
 * fresh reputation_points read at evaluation time + the user_badges UNIQUE make replays harmless.
 */
final class AwardReputationBadges implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly BadgeService $badges) {}

    public function handle(ReputationAwarded $event): void
    {
        $user = User::find($event->recipient->getKey());

        if ($user instanceof User) {
            $this->badges->evaluate($user, BadgeService::TRIGGER_REPUTATION);
        }
    }
}
