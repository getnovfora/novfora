<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

use App\Models\AclEntry;
use App\Models\Module;
use App\Models\Permission;
use App\Permissions\AclVersion;
use App\Support\Audit;
use Database\Seeders\PermissionCatalogSeeder;
use Illuminate\Support\Facades\Artisan;

/**
 * The single audited lifecycle authority for local modules/plugins (ADR-0031, apex). It is the ONLY writer of
 * the `modules` table and the only thing that runs a module's migrations or registers its permission keys.
 *
 * Security posture (non-negotiable): a module is a LOCAL package an admin installs — there is NO remote fetch,
 * no eval of downloaded code, no marketplace. The manifest is validated (ManifestValidator) before anything is
 * trusted; compatibility + dependencies are checked BEFORE enable ("know before you enable"); a module may
 * NEVER redefine a core permission key, and the keys it does register only ADD to the catalog — grants remain
 * separate acl_entries an admin must create, so a module can never escalate anyone's permissions.
 *
 * Lifecycle: discover (filesystem) → install (record, disabled) → enable (compat + deps + permissions +
 * migrations, then load on boot) → disable (stop loading, KEEP data) → upgrade (new version + new migrations)
 * → remove (roll back migrations, drop owned permissions + their grants, delete the row).
 */
final class ModuleManager
{
    public function __construct(private readonly ManifestValidator $validator) {}

    public function path(): string
    {
        return (string) config('novfora.modules.path', base_path('modules'));
    }

    public function dirFor(string $slug): string
    {
        // BOUNDARY GUARD (apex): the lifecycle $slug is attacker-influenceable (a livewire/update can send any
        // string to install/enable/remove). Prove it is a path-safe vendor/name BEFORE it is ever concatenated
        // into a filesystem / migration --path, so it can never traverse out of modules/ (e.g. a/../../etc).
        // Every path helper (srcPath, migrationsPath) and manifestFor route through here, so this one assertion
        // closes the traversal for the whole lifecycle.
        $this->validator->assertSlug($slug);

        return $this->path().'/'.$slug;
    }

    public function srcPath(string $slug): string
    {
        return $this->dirFor($slug).'/src';
    }

    public function migrationsPath(string $slug): string
    {
        return $this->dirFor($slug).'/database/migrations';
    }

    public function manifestFor(string $slug): ModuleManifest
    {
        return $this->validator->fromDirectory($this->dirFor($slug));
    }

    /**
     * Scan the modules directory for valid manifests. Invalid manifests are skipped (their error is available
     * via manifestFor()); this never throws, so a single broken module can't break the ACP listing.
     *
     * @return list<ModuleManifest>
     */
    public function discover(): array
    {
        $base = $this->path();
        if (! is_dir($base)) {
            return [];
        }
        $found = [];
        foreach (glob($base.'/*', GLOB_ONLYDIR) ?: [] as $vendorDir) {
            foreach (glob($vendorDir.'/*', GLOB_ONLYDIR) ?: [] as $moduleDir) {
                if (! is_file($moduleDir.'/module.json')) {
                    continue;
                }
                try {
                    $found[] = $this->validator->fromDirectory($moduleDir);
                } catch (ModuleException) {
                    // skip — surfaced individually where an admin asks for this slug
                }
            }
        }

        return $found;
    }

    public function compatibility(ModuleManifest $manifest): string
    {
        return ModuleApi::satisfies($manifest->apiVersion) ? 'compatible' : 'incompatible';
    }

    public function install(string $slug): Module
    {
        $manifest = $this->manifestFor($slug);
        $this->assertCompatible($manifest);

        $module = Module::updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $manifest->name,
                'version' => $manifest->version,
                'api_version' => $manifest->apiVersion,
                'enabled' => false,
                'installed_at' => now(),
                'package_hash' => $this->packageHash($slug), // integrity baseline recorded at install
                'meta' => $this->metaFor($manifest),
            ],
        );
        Audit::log('module.installed', $module, ['slug' => $slug, 'version' => $manifest->version]);

        return $module;
    }

    public function enable(string $slug, bool $acknowledgeTrust = false): Module
    {
        $module = $this->installed($slug);
        $manifest = $this->manifestFor($slug);
        $this->assertCompatible($manifest);
        $this->assertDependencies($manifest);

        // CONSENT GATE (apex): enabling a module loads its PHP in-process with FULL server trust. Refuse unless
        // an admin has explicitly acknowledged that. Consent is recorded ONCE per module and carries across a
        // later disable/enable, so the admin isn't re-prompted for a module they already vouched for.
        if ($module->consented_at === null && ! $acknowledgeTrust) {
            throw new ModuleException(
                "Enabling '{$slug}' runs its code with full server trust. Confirm you trust this module to proceed."
            );
        }

        $this->registerPermissions($module, $manifest);
        $this->runMigrations($slug);

        $module->forceFill([
            'enabled' => true,
            'consented_at' => $module->consented_at ?? now(),
            'package_hash' => $this->packageHash($slug), // bless the current files as the integrity baseline
            'failed_at' => null,                          // clear any prior disable-on-fatal quarantine
            'last_error' => null,
            'name' => $manifest->name,
            'version' => $manifest->version,
            'api_version' => $manifest->apiVersion,
            'meta' => $this->metaFor($manifest),
        ])->save();
        Audit::log('module.enabled', $module, ['slug' => $slug, 'version' => $manifest->version, 'package_hash' => $module->package_hash]);

        return $module;
    }

    public function disable(string $slug): Module
    {
        $module = $this->installed($slug);
        // Non-destructive: the schema + data stay so a later re-enable needs no re-import; we just stop loading
        // the provider on the next boot (ADR-0031 refinement of ADR-0008 — disable ≠ data loss; remove purges).
        $module->forceFill(['enabled' => false])->save();
        Audit::log('module.disabled', $module, ['slug' => $slug]);

        return $module;
    }

    public function upgrade(string $slug): Module
    {
        $module = $this->installed($slug);
        $manifest = $this->manifestFor($slug);
        $this->assertCompatible($manifest);
        $this->assertDependencies($manifest);

        // Monotonic version guard (hardening): refuse a DOWNGRADE on upgrade. The on-disk version is operator-
        // controlled and feeds downstream modules' `requires` checks; refusing a lower version blocks a swapped
        // manifest from rolling the recorded version backwards.
        if (SemverConstraint::satisfies($module->version, '>='.$manifest->version)
            && $module->version !== $manifest->version) {
            throw new ModuleException(
                "Module '{$slug}' upgrade refused: manifest version {$manifest->version} is older than the "
                ."installed {$module->version}."
            );
        }

        if ($module->enabled) {
            $this->runMigrations($slug);
            $this->registerPermissions($module, $manifest);
        }
        $module->forceFill([
            'name' => $manifest->name,
            'version' => $manifest->version,
            'api_version' => $manifest->apiVersion,
            'package_hash' => $this->packageHash($slug), // re-bless the integrity baseline for the new version
            'meta' => $this->metaFor($manifest),
        ])->save();
        Audit::log('module.upgraded', $module, ['slug' => $slug, 'to' => $manifest->version]);

        return $module;
    }

    public function remove(string $slug): void
    {
        $module = $this->installed($slug);
        $this->rollbackMigrations($slug);
        $this->dropPermissions($module);
        Audit::log('module.removed', $module, ['slug' => $slug]);
        $module->delete();
    }

    /**
     * A content hash of the package — sha-256 over a path-sorted serialisation of `module.json` plus every PHP
     * file under `src/` and `database/migrations/`. Recorded at install/enable/upgrade as the admin-blessed
     * baseline so {@see integrityStatus()} can flag files that changed since (tamper / accidental-edit detection).
     */
    public function packageHash(string $slug): ?string
    {
        $dir = $this->dirFor($slug); // routes through the slug boundary guard (no traversal)
        if (! is_dir($dir)) {
            return null;
        }

        $files = is_file($dir.'/module.json') ? ['module.json'] : [];
        foreach (['src', 'database/migrations'] as $sub) {
            $base = $dir.'/'.$sub;
            if (! is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $sub.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                }
            }
        }
        sort($files);

        $hash = hash_init('sha256');
        foreach ($files as $rel) {
            $path = $dir.'/'.$rel;
            if (is_file($path)) {
                hash_update($hash, $rel."\0".(string) file_get_contents($path)."\0");
            }
        }

        return hash_final($hash);
    }

    /** 'verified' (files match the blessed hash), 'modified' (differ), or 'unknown' (no baseline / files gone). */
    public function integrityStatus(string $slug): string
    {
        $module = Module::where('slug', $slug)->first();
        if (! $module instanceof Module || $module->package_hash === null) {
            return 'unknown';
        }
        $current = $this->packageHash($slug);

        return $current !== null && hash_equals($module->package_hash, $current) ? 'verified' : 'modified';
    }

    /**
     * Disable-on-fatal (apex): a module whose provider throws while loading is QUARANTINED — disabled + the
     * error recorded — so it is skipped on the next boot instead of repeatedly white-screening the site.
     * Called by {@see ModuleLoader} from a caught Throwable; swallows its own errors so the safety net can
     * never itself fatal a request.
     */
    public function quarantine(string $slug, string $error): void
    {
        try {
            $module = Module::where('slug', $slug)->first();
            if (! $module instanceof Module) {
                return;
            }
            $module->forceFill(['enabled' => false, 'failed_at' => now(), 'last_error' => mb_substr($error, 0, 1000)])->save();
            Audit::log('module.quarantined', $module, ['slug' => $slug, 'error' => mb_substr($error, 0, 250)]);
        } catch (\Throwable) {
            // never let the safety net itself break the request
        }
    }

    /** Whether the global module KILL SWITCH (file-based safe mode) is engaged — ModuleLoader loads nothing. */
    public function safeMode(): bool
    {
        return is_file($this->safeModeMarker());
    }

    public function engageSafeMode(): void
    {
        @file_put_contents($this->safeModeMarker(), 'engaged '.now()->toIso8601String());
        Audit::log('module.safe_mode.engaged', null, []);
    }

    public function releaseSafeMode(): void
    {
        $marker = $this->safeModeMarker();
        if (is_file($marker)) {
            @unlink($marker);
        }
        Audit::log('module.safe_mode.released', null, []);
    }

    private function safeModeMarker(): string
    {
        return (string) config('novfora.modules.safe_mode_marker', storage_path('modules-safe-mode'));
    }

    private function installed(string $slug): Module
    {
        $module = Module::where('slug', $slug)->first();
        if (! $module instanceof Module) {
            throw new ModuleException("Module '{$slug}' is not installed.");
        }

        return $module;
    }

    private function assertCompatible(ModuleManifest $manifest): void
    {
        if (! ModuleApi::satisfies($manifest->apiVersion)) {
            throw new ModuleException(
                "Module '{$manifest->slug}' targets module API '{$manifest->apiVersion}', "
                .'incompatible with core '.ModuleApi::VERSION.'.'
            );
        }
        if ($manifest->requiresPhp !== null && ! SemverConstraint::satisfies($this->phpVersion(), $manifest->requiresPhp)) {
            throw new ModuleException(
                "Module '{$manifest->slug}' requires PHP '{$manifest->requiresPhp}', running ".$this->phpVersion().'.'
            );
        }
    }

    private function assertDependencies(ModuleManifest $manifest): void
    {
        foreach ($manifest->requiresModules as $depSlug => $constraint) {
            $dep = Module::where('slug', $depSlug)->first();
            if (! $dep instanceof Module || ! $dep->enabled) {
                throw new ModuleException(
                    "Module '{$manifest->slug}' requires '{$depSlug}' ({$constraint}) to be installed and enabled."
                );
            }
            if (! SemverConstraint::satisfies($dep->version, $constraint)) {
                throw new ModuleException(
                    "Module '{$manifest->slug}' requires '{$depSlug}' {$constraint}, but {$dep->version} is enabled."
                );
            }
        }
    }

    private function registerPermissions(Module $module, ModuleManifest $manifest): void
    {
        $coreKeys = array_keys(PermissionCatalogSeeder::catalog());
        $owned = [];
        foreach ($manifest->permissions as $perm) {
            $key = $perm['key'];
            if (in_array($key, $coreKeys, true)) {
                throw new ModuleException("Module '{$manifest->slug}' may not redefine the core permission '{$key}'.");
            }
            $otherOwner = Module::where('slug', '!=', $manifest->slug)->get()
                ->first(fn (Module $m): bool => in_array($key, $m->permission_keys ?? [], true));
            if ($otherOwner instanceof Module) {
                throw new ModuleException("Permission '{$key}' is already provided by module '{$otherOwner->slug}'.");
            }
            Permission::updateOrCreate(
                ['key' => $key],
                ['label' => $perm['label'], 'scope_kind' => $perm['scope_kind'], 'group' => $perm['group'], 'description' => $perm['description']],
            );
            $owned[] = $key;
        }
        $module->forceFill(['permission_keys' => $owned])->save();
        if ($owned !== []) {
            app(AclVersion::class)->bump(); // a new catalog key may unblock a pending grant; refresh resolution
        }
    }

    private function dropPermissions(Module $module): void
    {
        $keys = $module->permission_keys ?? [];
        if ($keys === []) {
            return;
        }
        // Remove the catalog entries AND any grants that referenced them, so removal leaves no dangling ACL.
        Permission::whereIn('key', $keys)->delete();
        AclEntry::whereIn('permission_key', $keys)->delete();
        app(AclVersion::class)->bump();
    }

    private function runMigrations(string $slug): void
    {
        $path = $this->migrationsPath($slug);
        if (! is_dir($path)) {
            return;
        }
        Artisan::call('migrate', ['--path' => $path, '--realpath' => true, '--force' => true]);
    }

    private function rollbackMigrations(string $slug): void
    {
        $path = $this->migrationsPath($slug);
        if (! is_dir($path)) {
            return;
        }
        // migrate:reset (NOT :rollback) so ALL of a module's migrations are reversed on remove, regardless of
        // how many BATCHES they ran across (initial enable + later upgrades). :rollback reverses only the last
        // batch, which would strand the tables from earlier batches — the ADR-0031 batch-semantics flag (H4).
        Artisan::call('migrate:reset', ['--path' => $path, '--realpath' => true, '--force' => true]);
    }

    /** @return array<string,mixed> */
    private function metaFor(ModuleManifest $manifest): array
    {
        return [
            'description' => $manifest->description,
            'author' => $manifest->author,
            'provider' => $manifest->provider,
            'namespace' => $manifest->namespace,
            'provides' => $manifest->provides,
        ];
    }

    private function phpVersion(): string
    {
        return PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION.'.'.PHP_RELEASE_VERSION;
    }
}
