<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Modules\ManifestValidator;
use App\Modules\ModuleException;
use App\Modules\ModuleManifest;

/*
| ADR-0031 apex boundary: a module.json is untrusted input. These pins are adversarial — a malformed or
| hostile manifest must be REFUSED with a clear message, never coerced. Path traversal in the slug, a
| core/framework namespace, a provider outside the module's namespace, an unparseable version/constraint, and
| malformed permission keys are the boundary's job to reject.
*/

function validManifest(array $overrides = []): array
{
    return array_replace([
        'name' => 'Hello World',
        'slug' => 'novfora/hello',
        'version' => '1.2.0',
        'api_version' => '^1.0',
    ], $overrides);
}

it('accepts a well-formed full manifest', function () {
    $m = (new ManifestValidator)->fromArray(validManifest([
        'description' => 'A friendly example.',
        'author' => 'NovFora',
        'namespace' => 'Modules\\Novfora\\Hello\\',
        'provider' => 'Modules\\Novfora\\Hello\\HelloServiceProvider',
        'requires' => ['php' => '>=8.3', 'modules' => ['acme/core' => '^1.0']],
        'permissions' => [['key' => 'novfora.hello.manage', 'label' => 'Manage hello', 'scope_kind' => 'global']],
        'provides' => ['routes', 'listeners', 'filters', 'slots', 'permissions', 'migrations'],
    ]));

    expect($m)->toBeInstanceOf(ModuleManifest::class)
        ->and($m->slug)->toBe('novfora/hello')
        ->and($m->vendor)->toBe('novfora')
        ->and($m->version)->toBe('1.2.0')
        ->and($m->namespace)->toBe('Modules\\Novfora\\Hello\\')
        ->and($m->provider)->toBe('Modules\\Novfora\\Hello\\HelloServiceProvider')
        ->and($m->requiresPhp)->toBe('>=8.3')
        ->and($m->requiresModules)->toBe(['acme/core' => '^1.0'])
        ->and($m->permissionKeys())->toBe(['novfora.hello.manage']);
});

it('accepts a minimal manifest (just the four required fields)', function () {
    $m = (new ManifestValidator)->fromArray(validManifest());
    expect($m->name)->toBe('Hello World')->and($m->permissions)->toBe([])->and($m->provider)->toBeNull();
});

it('rejects a missing required field', function () {
    foreach (['name', 'slug', 'version', 'api_version'] as $field) {
        $data = validManifest();
        unset($data[$field]);
        expect(fn () => (new ManifestValidator)->fromArray($data))
            ->toThrow(ModuleException::class, 'required');
    }
});

it('rejects a non-string field', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest(['slug' => 123])))
        ->toThrow(ModuleException::class, 'must be a string');
});

it('rejects a slug with path traversal or unsafe characters', function () {
    foreach (['../../etc', 'novfora/../evil', 'Novfora/Hello', 'novfora\\hello', 'novfora/hello/extra', 'no spaces/here'] as $slug) {
        expect(fn () => (new ManifestValidator)->fromArray(validManifest(['slug' => $slug])))
            ->toThrow(ModuleException::class);
    }
});

it('rejects a slug that does not match its directory', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest(['slug' => 'novfora/hello']), 'acme/other'))
        ->toThrow(ModuleException::class, 'does not match its directory');
});

it('rejects an unparseable version and api_version', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest(['version' => '1.x'])))
        ->toThrow(ModuleException::class, 'version');
    expect(fn () => (new ManifestValidator)->fromArray(validManifest(['api_version' => 'not-a-constraint'])))
        ->toThrow(ModuleException::class, 'constraint');
});

it('refuses a namespace that targets a core or framework root', function () {
    foreach (['App\\Evil\\', 'Illuminate\\Foo\\', 'Database\\Bar\\', 'Tests\\X\\'] as $ns) {
        expect(fn () => (new ManifestValidator)->fromArray(validManifest(['namespace' => $ns])))
            ->toThrow(ModuleException::class, 'reserved');
    }
});

it('refuses a reserved root regardless of case (PHP class resolution is case-insensitive) — P5.1', function () {
    // `app\Foo` resolves to the same class as `App\Foo`, so the guard must be case-insensitive.
    foreach (['app\\Evil\\', 'illuminate\\Foo\\', 'LARAVEL\\Bar\\', 'liVeWire\\X\\'] as $ns) {
        expect(fn () => (new ManifestValidator)->fromArray(validManifest(['namespace' => $ns])))
            ->toThrow(ModuleException::class, 'reserved');
    }
});

it('refuses a provider outside the module namespace, or with no namespace', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'namespace' => 'Modules\\Novfora\\Hello\\',
        'provider' => 'App\\Providers\\AppServiceProvider', // a real core class — must be refused
    ])))->toThrow(ModuleException::class, 'inside the module namespace');

    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'provider' => 'Modules\\X\\Provider', // provider but no namespace declared
    ])))->toThrow(ModuleException::class, 'no');
});

it('rejects malformed permission entries', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'permissions' => [['key' => 'NotLowercase']],
    ])))->toThrow(ModuleException::class, 'permission key');

    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'permissions' => [['key' => 'novfora.hello.x', 'scope_kind' => 'galaxy']],
    ])))->toThrow(ModuleException::class, 'scope_kind');

    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'permissions' => [['key' => 'a.b'], ['key' => 'a.b']], // duplicate
    ])))->toThrow(ModuleException::class, 'more than once');
});

it('rejects an unknown provides capability and a bad dependency constraint', function () {
    expect(fn () => (new ManifestValidator)->fromArray(validManifest(['provides' => ['routes', 'mine-the-bitcoin']])))
        ->toThrow(ModuleException::class, 'capability');

    expect(fn () => (new ManifestValidator)->fromArray(validManifest([
        'requires' => ['modules' => ['acme/core' => 'banana']],
    ])))->toThrow(ModuleException::class, 'constraint');
});

it('rejects non-object and non-JSON manifests', function () {
    expect(fn () => (new ManifestValidator)->fromJson('[1,2,3]'))
        ->toThrow(ModuleException::class, 'object');
    expect(fn () => (new ManifestValidator)->fromJson('{not json'))
        ->toThrow(ModuleException::class, 'valid JSON');
});
