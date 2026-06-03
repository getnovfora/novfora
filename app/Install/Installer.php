<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

/**
 * The install-state / lock authority (M5, phase-1-plan §5).
 *
 * Hearth is installed iff the marker file exists. A FILE marker — not a DB flag — is deliberate: the
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
        return (string) config('hearth.install.marker', storage_path('installed'));
    }

    public function isInstalled(): bool
    {
        return is_file($this->markerPath());
    }

    /**
     * Whether the app should force the unauthenticated installer (redirect every request to the wizard
     * and harden pre-install drivers). False once installed, and false in environments that opt out
     * (the test suite, via HEARTH_INSTALL_ENFORCE=false) so they are never redirected to /install.
     */
    public function shouldEnforce(): bool
    {
        return (bool) config('hearth.install.enforce', true) && ! $this->isInstalled();
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

    /** A non-reversible fingerprint of the current APP_KEY (or '' if unset) — provenance, not a secret. */
    private function appKeyFingerprint(): string
    {
        $key = (string) config('app.key', '');

        return $key === '' ? '' : substr(hash('sha256', $key), 0, 12);
    }
}
