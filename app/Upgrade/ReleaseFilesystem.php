<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade;

use RuntimeException;

/** Atomic file replacement and recovery, deliberately independent of Composer/Laravel. */
class ReleaseFilesystem
{
    public function __construct(private readonly bool $publicOnly = false) {}

    /** Runtime data and host routing are never release-owned. */
    public function isManaged(string $path): bool
    {
        if ($path === '' || str_contains($path, '\\') || str_starts_with($path, '/')
            || preg_match('~(^|/)\.{1,2}(/|$)|[\x00-\x1f:]~', $path)) {
            return false;
        }
        if ($this->publicOnly) {
            return ! preg_match('~(^|/)(storage|\.[^/]*)(/|$)~', $path)
                && (bool) preg_match('~\.(css|js|mjs|json|map|png|jpg|jpeg|gif|webp|svg|ico|woff2?|ttf|otf|txt|webmanifest)$~i', $path);
        }
        if (in_array($path, ['artisan', 'composer.json', 'composer.lock', '.env.example', 'LICENSE', 'README.md', 'release-manifest.json'], true)) {
            return true;
        }
        if (preg_match('~^(app|config|lang|resources|routes|vendor)/~', $path)) {
            return true;
        }
        if (preg_match('~^database/(migrations|factories|seeders)/~', $path)) {
            return true;
        }
        if (in_array($path, ['bootstrap/app.php', 'bootstrap/providers.php', 'bootstrap/release-upgrade.php', 'bootstrap/release-status.php', 'bootstrap/cache/packages.php'], true)) {
            return true;
        }
        if (str_starts_with($path, 'public/') && ! preg_match('~^public/(storage(/|$)|\.htaccess$|hot$)~', $path)) {
            return true;
        }

        return (bool) preg_match('~^(modules/novfora/(hello|kudos|qa)|themes/(aurora|nebula))/~', $path);
    }

    /** Refuse symlinks anywhere below an explicitly selected root. */
    public function path(string $root, string $relative): string
    {
        if ($relative === '' || str_contains($relative, '\\') || str_starts_with($relative, '/')
            || preg_match('~(^|/)\.{1,2}(/|$)|[\x00-\x1f:]~', $relative)) {
            throw new RuntimeException('Unsafe release path.');
        }
        $realRoot = realpath($root);
        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new RuntimeException('Release root is unavailable.');
        }
        $path = $realRoot;
        foreach (explode('/', $relative) as $part) {
            $path .= '/'.$part;
            if (is_link($path)) {
                throw new RuntimeException('Release path crosses a symbolic link: '.$relative);
            }
        }

        return $path;
    }

    /**
     * Snapshot the entire write/delete set BEFORE replacing any file. Safe to retry until the caller
     * atomically records the prepared plan. A process killed during later application never rewrites backups.
     *
     * @param  array<string,string>  $files  authenticated relative file => sha256 map
     * @param  list<string>  $obsolete  previously release-owned files to remove
     * @return list<array{path:string,existed:bool,old_hash:?string,new_hash:?string}>
     */
    public function snapshot(string $root, string $stage, string $backup, array $files, array $obsolete = []): array
    {
        $this->mkdir($backup);
        $plan = [];
        foreach (array_unique(array_merge(array_keys($files), $obsolete)) as $relative) {
            if (! $this->isManaged($relative)) {
                continue;
            }
            $target = $this->path($root, $relative);
            $newHash = $files[$relative] ?? null;
            if ($newHash !== null) {
                $source = $this->path($stage, $relative);
                if (! is_file($source) || hash_file('sha256', $source) !== $newHash) {
                    throw new RuntimeException('Staged release integrity failed: '.$relative);
                }
            }
            if (file_exists($target) && ! is_file($target)) {
                throw new RuntimeException('A release file conflicts with a directory: '.$relative);
            }
            $existed = is_file($target);
            $oldHash = $existed ? hash_file('sha256', $target) : null;
            if ($oldHash === false) {
                throw new RuntimeException('Cannot hash existing code: '.$relative);
            }
            if ($existed) {
                $this->copyAtomic($target, $this->path($backup, $relative), $oldHash);
            }
            $plan[] = ['path' => $relative, 'existed' => $existed, 'old_hash' => $oldHash, 'new_hash' => $newHash];
        }

        return $plan;
    }

    /** @param list<array{path:string,existed:bool,old_hash:?string,new_hash:?string}> $plan */
    public function apply(string $root, string $stage, array $plan): void
    {
        foreach ($plan as $entry) {
            $relative = $entry['path'];
            if (! $this->isManaged($relative)) {
                throw new RuntimeException('Unmanaged path in upgrade plan.');
            }
            $target = $this->path($root, $relative);
            if ($entry['new_hash'] === null) {
                if (is_file($target) && ! unlink($target)) {
                    throw new RuntimeException('Cannot remove obsolete release file.');
                }
            } elseif (! is_file($target) || hash_file('sha256', $target) !== $entry['new_hash']) {
                $this->copyAtomic($this->path($stage, $relative), $target, $entry['new_hash']);
            }
        }
    }

    /** @param list<array{path:string,existed:bool,old_hash:?string,new_hash:?string}> $plan */
    public function rollback(string $root, string $backup, array $plan): void
    {
        // Validate ALL recovery bytes first: never begin a partial rollback from a damaged snapshot.
        foreach ($plan as $entry) {
            if (! $this->isManaged($entry['path'])) {
                throw new RuntimeException('Unmanaged path in recovery plan.');
            }
            if ($entry['existed'] && hash_file('sha256', $this->path($backup, $entry['path'])) !== $entry['old_hash']) {
                throw new RuntimeException('Recovery snapshot integrity failed.');
            }
        }
        foreach ($plan as $entry) {
            $target = $this->path($root, $entry['path']);
            if ($entry['existed']) {
                $this->copyAtomic($this->path($backup, $entry['path']), $target, $entry['old_hash']);
            } elseif (is_file($target) && ! unlink($target)) {
                throw new RuntimeException('Cannot remove newly installed release file.');
            }
        }
    }

    public function copyAtomic(string $source, string $target, string $expectedHash): void
    {
        if (! is_file($source) || is_link($source)) {
            throw new RuntimeException('Invalid release source file.');
        }
        $this->mkdir(dirname($target));
        $temp = tempnam(dirname($target), '.novfora-upgrade-');
        if ($temp === false) {
            throw new RuntimeException('Cannot stage replacement file.');
        }
        try {
            if (! copy($source, $temp) || hash_file('sha256', $temp) !== $expectedHash) {
                throw new RuntimeException('Replacement file failed integrity verification.');
            }
            if (! chmod($temp, fileperms($source) & 0777) || ! rename($temp, $target)) {
                throw new RuntimeException('Cannot publish replacement file.');
            }
            if (function_exists('opcache_invalidate')) {
                opcache_invalidate($target, true);
            }
        } finally {
            if (is_file($temp)) {
                unlink($temp);
            }
        }
    }

    private function mkdir(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            throw new RuntimeException('Cannot create release directory.');
        }
    }
}
