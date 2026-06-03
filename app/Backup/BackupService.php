<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use FilesystemIterator;
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
            $this->runDump(
                ['mysqldump', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
                    '-u', (string) ($c['username'] ?? 'root'), '--single-transaction', '--skip-lock-tables',
                    '--routines', '--no-tablespaces', (string) ($c['database'] ?? '')],
                ['MYSQL_PWD' => (string) ($c['password'] ?? '')],
                $work.DIRECTORY_SEPARATOR.'database.sql',
                'mysqldump',
            );

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
