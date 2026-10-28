<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Maintenance\CounterRebuildService;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:forums:recompute-counters` — self-heal the denormalised forum / topic / user counters
 * from the live posts (U16 / NOV-114). Idempotent + chunked (safe under `withoutOverlapping` on a coarse cron
 * tick), mirroring the reputation / badges self-heals. Also dispatched as a queued job from the ACP
 * Maintenance page.
 */
class RecomputeCountersCommand extends Command
{
    protected $signature = 'novfora:forums:recompute-counters {--chunk=500 : Rows per batch}';

    protected $description = 'Recompute forum/topic reply+post counters and users.post_count from live posts (idempotent self-heal).';

    public function handle(CounterRebuildService $service): int
    {
        $r = $service->rebuildAll((int) $this->option('chunk'));
        $this->info("Recomputed {$r['forums']} forum(s), {$r['topics']} topic(s), {$r['users']} user post-count(s).");

        return self::SUCCESS;
    }
}
