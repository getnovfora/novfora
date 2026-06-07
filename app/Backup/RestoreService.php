<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Backup;

use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

/**
 * Restores a Hearth backup archive produced by {@see BackupService} (M5). The inverse operation, and a
 * DESTRUCTIVE one — it overwrites the live database and storage — so the CLI gates it behind --force /
 * an interactive confirm. Before touching anything it validates the manifest format and verifies the
 * dump's SHA-256 against the manifest, refusing a corrupted or foreign archive.
 *
 * This is the documented upgrade safety net: take a backup, attempt the upgrade, and if it goes wrong,
 * restore to exactly the prior state (proven by the backup→restore round-trip test).
 *
 * (Not `final` — like {@see RestoreRunner} and {@see \App\Upgrade\UpgradeRunner} — so it can be swapped for a
 * test double when a caller's failure handling is unit-tested in isolation, e.g. forcing a restore-stage
 * failure to prove the maintenance gate stays up.)
 */
class RestoreService
{
    /** @return array{db_driver:string, storage_restored:bool} */
    public function restore(string $archivePath): array
    {
        if (! is_file($archivePath)) {
            throw new BackupException('Backup archive not found: '.$archivePath);
        }

        $work = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hearth-restore-'.bin2hex(random_bytes(6));
        $this->ensureDir($work);

        try {
            $this->unzip($archivePath, $work);
            $manifest = $this->readManifest($work);
            $this->verifyIntegrity($work, $manifest);

            $this->restoreDatabase($work, $manifest['db']);

            $storageRestored = false;
            if (($manifest['storage_included'] ?? false) && is_dir($work.DIRECTORY_SEPARATOR.'storage')) {
                $this->restoreStorage($work);
                $storageRestored = true;
            }

            return ['db_driver' => (string) $manifest['db']['driver'], 'storage_restored' => $storageRestored];
        } finally {
            $this->rmrf($work);
        }
    }

    /**
     * Read a backup's manifest metadata WITHOUT extracting it (RH-11) — cheap, so it backs both the CLI
     * restore confirmation (date, size, database kind) and the panel's request pre-check (the no-SSH path
     * inspects + checks engine compatibility in-request, then defers the heavier dump-hash verification to
     * the cron run). Refuses an archive that is not a recognised Hearth backup. Does NOT verify the dump hash
     * — {@see validate()} does that, with {@see assertRestorable()}, before a restore touches the database.
     *
     * @return array{created_at:?string, version:?string, db_kind:string, db_driver:string, storage_included:bool, size_bytes:int}
     */
    public function inspect(string $archivePath): array
    {
        if (! is_file($archivePath)) {
            throw new BackupException('Backup archive not found: '.$archivePath);
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('Could not open the backup archive.');
        }

        try {
            $manifest = $this->manifestFromZip($zip);
        } finally {
            $zip->close();
        }

        return [
            'created_at' => isset($manifest['created_at']) && is_string($manifest['created_at']) ? $manifest['created_at'] : null,
            'version' => isset($manifest['version']) && is_string($manifest['version']) ? $manifest['version'] : null,
            'db_kind' => (string) $manifest['db']['kind'],
            'db_driver' => (string) $manifest['db']['driver'],
            'storage_included' => (bool) ($manifest['storage_included'] ?? false),
            'size_bytes' => (int) (@filesize($archivePath) ?: 0),
        ];
    }

    /**
     * Fully validate an archive before anything is touched (RH-11, the "refuse before touching" guard):
     * the manifest is well-formed AND the database dump's SHA-256 matches the manifest. Streams the dump
     * straight out of the zip (no disk extraction, chunked hashing) so it stays baseline-memory-safe for
     * large archives. Throws {@see BackupException} on any failure. @return array<string,mixed> the manifest.
     */
    public function validate(string $archivePath): array
    {
        if (! is_file($archivePath)) {
            throw new BackupException('Backup archive not found: '.$archivePath);
        }

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new BackupException('Could not open the backup archive.');
        }

        try {
            $manifest = $this->manifestFromZip($zip);

            // Refuse a cross-engine archive up front — before the (heavier) hash and before any restore — so a
            // mismatched backup never enters the maintenance window (RH-11).
            $this->assertRestorable((string) $manifest['db']['driver']);

            $dumpName = (string) $manifest['db']['file'];
            $stream = $zip->getStream($dumpName);
            if ($stream === false) {
                throw new BackupException('The backup is missing its database dump.');
            }

            $ctx = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, 1 << 20); // 1 MiB
                if ($chunk === false) {
                    break;
                }
                hash_update($ctx, $chunk);
            }
            fclose($stream);

            if (hash_final($ctx) !== $manifest['db']['sha256']) {
                throw new BackupException('Backup integrity check failed — the database dump is corrupted.');
            }
        } finally {
            $zip->close();
        }

        return $manifest;
    }

    /**
     * Assert a backup made for `$manifestDriver` can be restored into the site's configured database engine
     * (RH-11). Cross-engine restores (SQLite ↔ SQL, MySQL ↔ PostgreSQL) cannot work, so refuse them at the
     * "before touching anything" stage rather than discovering it mid-restore. MySQL and MariaDB are
     * interchangeable. Throws {@see BackupException} on a mismatch.
     */
    public function assertRestorable(string $manifestDriver): void
    {
        $conn = (string) config('database.default');
        $configured = (string) config("database.connections.{$conn}.driver", $conn);

        if ($this->engineFamily($manifestDriver) !== $this->engineFamily($configured)) {
            throw new BackupException(
                "This backup was made for a [{$manifestDriver}] database and cannot be restored into the configured [{$configured}] database."
            );
        }
    }

    private function engineFamily(string $driver): string
    {
        return match (true) {
            $driver === 'sqlite' => 'sqlite',
            in_array($driver, ['mysql', 'mariadb'], true) => 'mysql',
            $driver === 'pgsql' => 'pgsql',
            default => 'other:'.$driver,
        };
    }

    /**
     * Read + format-check manifest.json directly from an open zip (no extraction). Shared by inspect()
     * and validate(). @return array<string,mixed>
     */
    private function manifestFromZip(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('manifest.json');
        if ($raw === false) {
            throw new BackupException('This archive has no manifest — it is not a Hearth backup.');
        }

        $manifest = json_decode($raw, true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::FORMAT) {
            throw new BackupException('Unsupported or unrecognised backup format.');
        }
        if (! isset($manifest['db']['file'], $manifest['db']['kind'], $manifest['db']['sha256'], $manifest['db']['driver'])) {
            throw new BackupException('The backup manifest is incomplete.');
        }

        return $manifest;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $work): array
    {
        $path = $work.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($path)) {
            throw new BackupException('This archive has no manifest — it is not a Hearth backup.');
        }

        $manifest = json_decode((string) file_get_contents($path), true);
        if (! is_array($manifest) || ($manifest['format'] ?? null) !== BackupService::FORMAT) {
            throw new BackupException('Unsupported or unrecognised backup format.');
        }
        if (! isset($manifest['db']['file'], $manifest['db']['kind'], $manifest['db']['sha256'])) {
            throw new BackupException('The backup manifest is incomplete.');
        }

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    private function verifyIntegrity(string $work, array $manifest): void
    {
        $dump = $work.DIRECTORY_SEPARATOR.$manifest['db']['file'];
        if (! is_file($dump)) {
            throw new BackupException('The backup is missing its database dump.');
        }

        if (hash_file('sha256', $dump) !== $manifest['db']['sha256']) {
            throw new BackupException('Backup integrity check failed — the database dump is corrupted.');
        }
    }

    /** @param array<string,mixed> $db */
    private function restoreDatabase(string $work, array $db): void
    {
        $conn = (string) config('database.default');
        $c = (array) config("database.connections.{$conn}");
        $driver = (string) ($c['driver'] ?? $conn);
        $dump = $work.DIRECTORY_SEPARATOR.$db['file'];

        if ($db['kind'] === 'sqlite') {
            if ($driver !== 'sqlite') {
                throw new BackupException("This backup is SQLite but the site is configured for [{$driver}].");
            }
            $target = (string) ($c['database'] ?? '');
            if ($target === '' || $target === ':memory:') {
                throw new BackupException('The configured SQLite database path is not a file.');
            }
            $this->ensureDir(dirname($target));
            if (! @copy($dump, $target)) {
                throw new BackupException('Could not write the restored SQLite database.');
            }
            DB::purge($conn); // reconnect so subsequent queries read the restored file

            return;
        }

        // SQL dump → pipe into the client. Password via env, dump via stdin (handles large dumps).
        if ($driver === 'mysql' || $driver === 'mariadb') {
            // Baseline hardening (phase-1.5): when proc_open/the mysql binary aren't usable (common on
            // shared hosts), apply the dump in-process over PDO instead of shelling out. Mirrors
            // BackupService's pure-PHP path; the .sql produced by either dumper restores the same way.
            if (BackupService::processFunctionsAvailable() && (string) config('hearth.backup.db_method', 'auto') !== 'php') {
                try {
                    $this->runRestore(
                        ['mysql', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
                            '-u', (string) ($c['username'] ?? 'root'), (string) ($c['database'] ?? '')],
                        ['MYSQL_PWD' => (string) ($c['password'] ?? '')],
                        $dump,
                        'mysql',
                    );
                } catch (BackupException) {
                    $this->phpRestoreSql($dump, $conn); // mysql client missing/unrunnable → in-process apply
                }
            } else {
                $this->phpRestoreSql($dump, $conn);
            }
        } elseif ($driver === 'pgsql') {
            $this->runRestore(
                ['psql', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-p', (string) ($c['port'] ?? 5432),
                    '-U', (string) ($c['username'] ?? 'postgres'), '-d', (string) ($c['database'] ?? '')],
                ['PGPASSWORD' => (string) ($c['password'] ?? '')],
                $dump,
                'psql',
            );
        } else {
            throw new BackupException("Cannot restore a SQL dump into driver [{$driver}].");
        }

        DB::purge($conn);
    }

    private function runRestore(array $command, array $env, string $dumpFile, string $tool): void
    {
        $input = fopen($dumpFile, 'r');
        if ($input === false) {
            throw new BackupException('Could not read the database dump.');
        }

        try {
            $process = new Process($command, null, $env, null, 600);
            $process->setInput($input);
            $process->run();
        } catch (Throwable) {
            throw new BackupException("Could not run {$tool} (is it installed and on PATH?).");
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }

        if (! $process->isSuccessful()) {
            throw new BackupException(trim($tool.' restore failed: '.$process->getErrorOutput()) ?: "{$tool} restore failed.");
        }
    }

    /**
     * Apply a .sql dump in-process via PDO (no proc_open, no mysql client) — the baseline-safe restore that
     * pairs with BackupService::phpDumpMysql. pdo_mysql executes the multi-statement dump in one exec();
     * our dumps contain only DDL/DML (no result sets), so there is nothing to consume between statements.
     * Loads the dump into memory, so it suits the small/medium databases a cheap-host forum runs.
     */
    private function phpRestoreSql(string $dumpFile, string $conn): void
    {
        $sql = (string) file_get_contents($dumpFile);
        if (trim($sql) === '') {
            throw new BackupException('The database dump is empty.');
        }

        try {
            DB::connection($conn)->getPdo()->exec($sql);
        } catch (Throwable $e) {
            throw new BackupException('Pure-PHP database restore failed: '.$e->getMessage());
        }
    }

    private function restoreStorage(string $work): void
    {
        $source = $work.DIRECTORY_SEPARATOR.'storage';
        $base = storage_path('app');
        $this->ensureDir($base);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($files as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $dest = $base.DIRECTORY_SEPARATOR.$relative;
            if ($file->isDir()) {
                $this->ensureDir($dest);
            } elseif ($file->isFile()) {
                $this->ensureDir(dirname($dest));
                @copy($file->getPathname(), $dest);
            }
        }
    }

    private function unzip(string $archive, string $work): void
    {
        $zip = new ZipArchive;
        if ($zip->open($archive) !== true) {
            throw new BackupException('Could not open the backup archive.');
        }
        if (! $zip->extractTo($work)) {
            $zip->close();
            throw new BackupException('Could not extract the backup archive.');
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
