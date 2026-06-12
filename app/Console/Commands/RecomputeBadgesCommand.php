<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Community\BadgeService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The badge catch-up sweep (P2-M5 ⚙): re-evaluate every active badge for every user, awarding anything
 * a missed/lost event dropped. Idempotent — awards are insertOrIgnore on UNIQUE(user_id,badge_id) and
 * PERMANENT (the sweep only ever adds) — and bounded (chunked iteration), so it is safe under
 * withoutOverlapping on a coarse/overlapping cron tick (ADR-0011).
 *
 * NAMING (deliberate, ADR-0028): the `nevo:` prefix joins the Phase-5 rename surface (#8) — do NOT
 * pre-rename; the sweep renames it with the rest of that surface.
 */
class RecomputeBadgesCommand extends Command
{
    protected $signature = 'nevo:badges:recompute {--user= : Re-evaluate only this user id} {--chunk=500 : Users per batch}';

    protected $description = 'Re-evaluate badge criteria for all users, awarding anything a missed event dropped.';

    public function handle(BadgeService $badges): int
    {
        if (($id = $this->option('user')) !== null) {
            $user = User::find((int) $id);
            $awarded = $user instanceof User ? $badges->evaluate($user) : 0;
            $this->info("Badges re-evaluated for user {$id}; {$awarded} new award(s).");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $awarded = 0;

        User::query()->orderBy('id')->chunkById($chunk, function ($users) use ($badges, &$awarded): void {
            foreach ($users as $user) {
                $awarded += $badges->evaluate($user);
            }
        });

        $this->info("Badge sweep complete; {$awarded} new award(s).");

        return self::SUCCESS;
    }
}
