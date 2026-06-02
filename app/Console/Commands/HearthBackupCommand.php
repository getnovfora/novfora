<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * `php artisan hearth:backup` — a baseline-friendly backup skeleton (M0): a database dump plus an
 * archive of storage/app, written to a local directory. Designed to run on a shared host from cron.
 * Scheduling, cloud targets, and the admin UI are added in M5 (per phase-1-plan §5).
 */
class HearthBackupCommand extends Command
{
    protected $signature = 'hearth:backup {--path= : Output directory (default: storage/backups)} {--dry-run : Show the plan without writing}';

    protected $description = 'Create a backup: database dump + storage archive (skeleton; M5 adds scheduling/cloud).';

    public function handle(): int
    {
        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver");
        $dir = rtrim($this->option('path') ?: storage_path('backups'), '/\\').DIRECTORY_SEPARATOR.'hearth-'.now()->format('Ymd-His');

        $this->components->info("Backup target: {$dir}");
        $this->line("  • database: connection [{$connection}] driver [{$driver}]");
        $this->line('  • storage:  '.storage_path('app'));

        if ($this->option('dry-run')) {
            $this->components->warn('Dry run — nothing written.');
            $this->restoreHint($driver);

            return self::SUCCESS;
        }

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->components->error("Could not create backup directory: {$dir}");

            return self::FAILURE;
        }

        $dbOk = $this->dumpDatabase($driver, $connection, $dir);
        $this->archiveStorage($dir);

        $this->components->info($dbOk
            ? "Backup written to {$dir}"
            : "Storage archived to {$dir} (database dump skipped — see warning above)");
        $this->restoreHint($driver);

        return self::SUCCESS;
    }

    private function dumpDatabase(string $driver, string $connection, string $dir): bool
    {
        $c = (array) config("database.connections.{$connection}");

        try {
            if ($driver === 'mysql' || $driver === 'mariadb') {
                $process = new Process(
                    ['mysqldump', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
                        '-u', (string) ($c['username'] ?? 'root'), '--single-transaction', '--skip-lock-tables',
                        (string) ($c['database'] ?? '')],
                    null,
                    ['MYSQL_PWD' => (string) ($c['password'] ?? '')], // env, not the CLI — keeps the password out of `ps`
                    null,
                    120,
                );
                $out = fopen($dir.DIRECTORY_SEPARATOR.'database.sql', 'w');
                $process->run(function ($type, $buffer) use ($out) {
                    if ($type === Process::OUT) {
                        fwrite($out, $buffer);
                    }
                });
                fclose($out);

                if (! $process->isSuccessful()) {
                    $this->components->warn('mysqldump failed: '.trim($process->getErrorOutput()));

                    return false;
                }

                return true;
            }

            if ($driver === 'sqlite') {
                $db = (string) ($c['database'] ?? '');
                if ($db !== ':memory:' && is_file($db)) {
                    copy($db, $dir.DIRECTORY_SEPARATOR.'database.sqlite');

                    return true;
                }
                $this->components->warn('In-memory/ephemeral SQLite — nothing to copy.');

                return false;
            }

            $this->components->warn("No dumper for driver [{$driver}] yet (added in M5).");

            return false;
        } catch (Throwable $e) {
            $this->components->warn('Database dump error: '.class_basename($e));

            return false;
        }
    }

    private function archiveStorage(string $dir): void
    {
        $base = storage_path('app');
        if (! is_dir($base)) {
            return;
        }

        try {
            $zip = new ZipArchive;
            if ($zip->open($dir.DIRECTORY_SEPARATOR.'storage-app.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                $this->components->warn('Could not create storage archive.');

                return;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($files as $file) {
                if ($file->isFile()) {
                    $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($base) + 1));
                }
            }
            $zip->close();
        } catch (Throwable $e) {
            $this->components->warn('Storage archive error: '.class_basename($e));
        }
    }

    private function restoreHint(string $driver): void
    {
        $this->newLine();
        $this->components->bulletList([
            $driver === 'sqlite'
                ? 'Restore DB: copy database.sqlite back over your configured SQLite file.'
                : 'Restore DB: mysql -h HOST -u USER -p DB < database.sql',
            'Restore files: unzip storage-app.zip into storage/app/.',
            'Reversible migrations mean upgrades never need manual DB surgery (ADR per CONTRIBUTING).',
        ]);
    }
}
