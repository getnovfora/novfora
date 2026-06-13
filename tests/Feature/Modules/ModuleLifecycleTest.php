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
    $manager->enable('novfora/hello', acknowledgeTrust: true); // explicit full-trust consent (H3 guardrail)

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

    expect(fn () => $manager->enable('test/needs-dep', acknowledgeTrust: true))
        ->toThrow(ModuleException::class, 'installed and enabled');
    expect(Module::where('slug', 'test/needs-dep')->firstOrFail()->enabled)->toBeFalse();
});

it('refuses to enable a module that tries to redefine a core permission key', function () {
    config(['novfora.modules.path' => base_path('tests/Fixtures/modules')]);
    $manager = app(ModuleManager::class);
    $manager->install('test/collide');

    expect(fn () => $manager->enable('test/collide', acknowledgeTrust: true))
        ->toThrow(ModuleException::class, 'redefine the core permission');
    expect(Module::where('slug', 'test/collide')->firstOrFail()->enabled)->toBeFalse();
});

it('refuses a lifecycle slug that attempts path traversal (boundary guard, adversarial review fix)', function () {
    $manager = app(ModuleManager::class);
    foreach (['a/../../etc', '../../evil', 'novfora/hello/../../../tmp', 'a/b/c', 'UPPER/case', 'has space/x'] as $slug) {
        // The slug never reaches the filesystem: dirFor() asserts it, so install() refuses before any read.
        expect(fn () => $manager->dirFor($slug))->toThrow(ModuleException::class);
        expect(fn () => $manager->install($slug))->toThrow(ModuleException::class);
    }
    expect(Module::count())->toBe(0); // nothing was installed from a traversal attempt
});

it('refuses an upgrade that would downgrade the recorded version (monotonic guard)', function () {
    $manager = app(ModuleManager::class);
    $manager->install('novfora/hello'); // on-disk manifest version is 1.0.0
    Module::where('slug', 'novfora/hello')->update(['version' => '2.0.0']); // pretend a newer build was installed

    expect(fn () => $manager->upgrade('novfora/hello'))
        ->toThrow(ModuleException::class, 'older than');
});

it('reverses a module\'s migrations across MULTIPLE batches on remove (H4 — migrate:reset)', function () {
    // A self-contained temp module (parallel-safe). It runs one migration on enable (batch 1) and a second on
    // a later upgrade (batch 2); remove must reverse BOTH — migrate:rollback would strand the batch-1 table.
    $base = sys_get_temp_dir().'/novfora-mb-'.bin2hex(random_bytes(4));
    $dir = $base.'/acme/multi';
    @mkdir($dir.'/database/migrations', 0777, true);

    $manifest = fn (string $version): string => (string) json_encode([
        'name' => 'Multi', 'slug' => 'acme/multi', 'version' => $version, 'api_version' => '^1.0',
    ]);
    $migration = fn (string $table): string => <<<PHP
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Database\Schema\Blueprint;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('{$table}', function (Blueprint \$t) {
                    \$t->id();
                });
            }

            public function down(): void
            {
                Schema::dropIfExists('{$table}');
            }
        };
        PHP;

    file_put_contents($dir.'/module.json', $manifest('1.0.0'));
    file_put_contents($dir.'/database/migrations/2026_01_01_000001_create_mb_alpha.php', $migration('mb_alpha'));
    config(['novfora.modules.path' => $base]);

    try {
        $manager = app(ModuleManager::class);
        $manager->install('acme/multi');
        $manager->enable('acme/multi', acknowledgeTrust: true);   // batch 1 → mb_alpha
        expect(Schema::hasTable('mb_alpha'))->toBeTrue();

        // Ship a second migration + a version bump, then upgrade → batch 2 → mb_beta.
        file_put_contents($dir.'/database/migrations/2026_01_01_000002_create_mb_beta.php', $migration('mb_beta'));
        file_put_contents($dir.'/module.json', $manifest('1.1.0'));
        $manager->upgrade('acme/multi');
        expect(Schema::hasTable('mb_beta'))->toBeTrue();

        $manager->remove('acme/multi');
        expect(Schema::hasTable('mb_alpha'))->toBeFalse()  // batch 1 reversed too (the fix)
            ->and(Schema::hasTable('mb_beta'))->toBeFalse();
    } finally {
        @unlink($dir.'/database/migrations/2026_01_01_000001_create_mb_alpha.php');
        @unlink($dir.'/database/migrations/2026_01_01_000002_create_mb_beta.php');
        @unlink($dir.'/module.json');
        @rmdir($dir.'/database/migrations');
        @rmdir($dir.'/database');
        @rmdir($dir);
        @rmdir($base.'/acme');
        @rmdir($base);
        Schema::dropIfExists('mb_alpha'); // belt-and-suspenders if an assertion above failed mid-way
        Schema::dropIfExists('mb_beta');
    }
});
