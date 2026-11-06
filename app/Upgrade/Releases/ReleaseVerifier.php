<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Upgrade\Releases;

use Illuminate\Support\Facades\File;
use ZipArchive;

/** Release-specific counterpart to the module ArchiveGuard: authenticate first, then bounded streamed extraction. */
final class ReleaseVerifier
{
    /** @return array<string,mixed> validated manifest */
    public function verify(string $zip, string $sig, string $expectedVersion): array
    {
        $temp = sys_get_temp_dir().'/novfora-release-'.bin2hex(random_bytes(12));
        try {
            return $this->stageSigned($zip, $sig, $temp, $expectedVersion);
        } finally {
            if (is_dir($temp)) {
                File::deleteDirectory($temp);
            }
        }
    }

    /** Signature is the ZIP's sibling `<zip>.sig`. Returns the validated manifest. */
    public function stage(string $zip, string $directory, string $expectedVersion): array
    {
        return $this->stageSigned($zip, $zip.'.sig', $directory, $expectedVersion);
    }

    /** @return array<string,mixed> */
    private function stageSigned(string $zip, string $sig, string $directory, string $expectedVersion): array
    {
        $digest = $this->authenticate($zip, $sig);
        if (file_exists($directory) || is_link($directory) || ! @mkdir($directory, 0700, true)) {
            throw new ReleaseException('Release staging target must be a new directory.');
        }
        try {
            $manifest = $this->extractAndCheck($zip, $directory, $expectedVersion);
            if (! hash_equals($digest, (string) hash_file('sha256', $zip))) {
                throw new ReleaseException('Release archive changed after signature verification.');
            }

            return $manifest;
        } catch (\Throwable $e) {
            File::deleteDirectory($directory);
            throw $e;
        }
    }

    private function authenticate(string $zip, string $sig): string
    {
        $size = @filesize($zip);
        $sigSize = @filesize($sig);
        if ($size === false || $size < 1 || $size > (int) config('releases.max_archive_bytes', 268_435_456)
            || $sigSize === false || $sigSize < 1 || $sigSize > (int) config('releases.max_signature_bytes', 4096)) {
            throw new ReleaseException('Release archive or detached signature is missing or oversized.');
        }
        if (! function_exists('sodium_crypto_sign_verify_detached')) {
            throw new ReleaseException('Sodium is required to verify core releases.');
        }
        $memory = trim((string) ini_get('memory_limit'));
        if ($memory !== '' && $memory !== '-1') {
            $unit = strtolower(substr($memory, -1));
            $number = (int) $memory;
            $multiplier = match ($unit) {
                'g' => 1_073_741_824,
                'm' => 1_048_576,
                'k' => 1024,
                default => 1,
            };
            if ($number > 0 && $size * 2 > $number * $multiplier - memory_get_usage(true)) {
                throw new ReleaseException('PHP memory limit is too low to authenticate this release archive.');
            }
        }
        $payload = json_decode((string) file_get_contents($sig), true);
        $id = is_array($payload) ? ($payload['key_id'] ?? null) : null;
        $encoded = is_array($payload) ? ($payload['signature'] ?? null) : null;
        $keys = config('releases.trusted_keys', []);
        if (! is_string($id) || ! is_string($encoded) || ! is_array($keys)
            || ! isset($keys[$id]) || ! is_string($keys[$id])) {
            throw new ReleaseException('Release signature has no trusted signing key.');
        }
        $signature = base64_decode($encoded, true);
        $publicKey = base64_decode($keys[$id], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || $publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ReleaseException('Invalid release signature encoding.');
        }
        $bytes = @file_get_contents($zip);
        if ($bytes === false || strlen($bytes) !== $size || ! sodium_crypto_sign_verify_detached($signature, $bytes, $publicKey)) {
            throw new ReleaseException('Release signature verification failed.');
        }
        $digest = hash('sha256', $bytes);
        sodium_memzero($bytes);

        return $digest;
    }

    /** @return array<string,mixed> */
    private function extractAndCheck(string $path, string $directory, string $expectedVersion): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ReleaseException('Release is not a readable ZIP archive.');
        }
        try {
            $count = $zip->numFiles;
            if ($count < 2 || $count > (int) config('releases.max_entries', 20_000)) {
                throw new ReleaseException('Release has an invalid entry count.');
            }
            $names = [];
            $declaredTotal = 0;
            for ($i = 0; $i < $count; $i++) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    throw new ReleaseException('Unreadable release entry.');
                }
                $name = (string) $entry['name'];
                $this->safeName($name);
                if (isset($names[$name]) || (str_ends_with($name, '/') && isset($names[rtrim($name, '/')]))
                    || (! str_ends_with($name, '/') && isset($names[$name.'/']))) {
                    throw new ReleaseException('Duplicate release entry.');
                }
                $names[$name] = true;
                $ops = $zip->getExternalAttributesIndex($i, $system, $attributes);
                if ($ops && $system === ZipArchive::OPSYS_UNIX) {
                    $type = ($attributes >> 16) & 0170000;
                    if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                        throw new ReleaseException('Release contains a link or special file.');
                    }
                }
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $size = (int) $entry['size'];
                $compressed = (int) $entry['comp_size'];
                $declaredTotal += $size;
                if ($size < 0 || $size > (int) config('releases.max_file_bytes', 134_217_728)
                    || $declaredTotal > (int) config('releases.max_total_bytes', 536_870_912)
                    || ($compressed > 0 && intdiv($size, $compressed) > (int) config('releases.max_compression_ratio', 200))) {
                    throw new ReleaseException('Release entry exceeds extraction limits.');
                }
            }
            $actualTotal = 0;
            $hashes = [];
            foreach (array_keys($names) as $name) {
                $target = $directory.'/'.$name;
                if (str_ends_with($name, '/')) {
                    if (! is_dir($target) && ! @mkdir($target, 0700, true)) {
                        throw new ReleaseException('Could not stage release directory.');
                    }

                    continue;
                }
                if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0700, true)) {
                    throw new ReleaseException('Could not stage release directory.');
                }
                $in = $zip->getStream($name);
                $out = @fopen($target, 'xb');
                if ($in === false || $out === false) {
                    if (is_resource($in)) {
                        fclose($in);
                    }
                    throw new ReleaseException('Could not stage release entry.');
                }
                $hash = hash_init('sha256');
                $fileBytes = 0;
                try {
                    while (! feof($in)) {
                        $chunk = fread($in, 262144);
                        if ($chunk === false) {
                            throw new ReleaseException('Could not read release entry.');
                        }
                        $length = strlen($chunk);
                        $fileBytes += $length;
                        $actualTotal += $length;
                        if ($fileBytes > (int) config('releases.max_file_bytes', 134_217_728)
                            || $actualTotal > (int) config('releases.max_total_bytes', 536_870_912)) {
                            throw new ReleaseException('Release expanded beyond extraction limits.');
                        }
                        hash_update($hash, $chunk);
                        $offset = 0;
                        while ($offset < $length) {
                            $written = fwrite($out, substr($chunk, $offset));
                            if ($written === false || $written === 0) {
                                throw new ReleaseException('Could not write release entry.');
                            }
                            $offset += $written;
                        }
                    }
                } finally {
                    fclose($in);
                    fclose($out);
                }
                $hashes[$name] = hash_final($hash);
            }
            $raw = @file_get_contents($directory.'/release-manifest.json');
            if ($raw === false || strlen($raw) > 2_097_152) {
                throw new ReleaseException('Release manifest is missing or oversized.');
            }
            $manifest = json_decode($raw, true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== 1
                || ($manifest['version'] ?? null) !== $expectedVersion
                || ! is_string($manifest['php'] ?? null)
                || ($manifest['php'] !== '>=8.3')
                || ! is_array($manifest['files'] ?? null)) {
                throw new ReleaseException('Release manifest has invalid format, version, or PHP requirement.');
            }
            unset($hashes['release-manifest.json']);
            ksort($hashes);
            ksort($manifest['files']);
            if ($manifest['files'] !== $hashes) {
                throw new ReleaseException('Release manifest does not match the archived files.');
            }

            return $manifest;
        } finally {
            $zip->close();
        }
    }

    private function safeName(string $name): void
    {
        if ($name === '' || strlen($name) > 512 || str_contains($name, "\0") || str_contains($name, '\\')
            || str_starts_with($name, '/') || preg_match('/^[A-Za-z]:/', $name)
            || str_contains($name, '//')) {
            throw new ReleaseException('Release contains an unsafe path.');
        }
        foreach (explode('/', $name) as $part) {
            if ($part === '' && str_ends_with($name, '/')) {
                continue;
            }
            if ($part === '' || $part === '.' || $part === '..') {
                throw new ReleaseException('Release contains an unsafe path.');
            }
        }
        if (($name !== '.env.example' && preg_match('#(^|/)\.env($|[.])#', $name) === 1)
            || $name === 'modules/novfora/populate' || str_starts_with($name, 'modules/novfora/populate/')
            || (str_starts_with($name, 'storage/logs/') && $name !== 'storage/logs/' && $name !== 'storage/logs/.gitignore')
            || $name === 'storage/installed' || $name === 'storage/install-token.txt'
            || $name === 'storage/app/private'
            || (str_starts_with($name, 'storage/app/private/') && $name !== 'storage/app/private/'
                && $name !== 'storage/app/private/.gitignore')) {
            throw new ReleaseException('Release contains private or runtime content.');
        }
    }
}
