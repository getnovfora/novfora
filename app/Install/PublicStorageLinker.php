<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Makes uploaded public files (avatars, covers, attachment thumbnails) reachable under public/storage.
 *
 * `php artisan storage:link` creates a SYMLINK, but many shared hosts disable symlink() (or forbid it via
 * open_basedir), which silently breaks every avatar/cover. This linker tries a real symlink first and, when
 * that isn't possible, falls back to COPYING storage/app/public into public/storage (an incremental mirror
 * the cron line refreshes — see refresh()). Paths are injectable so it is testable without touching the
 * real public/ tree. Returns the method used: 'symlink' | 'copy' | 'failed'.
 */
final class PublicStorageLinker
{
    public function source(): string
    {
        return (string) config('novfora.storage.public_source', storage_path('app/public'));
    }

    public function link(): string
    {
        return (string) config('novfora.storage.public_link', public_path('storage'));
    }

    /** Establish the link (or mirror) for the default public paths. @return 'symlink'|'copy'|'failed' */
    public function publish(): string
    {
        return $this->linkPaths($this->source(), $this->link());
    }

    /**
     * Idempotently refresh a COPY mirror so newly-uploaded files appear; a no-op where a real symlink is in
     * place (the live view is already current) or where public files aren't copied. Cheap to call each cron
     * tick — only changed/new files are copied.
     */
    public function refresh(): void
    {
        $link = $this->link();
        if (is_link($link) || ! is_dir($link)) {
            return; // symlinked (always live) or nothing published yet
        }
        $this->mirror($this->source(), $link); // incremental
    }

    /** @return 'symlink'|'copy'|'failed' */
    public function linkPaths(string $source, string $link): string
    {
        if (! is_dir($source)) {
            @mkdir($source, 0775, true);
        }

        // An existing real symlink is already live; an existing real directory is a copy mirror to refresh.
        if (is_link($link)) {
            return 'symlink';
        }
        if (is_dir($link)) {
            $this->mirror($source, $link);

            return 'copy';
        }

        if ($this->symlinkUsable()) {
            try {
                if (@symlink($source, $link)) {
                    return 'symlink';
                }
            } catch (Throwable) {
                // open_basedir / host policy — fall through to the copy mirror.
            }
        }

        return $this->mirror($source, $link) ? 'copy' : 'failed';
    }

    /** Whether a real symlink is even worth attempting on this host (function present + enabled + opted-in). */
    public function symlinkUsable(): bool
    {
        if (! (bool) config('novfora.storage.use_symlink', true)) {
            return false;
        }
        if (! \function_exists('symlink')) {
            return false;
        }
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

        return ! in_array('symlink', $disabled, true);
    }

    /** Recursively copy $source → $dest, skipping files already up to date. Returns success. */
    private function mirror(string $source, string $dest): bool
    {
        if (! is_dir($source)) {
            return false;
        }
        if (! is_dir($dest) && ! @mkdir($dest, 0775, true) && ! is_dir($dest)) {
            return false;
        }

        try {
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
            );

            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($source) + 1);
                $target = $dest.DIRECTORY_SEPARATOR.$relative;

                if ($file->isDir()) {
                    if (! is_dir($target)) {
                        @mkdir($target, 0775, true);
                    }
                } elseif ($file->isFile()) {
                    // Incremental: copy only when missing or stale (cheap to re-run from the scheduler).
                    if (! is_file($target) || filemtime($target) < $file->getMTime()) {
                        @copy($file->getPathname(), $target);
                    }
                }
            }
        } catch (Throwable) {
            return false;
        }

        return is_dir($dest);
    }
}
