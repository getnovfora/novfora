<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Creates portable backup archives (M5, phase-1-plan §5; completes the M0 hearth:backup skeleton).
 *
 * A backup is ONE self-describing `.zip` containing the database dump, a mirror of storage/app, and a
 * `manifest.json` carrying the app version, the DB kind/driver, and a SHA-256 of the dump (so restore
 * can verify integrity before touching the live database). Baseline-safe: a plain file written to a
 * local directory, drivable from the single cron line and the admin UI. {@see RestoreService} is the
 * inverse.
 */
final class BackupService
{
    /** Manifest format version — restore refuses archives it doesn't understand. */
    public const FORMAT = 1;

    public function destination(): string
    {
        return rtrim((string) config('hearth.backup.path', storage_path('backups')), '/\\');
    }

    /**
     * Create a backup archive, then prune older ones beyond the retention count.
     *
     * @param  int|null  $keep  retain the N newest archives (default: config hearth.backup.keep)
     */
    public function create(?int $keep = null): BackupResult
    {
        $dir = $this->destination();
        $this->ensureDir($dir);

        $stamp = now()->format('Ymd-His');
        $work = $dir.DIRECTORY_SEPARATOR.'.work-'.$stamp.'-'.bin2hex(random_bytes(3));
        $this->ensureDir($work);

        try {
            $db = $this->dumpDatabase($work);
            $storageIncluded = $this->copyStorage($work);
            $this->writeManifest($work, $db, $storageIncluded);

            $archive = $dir.DIRECTORY_SEPARATOR.'hearth-'.$stamp.'.zip';
            $this->zipDirectory($work, $archive);
        } finally {
            $this->rmrf($work);
        }

        $this->prune($keep ?? (int) config('hearth.backup.keep', 7));

        return new BackupResult($archive, (int) (@filesize($archive) ?: 0), $db['kind']);
    }

    /** @return list<array{name:string, path:string, size:int, created:int}> newest first */
    public function list(): array
    {
        $dir = $this->destination();
        $items = [];
        foreach (glob($dir.DIRECTORY_SEPARATOR.'hearth-*.zip') ?: [] as $path) {
            $items[] = ['name' => basename($path), 'path' => $path, 'size' => (int) (@filesize($path) ?: 0), 'created' => (int) (@filemtime($path) ?: 0)];
        }
        usort($items, fn ($a, $b) => $b['created'] <=> $a['created']);

        return $items;
    }

    public function prune(int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $archives = $this->list();
        foreach (array_slice($archives, $keep) as $old) {
            @unlink($old['path']);
        }
    }

    /**
     * Dump the default connection's database into the work dir.
     *
     * @return array{kind:string, driver:string, file:string}
     */
    private function dumpDatabase(string $work): array
    {
        $conn = (string) config('database.default');
        $c = (array) config("database.connections.{$conn}");
        $driver = (string) ($c['driver'] ?? $conn);

        if ($driver === 'sqlite') {
            $src = (string) ($c['database'] ?? '');
            if ($src === ':memory:' || ! is_file($src)) {
                throw new BackupException('Cannot back up an in-memory or missing SQLite database.');
            }
            if (! @copy($src, $work.DIRECTORY_SEPARATOR.'database.sqlite')) {
                throw new BackupException('Could not copy the SQLite database file.');
            }

            return ['kind' => 'sqlite', 'driver' => 'sqlite', 'file' => 'database.sqlite'];
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $out = $work.DIRECTORY_SEPARATOR.'database.sql';

            // Baseline hardening (phase-1.5): cheap shared hosts frequently disable proc_open/exec, or
            // ship without the mysqldump binary, so shelling out is NOT baseline-safe. When the process
            // tools aren't usable we fall back to a pure-PHP dump over the live PDO connection — same .sql
            // format, fully interoperable with the mysql client on restore. Forced via
            // config('hearth.backup.db_method') = php|shell|auto (default auto).
            if ($this->canShellOut()) {
                try {
                    $this->runDump(
                        ['mysqldump', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
                            '-u', (string) ($c['username'] ?? 'root'), '--single-transaction', '--skip-lock-tables',
                            '--routines', '--no-tablespaces', (string) ($c['database'] ?? '')],
                        ['MYSQL_PWD' => (string) ($c['password'] ?? '')],
                        $out,
                        'mysqldump',
                    );
                } catch (BackupException) {
                    $this->phpDumpMysql($out); // mysqldump missing/unrunnable on this host → pure-PHP path
                }
            } else {
                $this->phpDumpMysql($out);
            }

            return ['kind' => 'sql', 'driver' => $driver, 'file' => 'database.sql'];
        }

        if ($driver === 'pgsql') {
            $this->runDump(
                ['pg_dump', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432),
                    '-U', (string) ($c['username'] ?? 'postgres'), '--no-owner', '--no-privileges',
                    (string) ($c['database'] ?? '')],
                ['PGPASSWORD' => (string) ($c['password'] ?? '')],
                $work.DIRECTORY_SEPARATOR.'database.sql',
                'pg_dump',
            );

            return ['kind' => 'sql', 'driver' => 'pgsql', 'file' => 'database.sql'];
        }

        throw new BackupException("No backup dumper for database driver [{$driver}].");
    }

    /** Stream a dump process's stdout to a file; keep the password out of argv via an env var. */
    private function runDump(array $command, array $env, string $outFile, string $tool): void
    {
        $handle = fopen($outFile, 'w');
        if ($handle === false) {
            throw new BackupException('Could not open the dump output file.');
        }

        try {
            $process = new Process($command, null, $env, null, 600);
            $process->run(function ($type, $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } catch (Throwable $e) {
            fclose($handle);
            throw new BackupException("Could not run {$tool} (is it installed and on PATH?).");
        }
        fclose($handle);

        if (! $process->isSuccessful()) {
            // Surface a trimmed stderr line — driver errors here don't contain the password (it's in env).
            throw new BackupException(trim($tool.' failed: '.$process->getErrorOutput()) ?: "{$tool} failed.");
        }
    }

    /**
     * Whether this host can run external processes (mysqldump/pg_dump). 'auto' (default) defers to whether
     * proc_open is available; 'php' forces the in-process dumper; 'shell' forces the external tools.
     * Shared by {@see RestoreService} via the same config key.
     */
    public function canShellOut(): bool
    {
        return match ((string) config('hearth.backup.db_method', 'auto')) {
            'php' => false,
            'shell' => true,
            default => self::processFunctionsAvailable(),
        };
    }

    /** True only if proc_open exists and is not in php.ini's disable_functions (the shared-host blocker). */
    public static function processFunctionsAvailable(): bool
    {
        if (! \function_exists('proc_open')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('proc_open', $disabled, true);
    }

    /**
     * Pure-PHP mysqldump replacement over the live PDO connection — needs no external binary and no
     * proc_open/exec, so it works on locked-down shared hosts (the baseline tier). Emits a standard .sql
     * (DROP/CREATE/INSERT with FK checks disabled) that the mysql client OR {@see RestoreService}'s
     * in-process restore can both apply. Rows are read buffered and written in batches; suitable for the
     * small/medium databases a cheap-host forum runs (very large boards should use the enhanced tier).
     */
    private function phpDumpMysql(string $outFile): void
    {
        $handle = fopen($outFile, 'w');
        if ($handle === false) {
            throw new BackupException('Could not open the dump output file.');
        }

        try {
            $pdo = DB::connection()->getPdo();
            fwrite($handle, "-- Hearth pure-PHP MySQL backup\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");

            /** @var list<string> $tables */
            $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tables as $table) {
                $q = '`'.str_replace('`', '``', (string) $table).'`';

                $create = $pdo->query("SHOW CREATE TABLE {$q}")->fetch(PDO::FETCH_ASSOC);
                $ddl = $create['Create Table'] ?? null; // views are unused in Hearth's schema
                if (! is_string($ddl)) {
                    continue;
                }
                fwrite($handle, "\nDROP TABLE IF EXISTS {$q};\n{$ddl};\n");

                $stmt = $pdo->query("SELECT * FROM {$q}", PDO::FETCH_ASSOC);
                $columns = null;
                $batch = [];
                foreach ($stmt as $row) {
                    if ($columns === null) {
                        $columns = implode(',', array_map(
                            fn ($col) => '`'.str_replace('`', '``', (string) $col).'`',
                            array_keys($row),
                        ));
                    }
                    $batch[] = '('.implode(',', array_map(
                        fn ($v) => $v === null ? 'NULL' : $pdo->quote((string) $v),
                        array_values($row),
                    )).')';

                    if (count($batch) >= 200) {
                        fwrite($handle, "INSERT INTO {$q} ({$columns}) VALUES ".implode(',', $batch).";\n");
                        $batch = [];
                    }
                }
                if ($batch !== []) {
                    fwrite($handle, "INSERT INTO {$q} ({$columns}) VALUES ".implode(',', $batch).";\n");
                }
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            fclose($handle);
            throw new BackupException('Pure-PHP database dump failed: '.$e->getMessage());
        }

        fclose($handle);
    }

    /** Mirror storage/app into the work dir. Returns whether anything was included. */
    private function copyStorage(string $work): bool
    {
        $base = storage_path('app');
        if (! is_dir($base)) {
            return false;
        }

        $target = $work.DIRECTORY_SEPARATOR.'storage';
        $this->ensureDir($target);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $any = false;
        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            $dest = $target.DIRECTORY_SEPARATOR.$relative;
            if ($file->isDir()) {
                $this->ensureDir($dest);
            } elseif ($file->isFile()) {
                $this->ensureDir(dirname($dest));
                @copy($file->getPathname(), $dest);
                $any = true;
            }
        }

        return $any;
    }

    /** @param array{kind:string, driver:string, file:string} $db */
    private function writeManifest(string $work, array $db, bool $storageIncluded): void
    {
        $manifest = [
            'format' => self::FORMAT,
            'app' => config('app.name', 'Hearth'),
            'version' => config('app.version', '1.0.0-mvp'),
            'created_at' => now()->toIso8601String(),
            'db' => [
                'kind' => $db['kind'],
                'driver' => $db['driver'],
                'file' => $db['file'],
                'sha256' => hash_file('sha256', $work.DIRECTORY_SEPARATOR.$db['file']) ?: '',
            ],
            'storage_included' => $storageIncluded,
        ];

        file_put_contents(
            $work.DIRECTORY_SEPARATOR.'manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
    }

    private function zipDirectory(string $work, string $archive): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException('Could not create the backup archive.');
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($work) + 1));
            if ($file->isDir()) {
                $zip->addEmptyDir($relative);
            } elseif ($file->isFile()) {
                $zip->addFile($file->getPathname(), $relative);
            }
        }

        $zip->close();
    }

    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new BackupException('Could not create directory: '.$dir);
        }
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
