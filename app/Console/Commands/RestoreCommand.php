<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupException;
use App\Backup\RestoreRunner;
use App\Backup\RestoreService;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:restore <archive>` — restore a NovFora backup (M5; RH-11 routes it through the shared
 * pipeline). DESTRUCTIVE: it overwrites the live database and storage, so it confirms first (skip with
 * --force). The archive's manifest + dump SHA-256 are validated before anything is touched; a corrupt or
 * foreign archive is refused. It runs the SAME backup-first, maintenance-safe choreography the no-SSH panel
 * restore uses ({@see RestoreRunner}): take a pre-restore safety snapshot → enter the maintenance window →
 * restore → refresh the schema state → exit → audit-log — so CLI and panel share one path.
 */
class RestoreCommand extends Command
{
    protected $signature = 'novfora:restore {archive : Path to a novfora-*.zip backup archive} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the database and storage from a NovFora backup archive (destructive).';

    public function handle(RestoreRunner $runner, RestoreService $restore): int
    {
        $archive = (string) $this->argument('archive');

        if (! is_file($archive)) {
            $this->components->error("Backup archive not found: {$archive}");

            return self::FAILURE;
        }

        // Surface what the operator is about to overwrite WITH (date/size) before they confirm.
        try {
            $info = $restore->inspect($archive);
            $this->components->info('Backup created '.($info['created_at'] ?? 'unknown').
                ' · '.$this->humanSize($info['size_bytes']).' · database: '.$info['db_kind'].
                ($info['storage_included'] ? ' + storage' : '').'.');
        } catch (BackupException $e) {
            $this->components->error('Not a valid NovFora backup: '.$e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("This will OVERWRITE the current database and storage from {$archive}. Continue?")) {
            $this->components->warn('Restore cancelled.');

            return self::SUCCESS;
        }

        $this->components->info('Restoring (a pre-restore safety snapshot is taken first)…');
        $result = $runner->runNow($archive);

        if ($result->isSuccess()) {
            $this->components->info('✓ Restore complete (database driver: '.(string) $result->dbDriver.
                ', '.$result->durationMs.' ms).');
            if ($result->safetyBackup !== null) {
                $this->line('  • pre-restore safety snapshot: '.$result->safetyBackup);
            }
            $this->line('  Run `php artisan optimize:clear` if anything looks stale.');

            return self::SUCCESS;
        }

        if ($result->isSkipped()) {
            $this->components->warn('Restore skipped ('.$result->reason.').');

            return self::SUCCESS; // already running elsewhere (locked) / not installed — not a failure
        }

        $this->components->error("Restore FAILED during the {$result->stage} step: ".(string) $result->error);
        if ($result->stage === 'restore') {
            $this->newLine();
            $this->components->bulletList([
                'The site stays in maintenance until you recover — it does not retry indefinitely.',
                $result->safetyBackup !== null
                    ? 'Roll back to the previous state: php artisan novfora:restore '.$result->safetyBackup
                    : 'No pre-restore safety snapshot was taken — restore another known-good backup.',
            ]);
        }

        return self::FAILURE;
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        $n = (float) $bytes;
        while ($n >= 1024 && $i < count($units) - 1) {
            $n /= 1024;
            $i++;
        }

        return round($n, 1).' '.$units[$i];
    }
}
