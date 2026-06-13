<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentRenderer;
use App\Forum\PostService;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\Module;
use App\Models\Permission;
use App\Modules\ModuleException;
use App\Modules\ModuleLoader;
use App\Modules\ModuleManager;
use App\Modules\SlotRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Users;

/*
| The module/plugin lifecycle (ADR-0031, apex). The happy path drives the REAL first-party example plugin
| (modules/novfora/hello) end-to-end as a living test of the contract; the adversarial cases (incompatible API,
| missing dependency, core-permission collision) drive fixtures under tests/Fixtures/modules. Security posture:
| compat + deps are checked BEFORE enable; a module can never redefine a core permission; removal cleans up its
| migrations + its owned permission grants.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function bootEnabledModules(): void
{
    app(ModuleLoader::class)->boot(app());
}

it('drives the example plugin install → enable → exercise → disable → remove', function () {
    $manager = app(ModuleManager::class);

    // discover() finds the shipped first-party example.
    $slugs = array_map(fn ($m) => $m->slug, $manager->discover());
    expect($slugs)->toContain('novfora/hello');

    // install records it (disabled); enable runs its migration + registers its permission.
    $manager->install('novfora/hello');
    $manager->enable('novfora/hello');

    $module = Module::where('slug', 'novfora/hello')->firstOrFail();
    expect($module->enabled)->toBeTrue()
        ->and(Schema::hasTable('hello_greetings'))->toBeTrue()
        ->and(Permission::where('key', 'novfora.hello.manage')->exists())->toBeTrue()
        ->and($module->permission_keys)->toContain('novfora.hello.manage')
        ->and(AuditLog::where('action', 'module.enabled')->exists())->toBeTrue();

    // Boot the loader (simulating the next request) → the provider wires its listener / filter / slot / route.
    bootEnabledModules();

    // (1) Domain-event listener: a new post records a greeting.
    $forum = Forum::create(['slug' => 'mods', 'title' => 'Mods', 'type' => 'forum']);
    $author = Users::inGroups(['members', 'tl1']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'A topic', 'markdown', ['source' => 'hi']);
    app(PostService::class)->reply($author, $topic, 'markdown', ['source' => 'a reply']);
    expect(DB::table('hello_greetings')->count())->toBeGreaterThanOrEqual(1);

    // (2) Filter hook: rendered post HTML carries the module's marker (re-sanitised by core).
    expect(app(ContentRenderer::class)->render('markdown', ['source' => 'hello'])['html'])
        ->toContain('hello-greeting');

    // (3) UI slot: the footer widget renders (sanitised).
    expect(app(SlotRegistry::class)->render('footer.widgets'))->toContain('hello-widget');

    // (4) Route registered by the module (checked by URI — a runtime-added named route doesn't refresh the
    //     router's name lookup, but it IS in the collection).
    expect(collect(Route::getRoutes()->getRoutes())->contains(fn ($r) => $r->uri() === 'hello'))->toBeTrue();

    // disable is non-destructive — the schema + data stay for a clean re-enable.
    $manager->disable('novfora/hello');
    expect(Module::where('slug', 'novfora/hello')->firstOrFail()->enabled)->toBeFalse()
        ->and(Schema::hasTable('hello_greetings'))->toBeTrue();

    // remove rolls back the migration and drops the module-owned permission (no dangling catalog/ACL).
    $manager->remove('novfora/hello');
    expect(Module::where('slug', 'novfora/hello')->exists())->toBeFalse()
        ->and(Schema::hasTable('hello_greetings'))->toBeFalse()
        ->and(Permission::where('key', 'novfora.hello.manage')->exists())->toBeFalse()
        ->and(AuditLog::where('action', 'module.removed')->exists())->toBeTrue();
});

it('refuses to install a module that targets an incompatible API major', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);

    expect(fn () => app(ModuleManager::class)->install('test/incompatible'))
        ->toThrow(ModuleException::class, 'incompatible');
    expect(Module::where('slug', 'test/incompatible')->exists())->toBeFalse();
});

it('refuses to enable a module whose dependency is not installed and enabled', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);
    $manager = app(ModuleManager::class);
    $manager->install('test/needs-dep'); // install is fine — the API major is compatible

    expect(fn () => $manager->enable('test/needs-dep'))
        ->toThrow(ModuleException::class, 'installed and enabled');
    expect(Module::where('slug', 'test/needs-dep')->firstOrFail()->enabled)->toBeFalse();
});

it('refuses to enable a module that tries to redefine a core permission key', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);
    $manager = app(ModuleManager::class);
    $manager->install('test/collide');

    expect(fn () => $manager->enable('test/collide'))
        ->toThrow(ModuleException::class, 'redefine the core permission');
    expect(Module::where('slug', 'test/collide')->firstOrFail()->enabled)->toBeFalse();
});
