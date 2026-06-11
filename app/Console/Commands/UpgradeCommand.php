<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Upgrade\SchemaState;
use App\Upgrade\UpgradeRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan novfora:upgrade` — apply pending database migrations behind the same backup-first,
 * maintenance-safe pipeline the cron-driven no-SSH upgrade uses (RH-10 / ADR-0021). This is the manual
 * path for operators who DO have a shell (enhanced tier), and the recovery path when automatic mode is
 * off or held. `--check` reports status without changing anything.
 */
class UpgradeCommand extends Command
{
    protected $signature = 'novfora:upgrade {--check : Report pending-migration + upgrade status without applying}';

    protected $description = 'Apply pending migrations behind a backup-first maintenance window (no-SSH upgrade pipeline).';

    public function handle(SchemaState $schema, UpgradeRunner $runner): int
    {
        try {
            $pending = $schema->pendingMigrationNames();
        } catch (Throwable $e) {
            $this->components->error('Could not read migration status: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('check')) {
            return $this->report($schema, $pending);
        }

        if ($pending === []) {
            $this->components->info('No pending migrations — the schema is up to date.');

            return self::SUCCESS;
        }

        $this->components->info(count($pending).' pending migration(s). Taking a pre-upgrade backup, then migrating…');

        $result = $runner->runManual();

        if ($result->isSuccess()) {
            $this->components->info(
                "Upgrade complete: applied {$result->migrationsApplied} migration(s) in {$result->durationMs} ms."
            );
            if ($result->backup !== null) {
                $this->line('  • pre-upgrade backup: '.$result->backup);
            }

            return self::SUCCESS;
        }

        if ($result->isSkipped()) {
            $this->components->warn('Upgrade skipped ('.$result->reason.').');

            return self::SUCCESS; // a no-op (already applied / locked by a concurrent run) is not a failure
        }

        // Failed.
        $this->components->error("Upgrade FAILED during the {$result->stage} step: ".(string) $result->error);
        $this->newLine();
        $this->components->bulletList([
            'The site stays in maintenance (schema.stuck) until you recover — it will not retry automatically.',
            $result->backup !== null
                ? 'Restore the pre-upgrade snapshot: php artisan novfora:restore '.$result->backup
                : 'No pre-upgrade backup was taken (backup step failed) — fix the backup target, then re-run.',
            'Or re-upload the previous release zip — the code then matches the schema and the gate self-clears within a cron tick.',
        ]);

        return self::FAILURE;
    }

    /** @param  list<string>  $pending */
    private function report(SchemaState $schema, array $pending): int
    {
        $this->components->twoColumnDetail('Pending migrations', (string) count($pending));
        $this->components->twoColumnDetail('Automatic upgrade', config('novfora.upgrade.auto', true) ? 'on' : 'off (manual)');
        $this->components->twoColumnDetail('Upgrade in progress', $schema->isUpgrading() ? 'yes' : 'no');
        $this->components->twoColumnDetail('Held for operator (stuck)', $schema->isStuck() ? 'yes' : 'no');

        foreach ($pending as $name) {
            $this->line('  • '.$name);
        }

        if (($last = $schema->lastRun()) !== null) {
            $this->newLine();
            $this->components->twoColumnDetail('Last run', (string) ($last['result'] ?? '—').' @ '.(string) ($last['at'] ?? '—'));
        }

        return self::SUCCESS;
    }
}
