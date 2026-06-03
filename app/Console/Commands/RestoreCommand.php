<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupException;
use App\Backup\RestoreService;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:restore <archive>` — restore a Hearth backup (M5). DESTRUCTIVE: it overwrites the
 * live database and storage, so it confirms first (skip with --force). The archive's manifest + dump
 * SHA-256 are validated before anything is touched; a corrupt or foreign archive is refused.
 */
class RestoreCommand extends Command
{
    protected $signature = 'hearth:restore {archive : Path to a hearth-*.zip backup archive} {--force : Skip the confirmation prompt}';

    protected $description = 'Restore the database and storage from a Hearth backup archive (destructive).';

    public function handle(RestoreService $restore): int
    {
        $archive = (string) $this->argument('archive');

        if (! is_file($archive)) {
            $this->components->error("Backup archive not found: {$archive}");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("This will OVERWRITE the current database and storage from {$archive}. Continue?")) {
            $this->components->warn('Restore cancelled.');

            return self::SUCCESS;
        }

        try {
            $this->components->info('Restoring…');
            $report = $restore->restore($archive);
        } catch (BackupException $e) {
            $this->components->error('Restore failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('✓ Restore complete (database driver: '.$report['db_driver'].
            ($report['storage_restored'] ? ', storage restored' : '').').');
        $this->line('  Run `php artisan optimize:clear` if anything looks stale.');

        return self::SUCCESS;
    }
}
