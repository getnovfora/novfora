<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

/**
 * The install-state / lock authority (M5, phase-1-plan §5).
 *
 * NovFora is installed iff the marker file exists. A FILE marker — not a DB flag — is deliberate: the
 * lock must be checkable BEFORE the database exists (a fresh, no-SSH upload) and must survive a DB wipe,
 * so a wiped database can never silently re-open the unauthenticated installer.
 *
 * SECURITY CONTRACT: {@see markInstalled()} is called LAST in the install sequence, only after every
 * step has succeeded, so a half-finished install never looks complete and never locks prematurely.
 * There is intentionally NO web route that clears the marker — {@see reset()} is CLI-only (an operator
 * with shell/filesystem access, i.e. already trusted). This removes the "re-trigger the installer to
 * reset the admin" attack surface entirely.
 */
final class Installer
{
    public function markerPath(): string
    {
        return (string) config('novfora.install.marker', storage_path('installed'));
    }

    public function isInstalled(): bool
    {
        return is_file($this->markerPath());
    }

    /**
     * Whether the app should force the unauthenticated installer (redirect every request to the wizard
     * and harden pre-install drivers). False once installed, and false in environments that opt out
     * (the test suite, via NOVFORA_INSTALL_ENFORCE=false) so they are never redirected to /install.
     */
    public function shouldEnforce(): bool
    {
        return (bool) config('novfora.install.enforce', true) && ! $this->isInstalled();
    }

    /**
     * Write the lock marker. Idempotent. Stores only non-secret provenance (version + timestamp) — never
     * credentials. The directory is created if missing; failure to write is surfaced (the caller treats
     * an unwritable marker as a failed install rather than silently leaving the installer open).
     */
    public function markInstalled(array $meta = []): void
    {
        $path = $this->markerPath();
        $dir = \dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Could not create the directory for the install marker: '.$dir);
        }

        $payload = json_encode([
            'installed_at' => now()->toIso8601String(),
            'version' => $meta['version'] ?? config('app.version', '1.0.0-mvp'),
            // Never persist secrets here. A short, non-reversible app-key fingerprint is enough to tell
            // "installed against this key" without exposing the key itself.
            'app_key_fingerprint' => $this->appKeyFingerprint(),
        ] + array_diff_key($meta, ['version' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (@file_put_contents($path, $payload.PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the install marker: '.$path);
        }

        @chmod($path, 0644);
    }

    /**
     * Remove the lock marker. CLI/operator use only (never wired to a web route). Returns whether a
     * marker was actually removed.
     */
    public function reset(): bool
    {
        $path = $this->markerPath();

        return is_file($path) && @unlink($path);
    }

    // ── Pre-install setup token (phase-1.5 F-A) ─────────────────────────────────────────────────────
    // A random token written to a 0600 file on first boot of a not-yet-installed site. The wizard and the
    // CLI installer require it, so an attacker who reaches the unauthenticated installer first cannot run it
    // (or use the DB-test SSRF) without filesystem access to read the value. Consumed on a successful install.

    public function tokenPath(): string
    {
        return (string) config('novfora.install.token_path', storage_path('install-token.txt'));
    }

    public function requiresToken(): bool
    {
        return (bool) config('novfora.install.require_token', true);
    }

    /**
     * Ensure a setup token exists (creating it 0600 if absent) and return it. Returns null when tokens are
     * not required, or when the filesystem can't hold one — in which case {@see verifyToken()} fails closed.
     */
    public function ensureToken(): ?string
    {
        if (! $this->requiresToken()) {
            return null;
        }
        if (($existing = $this->readToken()) !== null) {
            return $existing;
        }

        $token = bin2hex(random_bytes(16));
        $path = $this->tokenPath();
        $dir = \dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return null;
        }
        if (@file_put_contents($path, $token.PHP_EOL, LOCK_EX) === false) {
            return null;
        }
        @chmod($path, 0600);

        return $token;
    }

    public function readToken(): ?string
    {
        $path = $this->tokenPath();
        if (! is_file($path)) {
            return null;
        }
        $value = trim((string) file_get_contents($path));

        return $value === '' ? null : $value;
    }

    /** Whether the supplied token is acceptable: true when tokens aren't required, else a constant-time match. */
    public function verifyToken(?string $supplied): bool
    {
        if (! $this->requiresToken()) {
            return true;
        }
        $actual = $this->readToken();
        if ($actual === null) {
            return false; // required but none on disk → fail closed
        }

        return is_string($supplied) && $supplied !== '' && hash_equals($actual, trim($supplied));
    }

    /** Delete the token after a successful install (single-use). */
    public function consumeToken(): void
    {
        $path = $this->tokenPath();
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** A non-reversible fingerprint of the current APP_KEY (or '' if unset) — provenance, not a secret. */
    private function appKeyFingerprint(): string
    {
        $key = (string) config('app.key', '');

        return $key === '' ? '' : substr(hash('sha256', $key), 0, 12);
    }
}
