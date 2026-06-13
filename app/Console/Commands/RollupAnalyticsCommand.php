<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Analytics\AnalyticsService;
use Illuminate\Console\Command;

/**
 * Rolls up daily admin analytics (ADR-0035). Driven by the scheduler once a day, so analytics work on the
 * baseline tier with no worker; idempotent, so re-running (or `--backfill`) just overwrites. Aggregate counts
 * only — no PII.
 */
final class RollupAnalyticsCommand extends Command
{
    protected $signature = 'novfora:analytics:rollup
        {--days=1 : also re-roll this many prior days (finalises yesterday + refreshes today)}
        {--backfill= : roll up this many days back from today (one-off catch-up)}';

    protected $description = 'Roll up daily admin analytics (aggregate counts, no PII).';

    public function handle(AnalyticsService $analytics): int
    {
        $backfill = $this->option('backfill');
        $analytics->rollupRecent($backfill !== null ? (int) $backfill : (int) $this->option('days'));
        $this->info('Analytics rolled up.');

        return self::SUCCESS;
    }
}
