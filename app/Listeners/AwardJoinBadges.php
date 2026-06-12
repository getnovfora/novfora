<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Community\BadgeService;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Award join-criteria badges on registration (P2-M5) — the welcome badge. QUEUED off the registration
 * hot path; idempotent via the user_badges UNIQUE, so a replay or a later cron sweep never duplicates.
 */
final class AwardJoinBadges implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $deleteWhenMissingModels = true;

    public function __construct(private readonly BadgeService $badges) {}

    public function handle(Registered $event): void
    {
        $user = $event->user instanceof User ? User::find($event->user->getAuthIdentifier()) : null;

        if ($user instanceof User) {
            $this->badges->evaluate($user, BadgeService::TRIGGER_JOIN);
        }
    }
}
