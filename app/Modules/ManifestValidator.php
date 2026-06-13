<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

/**
 * Strict, fail-closed validation of an untrusted `module.json` (ADR-0031, apex). A plugin is a LOCAL package
 * an admin installs — but its manifest is still parsed input, and it is the surface that decides where code is
 * loaded from, what provider class runs, and what permission keys enter the catalog. So every field is
 * validated for shape AND for safety BEFORE any of it is trusted:
 *
 *   - slug is a strict `vendor/name` of lowercase tokens — no `.`/`..`/slashes/whitespace, so it can never be
 *     used to escape `modules/` (path traversal) when the manager resolves the directory;
 *   - namespace (if present) is PSR-4-shaped and is REFUSED if it targets a core/framework root (App\,
 *     Illuminate\, …), so a module can never shadow a core class by autoload precedence;
 *   - provider (if present) MUST live inside the module's own namespace, so a manifest can't nominate an
 *     arbitrary existing class to instantiate;
 *   - version / api_version / every requires-constraint must parse (SemverConstraint), so a compatibility
 *     verdict is never silently wrong;
 *   - declared permission keys are well-formed dotted, lowercase keys with a valid scope kind (collision with
 *     a CORE key is refused later, at enable time, where the catalog is known).
 *
 * Anything unexpected throws ModuleException with an operator-facing message; nothing is coerced silently.
 */
final class ManifestValidator
{
    private const MANIFEST_FILE = 'module.json';

    private const SCOPE_KINDS = ['global', 'category', 'forum', 'thread'];

    private const KNOWN_PROVIDES = ['routes', 'listeners', 'filters', 'slots', 'widgets', 'permissions', 'settings', 'migrations', 'commands', 'schedule'];

    /** Reserved namespace roots a module may never claim (it would shadow core/framework code). */
    private const RESERVED_NAMESPACES = ['App', 'Illuminate', 'Symfony', 'Laravel', 'Database', 'Tests', 'Livewire', 'Composer'];

    /** Read + validate `<dir>/module.json`. The directory's own name is cross-checked against the slug. */
    public function fromDirectory(string $dir): ModuleManifest
    {
        $path = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.self::MANIFEST_FILE;
        if (! is_file($path)) {
            throw new ModuleException('No '.self::MANIFEST_FILE." found in {$dir}.");
        }
        $json = file_get_contents($path);
        if ($json === false) {
            throw new ModuleException("Could not read {$path}.");
        }

        $expectedSlug = $this->slugFromDirectory($dir);

        return $this->fromJson($json, $expectedSlug);
    }

    public function fromJson(string $json, ?string $expectedSlug = null): ModuleManifest
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ModuleException('The module manifest is not valid JSON: '.$e->getMessage());
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new ModuleException('The module manifest must be a JSON object.');
        }

        return $this->fromArray($data, $expectedSlug);
    }

    /**
     * @param  array<mixed>  $data
     */
    public function fromArray(array $data, ?string $expectedSlug = null): ModuleManifest
    {
        $slug = $this->assertSlug($this->string($data, 'slug', required: true));
        if ($expectedSlug !== null && $slug !== $expectedSlug) {
            throw new ModuleException("Manifest slug '{$slug}' does not match its directory ('{$expectedSlug}').");
        }
        $vendor = explode('/', $slug)[0];

        $name = $this->boundedString($data, 'name', max: 100, required: true);
        $version = $this->string($data, 'version', required: true);
        if (! SemverConstraint::isValidVersion($version)) {
            throw new ModuleException("Manifest 'version' must be a plain x.y.z version, got '{$version}'.");
        }
        $apiVersion = $this->string($data, 'api_version', required: true);
        if (! SemverConstraint::isValidConstraint($apiVersion)) {
            throw new ModuleException("Manifest 'api_version' is not a supported constraint: '{$apiVersion}'.");
        }

        $namespace = $this->validateNamespace($this->boundedString($data, 'namespace', max: 200, required: false));
        $provider = $this->validateProvider($this->boundedString($data, 'provider', max: 255, required: false), $namespace);

        return new ModuleManifest(
            slug: $slug,
            vendor: $vendor,
            name: $name,
            version: $version,
            apiVersion: $apiVersion,
            description: $this->boundedString($data, 'description', max: 500, required: false),
            author: $this->boundedString($data, 'author', max: 100, required: false),
            namespace: $namespace,
            provider: $provider,
            requiresPhp: $this->validatePhpConstraint($data),
            requiresModules: $this->validateRequiredModules($data),
            permissions: $this->validatePermissions($data),
            provides: $this->validateProvides($data),
            raw: $data,
        );
    }

    /**
     * Assert a slug is a path-safe `vendor/name` — each segment a lowercase token of [a-z0-9] joined by single
     * hyphens, with exactly one '/'. PUBLIC because it is also the boundary guard for the lifecycle `$slug`
     * parameter (ModuleManager::dirFor): a slug that never reaches a manifest must still be proven safe before
     * it is concatenated into a filesystem / migration path, so it can never carry `..`, extra slashes, or
     * other traversal. Throws ModuleException otherwise.
     */
    public function assertSlug(string $slug): string
    {
        $token = '[a-z0-9]+(?:-[a-z0-9]+)*';
        if (! preg_match('#^'.$token.'/'.$token.'$#', $slug)) {
            throw new ModuleException("Module slug must be 'vendor/name' of lowercase tokens, got '{$slug}'.");
        }

        return $slug;
    }

    private function validateNamespace(?string $namespace): ?string
    {
        if ($namespace === null) {
            return null;
        }
        $ns = rtrim($namespace, '\\').'\\';
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\$/', $ns)) {
            throw new ModuleException("Manifest 'namespace' is not a valid PSR-4 namespace: '{$namespace}'.");
        }
        $root = explode('\\', $ns)[0];
        if (in_array($root, self::RESERVED_NAMESPACES, true)) {
            throw new ModuleException("Manifest 'namespace' may not start with the reserved root '{$root}\\'.");
        }

        return $ns;
    }

    private function validateProvider(?string $provider, ?string $namespace): ?string
    {
        if ($provider === null) {
            return null;
        }
        if ($namespace === null) {
            throw new ModuleException("Manifest declares a 'provider' but no 'namespace' to load it from.");
        }
        $fqcn = ltrim($provider, '\\');
        if (! str_starts_with($fqcn.'\\', $namespace)) {
            throw new ModuleException("Manifest 'provider' must live inside the module namespace '{$namespace}'.");
        }
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $fqcn)) {
            throw new ModuleException("Manifest 'provider' is not a valid class name: '{$provider}'.");
        }

        return $fqcn;
    }

    private function validatePhpConstraint(array $data): ?string
    {
        $requires = $data['requires'] ?? null;
        if ($requires === null) {
            return null;
        }
        if (! is_array($requires) || array_is_list($requires)) {
            throw new ModuleException("Manifest 'requires' must be an object.");
        }
        $php = $requires['php'] ?? null;
        if ($php === null) {
            return null;
        }
        if (! is_string($php) || ! SemverConstraint::isValidConstraint($php)) {
            throw new ModuleException("Manifest 'requires.php' is not a supported constraint.");
        }

        return $php;
    }

    /** @return array<string,string> slug => constraint */
    private function validateRequiredModules(array $data): array
    {
        $requires = $data['requires'] ?? null;
        if (! is_array($requires)) {
            return [];
        }
        $modules = $requires['modules'] ?? null;
        if ($modules === null) {
            return [];
        }
        if (! is_array($modules) || array_is_list($modules)) {
            throw new ModuleException("Manifest 'requires.modules' must be an object of slug => constraint.");
        }

        $out = [];
        foreach ($modules as $slug => $constraint) {
            if (! is_string($slug)) {
                throw new ModuleException("Manifest 'requires.modules' has a non-string dependency slug.");
            }
            $this->assertSlug($slug);
            if (! is_string($constraint) || ! SemverConstraint::isValidConstraint($constraint)) {
                throw new ModuleException("Manifest dependency '{$slug}' has an unsupported version constraint.");
            }
            $out[$slug] = $constraint;
        }

        return $out;
    }

    /** @return list<array{key:string,label:string,scope_kind:string,group:string,description:string}> */
    private function validatePermissions(array $data): array
    {
        $permissions = $data['permissions'] ?? null;
        if ($permissions === null) {
            return [];
        }
        if (! is_array($permissions) || ! array_is_list($permissions)) {
            throw new ModuleException("Manifest 'permissions' must be a JSON array.");
        }

        $out = [];
        $seen = [];
        foreach ($permissions as $i => $perm) {
            if (! is_array($perm) || array_is_list($perm)) {
                throw new ModuleException("Manifest permission #{$i} must be an object.");
            }
            $key = $this->string($perm, 'key', required: true);
            if (! preg_match('/^[a-z][a-z0-9]*(\.[a-z0-9][a-z0-9_-]*)+$/', $key) || strlen($key) > 150) {
                throw new ModuleException("Manifest permission key '{$key}' is not a valid dotted lowercase key.");
            }
            if (isset($seen[$key])) {
                throw new ModuleException("Manifest declares permission key '{$key}' more than once.");
            }
            $seen[$key] = true;
            $scopeKind = $this->boundedString($perm, 'scope_kind', max: 20, required: false) ?? 'global';
            if (! in_array($scopeKind, self::SCOPE_KINDS, true)) {
                throw new ModuleException("Manifest permission '{$key}' has an invalid scope_kind '{$scopeKind}'.");
            }
            $out[] = [
                'key' => $key,
                'label' => $this->boundedString($perm, 'label', max: 150, required: false) ?? $key,
                'scope_kind' => $scopeKind,
                'group' => $this->boundedString($perm, 'group', max: 60, required: false) ?? 'Modules',
                'description' => $this->boundedString($perm, 'description', max: 255, required: false) ?? '',
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function validateProvides(array $data): array
    {
        $provides = $data['provides'] ?? null;
        if ($provides === null) {
            return [];
        }
        if (! is_array($provides) || ! array_is_list($provides)) {
            throw new ModuleException("Manifest 'provides' must be a JSON array of strings.");
        }
        $out = [];
        foreach ($provides as $item) {
            if (! is_string($item) || ! in_array($item, self::KNOWN_PROVIDES, true)) {
                throw new ModuleException("Manifest 'provides' contains an unknown capability.");
            }
            $out[] = $item;
        }

        return array_values(array_unique($out));
    }

    private function slugFromDirectory(string $dir): string
    {
        $normalized = str_replace('\\', '/', rtrim($dir, '/\\'));
        $parts = explode('/', $normalized);
        $name = array_pop($parts); // explode() guarantees at least one element
        $vendor = array_pop($parts) ?? '';

        return "{$vendor}/{$name}";
    }

    /** @param array<mixed> $data */
    private function string(array $data, string $key, bool $required): string
    {
        $value = $data[$key] ?? null;
        if ($value === null) {
            if ($required) {
                throw new ModuleException("Manifest is missing required field '{$key}'.");
            }

            return '';
        }
        if (! is_string($value)) {
            throw new ModuleException("Manifest field '{$key}' must be a string.");
        }
        $trimmed = trim($value);
        if ($required && $trimmed === '') {
            throw new ModuleException("Manifest field '{$key}' must not be empty.");
        }

        return $trimmed;
    }

    /** @param array<mixed> $data */
    private function boundedString(array $data, string $key, int $max, bool $required): ?string
    {
        $value = $this->string($data, $key, $required);
        if ($value === '' && ! $required) {
            return null;
        }
        if (strlen($value) > $max) {
            throw new ModuleException("Manifest field '{$key}' exceeds {$max} characters.");
        }

        return $value;
    }
}
