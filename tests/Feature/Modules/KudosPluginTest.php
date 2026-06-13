<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Content\ContentRenderer;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Permission;
use App\Models\User;
use App\Modules\ModuleLoader;
use App\Modules\ModuleManager;
use App\Modules\SlotRegistry;
use App\Permissions\PermissionResolver;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use App\Theme\WidgetRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Users;

/*
| DOGFOOD (D1): the first-party Kudos plugin (modules/novfora/kudos) — a SECOND plugin proving the contract,
| including the B2 layout-WIDGET seam (a module contributes a widget like a built-in) and the 'widgets'
| manifest capability added in the dogfood. Built with zero core edits.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    SettingsRegistry::flushRuntime();
});

afterEach(fn () => SettingsRegistry::flushRuntime());

function enableKudos(): void
{
    $manager = app(ModuleManager::class);
    $manager->install('novfora/kudos');
    $manager->enable('novfora/kudos', acknowledgeTrust: true);
    app(ModuleLoader::class)->boot(app());
}

function kudosAllow(User $user, string $permission): void
{
    AclEntry::create([
        'permission_key' => $permission, 'holder_type' => 'user', 'holder_id' => $user->getKey(),
        'scope_type' => 'global', 'scope_id' => null, 'value' => 1, // ALLOW
    ]);
    app(PermissionResolver::class)->flushMemo();
}

it('enables through the contract — migration, permission, setting, and a module-registered widget', function () {
    enableKudos();

    expect(Schema::hasTable('kudos'))->toBeTrue()
        ->and(Permission::where('key', 'novfora.kudos.give')->exists())->toBeTrue()
        ->and(SettingsRegistry::has('kudos.glyph'))->toBeTrue()
        ->and(app(WidgetRegistry::class)->has('kudos'))->toBeTrue();                    // module widget registered
    expect(app(WidgetRegistry::class)->get('kudos')?->render([]))->toContain('Kudos');  // …and renders
});

it('replaces the [kudos] shortcode via the post.html filter, honouring its setting', function () {
    enableKudos();
    app(Settings::class)->set('kudos.glyph', 'YAY');

    $html = app(ContentRenderer::class)->render('markdown', ['source' => 'nice [kudos] work'])['html'];
    expect($html)->toContain('YAY')->not->toContain('[kudos]');
});

it('gates the give-kudos route, dedups one per user per post, and totals it in the footer slot', function () {
    enableKudos();
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'k', 'title' => 'K', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'T', 'markdown', ['source' => 'op']);
    $post = $topic->posts()->orderBy('id')->first();

    // No permission → forbidden, nothing recorded.
    $this->actingAs($author)->post('/kudos/give', ['post_id' => $post->id])->assertForbidden();
    expect(DB::table('kudos')->count())->toBe(0);

    // Grant → recorded once; a repeat is deduped by the unique(post_id,user_id).
    kudosAllow($author, 'novfora.kudos.give');
    $this->actingAs($author)->post('/kudos/give', ['post_id' => $post->id])->assertRedirect();
    $this->actingAs($author)->post('/kudos/give', ['post_id' => $post->id])->assertRedirect();
    expect(DB::table('kudos')->count())->toBe(1);

    // The footer slot reflects the community total (sanitised module output).
    expect(app(SlotRegistry::class)->render('footer.widgets'))->toContain('Kudos given: 1');
});
