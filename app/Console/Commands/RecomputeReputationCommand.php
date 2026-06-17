<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Community\ReputationService;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * The reputation self-heal cron (P2-M5 ⚙): overwrite every user's denormalised reputation_points with the
 * authoritative SUM from the reputation_events ledger. Idempotent — re-running over an already-reconciled
 * board changes nothing — and bounded (chunked iteration; each chunk is one batched SUM + per-user writes),
 * so it is safe under withoutOverlapping on a coarse/overlapping cron tick (ADR-0011).
 *
 * NAMING (ADR-0028 → P5.5/ADR-0073): the `novfora:` command prefix — the 1.0 brand rename completed the
 * Phase-5 rename surface #8.
 */
class RecomputeReputationCommand extends Command
{
    protected $signature = 'novfora:reputation:recompute {--user= : Recompute only this user id} {--chunk=500 : Users per batch}';

    protected $description = 'Recompute users.reputation_points from the reputation_events ledger (idempotent self-heal).';

    public function handle(ReputationService $reputation): int
    {
        if (($id = $this->option('user')) !== null) {
            $reputation->recomputeFor([(int) $id]);
            $this->info("Reputation recomputed for user {$id}.");

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $count = 0;

        User::query()->orderBy('id')->chunkById($chunk, function ($users) use ($reputation, &$count): void {
            $reputation->recomputeFor($users->pluck('id')->map(fn ($i): int => (int) $i)->all());
            $count += $users->count();
        });

        $this->info("Reputation recomputed for {$count} user(s).");

        return self::SUCCESS;
    }
}
