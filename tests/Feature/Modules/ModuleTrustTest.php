<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Module;
use App\Modules\ModuleException;
use App\Modules\ModuleLoader;
use App\Modules\ModuleManager;
use App\Modules\SlotRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
| Plugin trust guardrails (ADR-0031 H3, apex). A module runs with FULL server trust, so these are the explicit,
| audited safety rails around that fact: an admin must CONSENT before a first enable; the package carries an
| INTEGRITY hash that flags files changed since the admin blessed it; a module that throws while loading is
| auto-DISABLED (quarantined) instead of white-screening the site; and a file-based KILL SWITCH loads nothing.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

it('refuses to enable a module without explicit full-trust consent, then enables with it', function () {
    $manager = app(ModuleManager::class);
    $manager->install('novfora/hello');

    // No consent → refused, with no side effects.
    expect(fn () => $manager->enable('novfora/hello'))
        ->toThrow(ModuleException::class, 'full server trust');
    expect(Module::where('slug', 'novfora/hello')->firstOrFail()->enabled)->toBeFalse();

    // Explicit acknowledgement → enabled, and the consent is recorded.
    $manager->enable('novfora/hello', acknowledgeTrust: true);
    $module = Module::where('slug', 'novfora/hello')->firstOrFail();
    expect($module->enabled)->toBeTrue()
        ->and($module->consented_at)->not->toBeNull();

    // Re-enabling after a disable does NOT re-prompt — consent is recorded once per module.
    $manager->disable('novfora/hello');
    $manager->enable('novfora/hello');
    expect(Module::where('slug', 'novfora/hello')->firstOrFail()->enabled)->toBeTrue();
});

it('records a package integrity hash and flags modified files', function () {
    // A self-contained throwaway module under a temp path (parallel-safe — never touches a shared fixture).
    $base = sys_get_temp_dir().'/novfora-mod-'.bin2hex(random_bytes(4));
    $dir = $base.'/acme/widget';
    @mkdir($dir.'/src', 0777, true);
    file_put_contents($dir.'/module.json', (string) json_encode([
        'name' => 'Widget', 'slug' => 'acme/widget', 'version' => '1.0.0', 'api_version' => '^1.0',
    ]));
    file_put_contents($dir.'/src/Thing.php', "<?php\nnamespace Acme\\Widget;\nclass Thing {}\n");
    config(['novfora.modules.path' => $base]);

    try {
        $manager = app(ModuleManager::class);
        $manager->install('acme/widget');
        expect($manager->integrityStatus('acme/widget'))->toBe('verified')
            ->and(Module::where('slug', 'acme/widget')->firstOrFail()->package_hash)->not->toBeNull();

        // Tamper with a package file → integrity flips to 'modified'.
        file_put_contents($dir.'/src/Thing.php', "<?php\nnamespace Acme\\Widget;\nclass Thing { public int \$x = 1; }\n");
        expect($manager->integrityStatus('acme/widget'))->toBe('modified');
    } finally {
        @unlink($dir.'/src/Thing.php');
        @unlink($dir.'/module.json');
        @rmdir($dir.'/src');
        @rmdir($dir);
        @rmdir($base.'/acme');
        @rmdir($base);
    }
});

it('quarantines a module whose provider throws while loading (disable-on-fatal)', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);
    $manager = app(ModuleManager::class);
    $manager->install('test/faulty');
    $manager->enable('test/faulty', acknowledgeTrust: true);
    expect(Module::where('slug', 'test/faulty')->firstOrFail()->enabled)->toBeTrue();

    // Booting the loader registers the provider, which throws — the loader must quarantine it, not fatal.
    app(ModuleLoader::class)->boot(app());

    $module = Module::where('slug', 'test/faulty')->firstOrFail();
    expect($module->enabled)->toBeFalse()
        ->and($module->failed_at)->not->toBeNull()
        ->and($module->last_error)->toContain('boom')
        ->and(AuditLog::where('action', 'module.quarantined')->exists())->toBeTrue();
});

it('the kill switch (safe mode) loads no modules even when one is enabled', function () {
    // Isolate the marker to a temp path so the switch can never leak into another test.
    $marker = sys_get_temp_dir().'/novfora-safe-'.bin2hex(random_bytes(4));
    config(['novfora.modules.safe_mode_marker' => $marker]);
    $manager = app(ModuleManager::class);
    $manager->install('novfora/hello');
    $manager->enable('novfora/hello', acknowledgeTrust: true);

    try {
        $manager->engageSafeMode();
        expect($manager->safeMode())->toBeTrue();

        // With safe mode engaged, booting the loader registers no module providers — the hello slot is absent.
        app(ModuleLoader::class)->boot(app());
        expect(app(SlotRegistry::class)->render('footer.widgets'))->not->toContain('hello-widget');

        $manager->releaseSafeMode();
        expect($manager->safeMode())->toBeFalse();
    } finally {
        @unlink($marker);
    }
});
