<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Permissions\PermissionSync;
use App\Permissions\PermissionSyncReport;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:permissions:sync` — re-provision the built-in role presets onto existing roles and
 * system groups so permissions ADDED to a preset since install reach an already-installed site (Wave 0.1,
 * ADR-0036). Additive + idempotent. Wired into the no-SSH upgrade pipeline; also run by hand after a
 * manual upgrade to clear a "403 on a new admin screen" (e.g. the Badges panel). Use --dry-run to preview.
 */
class PermissionSyncCommand extends Command
{
    protected $signature = 'novfora:permissions:sync {--dry-run : Show what would change without writing}';

    protected $description = 'Re-provision role presets onto existing roles & groups (additive, idempotent).';

    public function handle(PermissionSync $sync): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $dry ? $sync->preview() : $sync->sync();

        if ($report->isNoop()) {
            $this->components->info($dry
                ? 'Already in sync — a real run would change nothing.'
                : 'Permissions already in sync — nothing to do.');

            return self::SUCCESS;
        }

        $this->detail($report, $dry ? 'Would add' : 'Added');

        $this->newLine();
        $this->components->info($dry
            ? sprintf('[dry-run] %d change(s) pending — re-run without --dry-run to apply.', $report->totalChanges())
            : sprintf('Permissions synced — %d change(s) applied; cached verdicts invalidated.', $report->totalChanges()));

        return self::SUCCESS;
    }

    private function detail(PermissionSyncReport $report, string $verb): void
    {
        if ($report->catalogAdded !== []) {
            $this->components->twoColumnDetail("<fg=gray>{$verb} catalog key(s)</>", implode(', ', $report->catalogAdded));
        }

        foreach ($report->permissionsAdded as $role => $keys) {
            $this->components->twoColumnDetail("<fg=gray>{$verb} to role</> {$role}", implode(', ', $keys));
        }

        foreach ($report->entriesWritten as $group => $keys) {
            $this->components->twoColumnDetail("<fg=gray>{$verb} ACL entries to</> {$group}", implode(', ', $keys));
        }
    }
}
