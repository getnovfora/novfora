<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Groups\GroupAutoPromoter;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:groups:auto-promote` — the AND/OR auto-promotion sweep (ACP v3 · v3-e, ADR-0083).
 *
 * The authoritative catch-up for custom-group auto-promotion: it re-evaluates every user against every
 * auto-promotion group, attaching the ones they now qualify for. Promotion-only + idempotent (a re-run
 * converges; an already-member is skipped), so it is safe under `withoutOverlapping` on a coarse/overlapping
 * cron tick (ADR-0011). Scheduled hourly in routes/console.php — the same mechanism as `novfora:trust:recompute`.
 *
 * The criterion-moving EVENTS (post created, reputation award) promote a single user immediately for low
 * latency; the time-based `tenure_days` criterion has no event, so this sweep is what eventually crosses it —
 * and it is the backstop that converges anything a dropped event missed.
 */
class AutoPromoteGroupsCommand extends Command
{
    protected $signature = 'novfora:groups:auto-promote {--user= : Evaluate only this user id} {--chunk=500 : Users per batch}';

    protected $description = 'Evaluate AND/OR auto-promotion criteria and promote users into the custom groups they qualify for.';

    public function handle(GroupAutoPromoter $promoter): int
    {
        if (($id = $this->option('user')) !== null) {
            $user = User::find((int) $id);
            $promoted = $user instanceof User ? $promoter->promote($user) : 0;
            $this->info("Auto-promotion evaluated for user {$id}; {$promoted} new promotion(s).");

            return self::SUCCESS;
        }

        // Nothing auto-promotes on this board → skip the whole sweep (no per-user work).
        if (! $promoter->hasCandidates()) {
            $this->info('No auto-promotion groups configured — nothing to sweep.');

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $promoted = 0;

        User::query()->orderBy('id')->chunkById($chunk, function ($users) use ($promoter, &$promoted): void {
            foreach ($users as $user) {
                $promoted += $promoter->promote($user);
            }
        });

        $this->info("Auto-promotion sweep complete; {$promoted} new promotion(s).");

        return self::SUCCESS;
    }
}
