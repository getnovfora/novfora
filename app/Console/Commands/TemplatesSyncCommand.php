<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Theme\Sandbox\TemplateSync;
use Illuminate\Console\Command;

/**
 * U11 (ADR-0112): reconcile stored template overrides against the current shipped defaults — the same sync
 * the upgrade path runs, invocable by hand (e.g. after a manual code deploy outside the RH-10 runner).
 */
class TemplatesSyncCommand extends Command
{
    protected $signature = 'novfora:templates:sync';

    protected $description = 'Three-way-merge admin template overrides against the current shipped defaults (U11)';

    public function handle(TemplateSync $sync): int
    {
        $report = $sync->sync();

        if ($report === []) {
            $this->info('No template overrides to reconcile.');

            return self::SUCCESS;
        }

        foreach ($report as $key => $outcome) {
            $this->line(sprintf('  %-24s %s', $key, $outcome));
        }

        $conflicts = count(array_keys($report, 'conflict', true));
        if ($conflicts > 0) {
            $this->warn("{$conflicts} conflict(s) — the existing override(s) keep rendering; review in Admin → Settings → Templates.");
        } else {
            $this->info('Reconciled.');
        }

        return self::SUCCESS;
    }
}
