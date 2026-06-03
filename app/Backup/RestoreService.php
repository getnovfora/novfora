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
 */
final class RestoreService
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
            $this->runRestore(
                ['mysql', '-h', (string) ($c['host'] ?? '127.0.0.1'), '-P', (string) ($c['port'] ?? 3306),
                    '-u', (string) ($c['username'] ?? 'root'), (string) ($c['database'] ?? '')],
                ['MYSQL_PWD' => (string) ($c['password'] ?? '')],
                $dump,
                'mysql',
            );
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
