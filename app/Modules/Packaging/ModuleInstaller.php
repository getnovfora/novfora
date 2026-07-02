<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules\Packaging;

use App\Models\Module;
use App\Modules\ManifestValidator;
use App\Modules\ModuleException;
use App\Modules\ModuleManager;
use App\Modules\ModuleTrustKeys;
use App\Support\Audit;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Install-from-zip orchestrator (U17, ADR-0104, apex). Takes an UNTRUSTED uploaded archive and, only if every
 * gate passes, atomically populates modules/<vendor>/<name>/ and hands off to the existing audited
 * ModuleManager lifecycle. The gates, in order:
 *
 *   1. Safe extraction into a throwaway staging dir (ArchiveGuard — traversal/symlink/bomb/type hardening).
 *   2. Manifest validation (ManifestValidator) → the slug comes from module.json, NEVER the upload filename.
 *   3. Signature / trust gate (PackageSignature against the trusted-key registry): a present-but-invalid
 *      signature is ALWAYS rejected; an unsigned package is rejected unless the loud `allow_unsigned` policy
 *      is on. A verified package records which key signed it.
 *   4. Atomic commit: rename staging → modules/<slug> only after all gates pass, then ModuleManager::install
 *      (fresh) or ::upgrade (existing, confirmed). Any failure rolls back and QUARANTINES the archive.
 *
 * Nothing here runs a queue/daemon — it is synchronous and Baseline-safe, bounded by the ArchiveGuard caps.
 */
final class ModuleInstaller
{
    public function __construct(
        private readonly ModuleManager $modules,
        private readonly ManifestValidator $validator,
        private readonly ModuleTrustKeys $trustKeys,
    ) {}

    /**
     * @return array{module: Module, slug: string, trust: string, signer_fingerprint: ?string}
     *
     * @throws PackageException
     */
    public function installFromZip(string $archivePath, bool $allowUpgrade = false): array
    {
        if (! is_file($archivePath)) {
            throw new PackageException(__('The uploaded package is missing.'));
        }

        $staging = $this->freshDir($this->stagingRoot());

        try {
            ArchiveGuard::fromConfig()->extract($archivePath, $staging);

            $slug = $this->resolveSlug($staging);
            [$trust, $signerFingerprint] = $this->assertTrusted($staging, $slug);

            $module = $this->commit($slug, $staging, $allowUpgrade, $trust, $signerFingerprint);

            return ['module' => $module, 'slug' => $slug, 'trust' => $trust, 'signer_fingerprint' => $signerFingerprint];
        } catch (PackageException $e) {
            $this->quarantine($archivePath, $e->getMessage());
            throw $e;
        } finally {
            File::deleteDirectory($staging);
        }
    }

    /** @throws PackageException */
    private function resolveSlug(string $staging): string
    {
        $path = $staging.'/module.json';
        if (! is_file($path)) {
            throw new PackageException(__('The archive has no module.json at its root.'));
        }

        try {
            // Validate the manifest fully but WITHOUT the directory-name cross-check — the staging dir is a
            // random temp name, not modules/<slug>. The full directory-matched validation still runs later,
            // inside ModuleManager::install(), once the files are atomically moved to modules/<slug>.
            $manifest = $this->validator->fromJson((string) file_get_contents($path));
        } catch (ModuleException $e) {
            throw new PackageException(__('The package manifest is invalid: :why', ['why' => $e->getMessage()]));
        }

        return $manifest->slug;
    }

    /**
     * @return array{0:string,1:?string} the trust tier ('signed'|'unsigned') and the signer fingerprint
     *
     * @throws PackageException
     */
    private function assertTrusted(string $staging, string $slug): array
    {
        $signer = PackageSignature::verify($staging, $this->trustKeys->enabledPublicKeys());
        if ($signer !== null) {
            $fingerprint = hash('sha256', (string) base64_decode($signer, true));
            Audit::log('module.zip_install.signature_verified', null, ['slug' => $slug, 'signer_fingerprint' => $fingerprint]);

            return ['signed', $fingerprint];
        }

        // A signature that is PRESENT but does not verify against any trusted key is never acceptable — that is
        // a tamper or an untrusted signer, not merely "unsigned". Reject regardless of the allow_unsigned policy.
        if (is_file($staging.'/'.PackageSignature::SIGNATURE_FILE)) {
            throw new PackageException(__('The package signature is invalid or not from a trusted key. Refusing to install.'));
        }

        if (! (bool) config('novfora.modules.zip.allow_unsigned', false)) {
            throw new PackageException(__('This package is unsigned. Signed packages from a trusted key are required (set NOVFORA_MODULES_ALLOW_UNSIGNED to override in development only).'));
        }

        return ['unsigned', null];
    }

    /** @throws PackageException */
    private function commit(string $slug, string $staging, bool $allowUpgrade, string $trust, ?string $signerFingerprint): Module
    {
        $target = $this->modules->dirFor($slug); // routes through the slug boundary guard (no traversal)
        $exists = is_dir($target);

        if ($exists && ! $allowUpgrade) {
            throw new PackageException(__("Module ':slug' is already installed. Confirm an upgrade to replace it.", ['slug' => $slug]));
        }

        File::ensureDirectoryExists(dirname($target));

        if (! $exists) {
            $this->move($staging, $target);
            try {
                $module = $this->modules->install($slug);
            } catch (\Throwable $e) {
                File::deleteDirectory($target); // roll the files back out
                throw new PackageException(__('Install failed after staging: :why', ['why' => $e->getMessage()]));
            }
            Audit::log('module.zip_install.accepted', $module, ['slug' => $slug, 'trust' => $trust, 'signer_fingerprint' => $signerFingerprint, 'action' => 'install']);

            return $module;
        }

        // Upgrade path: back up the live dir, swap in the new files, upgrade; restore on any failure.
        $backup = $this->freshDir($this->stagingRoot()).'-backup';
        $this->move($target, $backup);
        $this->move($staging, $target);
        try {
            $module = $this->modules->upgrade($slug);
        } catch (\Throwable $e) {
            File::deleteDirectory($target);
            $this->move($backup, $target); // restore the previous version verbatim
            throw new PackageException(__('Upgrade failed and was rolled back: :why', ['why' => $e->getMessage()]));
        }
        File::deleteDirectory($backup);
        Audit::log('module.zip_install.accepted', $module, ['slug' => $slug, 'trust' => $trust, 'signer_fingerprint' => $signerFingerprint, 'action' => 'upgrade']);

        return $module;
    }

    private function quarantine(string $archivePath, string $reason): void
    {
        try {
            $dir = $this->quarantineRoot();
            File::ensureDirectoryExists($dir);
            $dest = $dir.'/'.now()->format('Ymd_His').'_'.Str::random(8).'.zip';
            @copy($archivePath, $dest);
            Audit::log('module.zip_install.rejected', null, ['reason' => Str::limit($reason, 240), 'quarantined' => basename($dest)]);
        } catch (\Throwable) {
            // Quarantine is best-effort telemetry; never let it mask the original rejection.
        }
    }

    private function move(string $from, string $to): void
    {
        if (! @rename($from, $to)) {
            // rename can fail across filesystems (staging on a different mount than modules/); fall back to a
            // recursive copy + delete so the commit still completes on such hosts.
            if (! File::copyDirectory($from, $to)) {
                throw new PackageException(__('Could not move the staged package into place.'));
            }
            File::deleteDirectory($from);
        }
    }

    private function freshDir(string $root): string
    {
        File::ensureDirectoryExists($root);
        $dir = $root.'/'.Str::random(16);
        File::ensureDirectoryExists($dir);

        return $dir;
    }

    private function stagingRoot(): string
    {
        return (string) config('novfora.modules.zip.staging_path', storage_path('app/module-staging'));
    }

    private function quarantineRoot(): string
    {
        return (string) config('novfora.modules.zip.quarantine_path', storage_path('app/module-quarantine'));
    }
}
