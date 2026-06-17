<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use Illuminate\Encryption\Encrypter;

/**
 * Safe, surgical writes to the `.env` file used by the installer (M5).
 *
 * Updates only the keys it is given, preserving every other line (comments, blank lines, unrelated
 * keys, ordering). Values that need it are quoted. Writes are whole-file and atomic-ish (write the full
 * new contents in one call). The target path comes from config so tests never touch the real `.env`.
 *
 * SECURITY: this class handles secrets (APP_KEY, DB password). It never logs values and never returns
 * them to a caller that would render them; the installer UI only ever shows success/failure, not the
 * written secrets.
 */
final class EnvWriter
{
    public function path(): string
    {
        return (string) config('novfora.install.env_path', base_path('.env'));
    }

    /** Ensure a `.env` exists, copying from `.env.example` when absent (a no-SSH upload has no `.env`). */
    public function ensureExists(): void
    {
        $path = $this->path();
        if (is_file($path)) {
            return;
        }

        $example = base_path('.env.example');
        $contents = is_file($example) ? (string) file_get_contents($example) : '';
        $this->putContents($contents);
    }

    /**
     * Guarantee the target `.env` carries a usable APP_KEY. If one is already in effect (config has a
     * key, e.g. set earlier this request or on a re-run) but the freshly-copied `.env` lacks it, persist
     * it. Otherwise generate a fresh base64 key, persist it, and apply it to the running config so the
     * very request that bootstraps a fresh upload can use the encrypter/session. Returns the key.
     */
    public function ensureAppKey(): string
    {
        $this->ensureExists();
        $current = (string) config('app.key', '');

        if ($current !== '') {
            // A `.env` copied from `.env.example` has an empty APP_KEY — make sure the in-effect key lands
            // in it (normalised to the `base64:` form Laravel writes).
            if (! $this->envHasAppKey()) {
                $this->set(['APP_KEY' => $this->normaliseKey($current)]);
            }

            return $current;
        }

        $cipher = (string) config('app.cipher', 'AES-256-CBC');
        $key = 'base64:'.base64_encode(Encrypter::generateKey($cipher));

        $this->set(['APP_KEY' => $key]);
        // Keep the `base64:` form in config — the encryption service provider decodes it on resolution.
        config(['app.key' => $key]);

        return $key;
    }

    private function envHasAppKey(): bool
    {
        return (bool) preg_match('/^APP_KEY=.+$/m', $this->getContents());
    }

    private function normaliseKey(string $key): string
    {
        // config may hold either the raw `base64:...` string or an already-decoded binary key.
        return str_starts_with($key, 'base64:') ? $key : 'base64:'.base64_encode($key);
    }

    /**
     * Set (create or replace) the given env keys. Values are written verbatim unless they need quoting
     * (whitespace or special chars), in which case they are double-quoted with `"`/`$`/backslash escaped.
     *
     * @param  array<string, string|int|bool|null>  $values
     */
    public function set(array $values): void
    {
        $this->ensureExists();
        $contents = $this->getContents();

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->format((string) ($value ?? ''));
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = (string) preg_replace($pattern, $this->escapeReplacement($line), $contents, 1);
            } else {
                $contents = rtrim($contents, "\r\n").PHP_EOL.$line.PHP_EOL;
            }
        }

        $this->putContents($contents);
    }

    private function format(string $value): string
    {
        if ($value === '') {
            return '';
        }

        // Quote when the value contains whitespace, a '#' (comment char), quotes, or a '$'. The '$' is
        // load-bearing for SECURITY: dotenv interpolates `${VAR}`/`$VAR` in UNQUOTED (and double-quoted)
        // values, so an installer-supplied value like `X${DB_PASSWORD}` (no whitespace) would otherwise be
        // written bare and resolve to a secret on the next load. Forcing it through the double-quoted branch
        // (which escapes `$`->`\$`) neutralises interpolation. A bare '=' still needs no quoting — dotenv
        // splits on the FIRST '=' — so a base64 APP_KEY stays unquoted like key:generate.
        if (preg_match('/[\s#"\'$]/', $value)) {
            return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value).'"';
        }

        return $value;
    }

    /** preg_replace treats `$` and `\` specially in the replacement — neutralise them. */
    private function escapeReplacement(string $line): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $line);
    }

    private function getContents(): string
    {
        $path = $this->path();

        return is_file($path) ? (string) file_get_contents($path) : '';
    }

    private function putContents(string $contents): void
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the environment file: '.$path);
        }

        // 0600 (owner-only): `.env` holds the APP_KEY and DB password. On shared hosting a world-readable
        // (0644) secrets file can be read by other tenants on the same box, so restrict it. The web user
        // that writes it is the same account user that later reads it (FPM/suexec per-user), so this does
        // not lock the app out. A no-op on Windows.
        @chmod($path, 0600);
    }
}
