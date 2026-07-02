<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules\Packaging;

use ZipArchive;

/**
 * Hostile-zip hardening (U17, ADR-0104, apex). Safely extracts an UNTRUSTED module archive into a staging
 * directory. It deliberately NEVER calls ZipArchive::extractTo — every entry is validated and then written by
 * a bounded, streamed copy to a path proven to sit inside the staging root. Consequences of that choice:
 *
 * - Zip-slip / traversal: entry names carrying `..`, a leading slash/backslash, a drive letter, a null byte,
 *   or a backslash are rejected; the resolved target is re-checked to be under the staging root before write.
 * - Symlink escape: we only ever write REGULAR files (file_put_contents) — a symlink entry can never be
 *   created, so there is nothing for a later entry to traverse through. (extractTo is what makes that unsafe.)
 * - Zip bomb: the per-entry compression-ratio cap is a pre-flight header check; the per-file and total
 *   UNCOMPRESSED byte caps are the load-bearing fence — read from the central directory first, then
 *   RE-ENFORCED against the ACTUAL bytes streamed out (per 256 KiB chunk), so a lying/zero comp_size header
 *   cannot slip a bomb past the pre-check (extraction aborts within one chunk of the cap).
 * - Extension allowlist: only the file types a module legitimately ships are written; anything else rejects.
 *
 * All limits are config-driven (`novfora.modules.zip.*`). Every violation throws PackageException.
 */
final class ArchiveGuard
{
    /** @var list<string> extensions a module package may contain (lowercased, no dot) */
    private const DEFAULT_EXTENSIONS = [
        'php', 'json', 'md', 'txt', 'css', 'js', 'svg', 'png', 'jpg', 'jpeg', 'gif',
        'webp', 'yml', 'yaml', 'xml', 'ico', 'woff', 'woff2', 'ttf', 'lang', 'html',
    ];

    private const READ_CHUNK = 262144; // 256 KiB

    public function __construct(
        private readonly int $maxEntries = 2000,
        private readonly int $maxFileBytes = 33_554_432,      // 32 MiB per file (uncompressed)
        private readonly int $maxTotalBytes = 134_217_728,    // 128 MiB total (uncompressed)
        private readonly int $maxCompressionRatio = 200,      // uncompressed/compressed per entry
        private readonly int $maxNameLength = 255,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            maxEntries: (int) config('novfora.modules.zip.max_entries', 2000),
            maxFileBytes: (int) config('novfora.modules.zip.max_file_bytes', 33_554_432),
            maxTotalBytes: (int) config('novfora.modules.zip.max_total_bytes', 134_217_728),
            maxCompressionRatio: (int) config('novfora.modules.zip.max_compression_ratio', 200),
        );
    }

    /**
     * Validate and extract an untrusted archive into $stagingDir (which must already exist and be empty).
     * Returns the list of relative file paths written (dirs excluded), for the caller's canonical hashing.
     *
     * @return list<string>
     *
     * @throws PackageException
     */
    public function extract(string $archivePath, string $stagingDir): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new PackageException(__('The uploaded file is not a readable zip archive.'));
        }

        try {
            return $this->doExtract($zip, $stagingDir);
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<string>
     *
     * @throws PackageException
     */
    private function doExtract(ZipArchive $zip, string $stagingDir): array
    {
        $count = $zip->numFiles;
        if ($count === 0) {
            throw new PackageException(__('The archive is empty.'));
        }
        if ($count > $this->maxEntries) {
            throw new PackageException(__('The archive has too many entries (:n; limit :max).', ['n' => $count, 'max' => $this->maxEntries]));
        }

        $root = rtrim(str_replace('\\', '/', $stagingDir), '/');
        $written = [];
        $totalBytes = 0;

        // Pre-flight from the central directory: cheap header checks + declared-size caps BEFORE any write.
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new PackageException(__('The archive has an unreadable entry.'));
            }
            $name = (string) $stat['name'];
            $this->assertSafeName($name);

            $declared = (int) $stat['size'];
            $comp = (int) $stat['comp_size'];
            if (! str_ends_with($name, '/')) {
                if ($declared > $this->maxFileBytes) {
                    throw new PackageException(__('An entry is too large (:name).', ['name' => $name]));
                }
                if ($comp > 0 && $declared > 0 && intdiv($declared, max(1, $comp)) > $this->maxCompressionRatio) {
                    throw new PackageException(__('An entry has a suspicious compression ratio (:name).', ['name' => $name]));
                }
                $totalBytes += $declared;
                if ($totalBytes > $this->maxTotalBytes) {
                    throw new PackageException(__('The archive is too large uncompressed (limit :max bytes).', ['max' => $this->maxTotalBytes]));
                }
                $this->assertAllowedExtension($name);
            }
        }

        // Extraction: our OWN streamed copy per entry — never extractTo. Re-enforce the per-file + total byte
        // caps on the ACTUAL streamed bytes (the ratio cap above is header-only, defence-in-depth).
        $actualTotal = 0;
        for ($i = 0; $i < $count; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $target = $root.'/'.ltrim(str_replace('\\', '/', $name), '/');

            // Defence in depth: the resolved target must stay inside the staging root even after normalization.
            if (! $this->within($root, $target)) {
                throw new PackageException(__('An entry would escape the staging directory (:name).', ['name' => $name]));
            }

            if (str_ends_with($name, '/')) {
                $this->ensureDir($target);

                continue;
            }

            $this->ensureDir(dirname($target));
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new PackageException(__('An entry could not be read (:name).', ['name' => $name]));
            }

            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                throw new PackageException(__('Could not write a staged file.'));
            }

            $entryBytes = 0;
            try {
                while (! feof($stream)) {
                    $buffer = fread($stream, self::READ_CHUNK);
                    if ($buffer === false) {
                        throw new PackageException(__('An entry could not be read (:name).', ['name' => $name]));
                    }
                    $entryBytes += strlen($buffer);
                    $actualTotal += strlen($buffer);
                    // A LYING header can't slip a bomb through: abort on the real byte count, not the declared.
                    if ($entryBytes > $this->maxFileBytes || $actualTotal > $this->maxTotalBytes) {
                        throw new PackageException(__('An entry expanded beyond its allowed size (:name).', ['name' => $name]));
                    }
                    // Drain the whole buffer: fwrite may return a SHORT count (not false) under disk pressure,
                    // which would otherwise silently truncate the staged file.
                    $offset = 0;
                    $len = strlen($buffer);
                    while ($offset < $len) {
                        $wrote = fwrite($out, substr($buffer, $offset));
                        if ($wrote === false || $wrote === 0) {
                            throw new PackageException(__('Could not write a staged file.'));
                        }
                        $offset += $wrote;
                    }
                }
            } finally {
                fclose($stream);
                fclose($out);
            }

            $written[] = ltrim(str_replace('\\', '/', $name), '/');
        }

        return $written;
    }

    /** @throws PackageException */
    private function assertSafeName(string $name): void
    {
        if ($name === '' || strlen($name) > $this->maxNameLength) {
            throw new PackageException(__('An entry has an invalid name.'));
        }
        if (str_contains($name, "\0") || str_contains($name, '\\')) {
            throw new PackageException(__('An entry has an illegal path (:name).', ['name' => $name]));
        }
        $normalized = str_replace('\\', '/', $name);
        // No absolute paths, no drive letters, no traversal segments.
        if (str_starts_with($normalized, '/') || preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            throw new PackageException(__('An entry has an absolute path (:name).', ['name' => $name]));
        }
        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                throw new PackageException(__('An entry attempts path traversal (:name).', ['name' => $name]));
            }
        }
    }

    /** @throws PackageException */
    private function assertAllowedExtension(string $name): void
    {
        $base = basename(str_replace('\\', '/', $name));
        // The detached signature (module.sig, at the archive root) is always permitted — it is the one file
        // the trust gate itself consumes; every other file type must be on the allowlist.
        if ($name === PackageSignature::SIGNATURE_FILE) {
            return;
        }
        $ext = strtolower((string) pathinfo($base, PATHINFO_EXTENSION));
        $allowed = (array) config('novfora.modules.zip.extensions', self::DEFAULT_EXTENSIONS);
        if ($ext === '' || ! in_array($ext, $allowed, true)) {
            throw new PackageException(__('The archive contains a disallowed file type (:name).', ['name' => $name]));
        }
    }

    private function within(string $root, string $target): bool
    {
        $root = rtrim($root, '/').'/';
        // Collapse any residual . segments without touching the filesystem (the file may not exist yet).
        $parts = [];
        foreach (explode('/', $target) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);

                continue;
            }
            $parts[] = $segment;
        }
        $resolved = '/'.implode('/', $parts);
        // On Windows the root carries a drive letter; compare case-insensitively there, exactly elsewhere.
        $needle = rtrim($root, '/');

        return str_starts_with($resolved, $needle.'/') || str_starts_with(strtolower($resolved), strtolower($needle).'/');
    }

    /** @throws PackageException */
    private function ensureDir(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new PackageException(__('Could not create a staged directory.'));
        }
    }
}
