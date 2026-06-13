<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Modules;

/**
 * An immutable, ALREADY-VALIDATED module manifest (ADR-0031). The only way to obtain one is through
 * ManifestValidator, so any code holding a ModuleManifest can trust every field's shape: the slug is a safe
 * `vendor/name` (no path traversal), the version/api-constraint parse, the namespace (if any) is non-core and
 * PSR-4-shaped, and the declared permission keys are well-formed. The raw decoded document is retained for the
 * ACP to display, never re-parsed for behaviour.
 */
final readonly class ModuleManifest
{
    /**
     * @param  array<string,string>  $requiresModules  slug => version constraint
     * @param  list<array{key:string,label:string,scope_kind:string,group:string,description:string}>  $permissions
     * @param  list<string>  $provides
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $slug,
        public string $vendor,
        public string $name,
        public string $version,
        public string $apiVersion,
        public ?string $description,
        public ?string $author,
        public ?string $namespace,
        public ?string $provider,
        public ?string $requiresPhp,
        public array $requiresModules,
        public array $permissions,
        public array $provides,
        public array $raw,
    ) {}

    /** The permission KEYS this module declares (the catalog entries registered on enable). @return list<string> */
    public function permissionKeys(): array
    {
        return array_map(static fn (array $p): string => $p['key'], $this->permissions);
    }
}
