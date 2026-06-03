<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Backup\BackupException;
use App\Backup\BackupService;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:backup` — create a backup: a single portable `.zip` with the database dump, a
 * mirror of storage/app, and an integrity manifest (M5; completes the M0 skeleton). Baseline-friendly:
 * runs from cron (the single schedule:run line) and from the admin UI. Restore is `hearth:restore`.
 */
class HearthBackupCommand extends Command
{
    protected $signature = 'hearth:backup
        {--path= : Output directory (default: config hearth.backup.path)}
        {--keep= : Retain the N newest archives (default: config hearth.backup.keep)}
        {--dry-run : Show the plan without writing}';

    protected $description = 'Create a backup archive (database + storage + manifest); prune to the retention count.';

    public function handle(BackupService $backups): int
    {
        if ($path = $this->option('path')) {
            config(['hearth.backup.path' => $path]);
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver", $connection);

        $this->components->info('Backup target: '.$backups->destination());
        $this->line("  • database: connection [{$connection}] driver [{$driver}]");
        $this->line('  • storage:  '.storage_path('app'));

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run — nothing written.');
            $this->restoreHint();

            return self::SUCCESS;
        }

        try {
            $keep = $this->option('keep') !== null ? (int) $this->option('keep') : null;
            $result = $backups->create($keep);
        } catch (BackupException $e) {
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Backup written: {$result->path} (".$this->humanSize($result->sizeBytes).')');
        $this->restoreHint();

        return self::SUCCESS;
    }

    private function restoreHint(): void
    {
        $this->newLine();
        $this->components->bulletList([
            'Restore with: php artisan hearth:restore /path/to/hearth-YYYYmmdd-HHMMSS.zip',
            'Reversible migrations mean upgrades never need manual DB surgery — see docs/getting-started.md.',
        ]);
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
