<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Deliverability\Digest\DigestAssembler;
use Illuminate\Console\Command;

/**
 * Spike P2 — `php artisan novfora:deliverability:digest-run`. One assembler tick: self-heal stuck runs, then
 * claim + enqueue due digests up to the per-tick cap. Driven by the single cron line (everyMinute,
 * withoutOverlapping, short mutex) but correct on a COARSE / overlapping / killed interval — the guarantee
 * is the committed UNIQUE row, not the cadence of the cron. No-op while the pipeline is dormant.
 */
final class DigestRunCommand extends Command
{
    protected $signature = 'novfora:deliverability:digest-run';

    protected $description = 'Assemble and enqueue cron-batched digest emails (idempotent per cadence period).';

    public function handle(DigestAssembler $assembler): int
    {
        if (! config('novfora.deliverability.enabled') || ! config('novfora.deliverability.digest.enabled')) {
            $this->info('Digest pipeline is dormant (novfora.deliverability.digest disabled).');

            return self::SUCCESS;
        }

        $dispatched = $assembler->tick();
        $this->info("Dispatched {$dispatched} digest(s).");

        return self::SUCCESS;
    }
}
