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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Users;

/*
| DOGFOOD (D1): the first-party Q&A plugin (modules/novfora/qa) built PURELY via the public module contract —
| zero core edits beyond the additive API 1.1 extension points it surfaced (the topic.post.aside slot + the
| SettingsRegistry::register path). It exercises every seam: a plugin migration + permission, a plugin SETTING,
| a domain-event listener, a post.html FILTER, a UI SLOT, and CSRF-guarded ROUTES gated by the permission.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed();
    SettingsRegistry::flushRuntime();
});

afterEach(fn () => SettingsRegistry::flushRuntime());

function enableQa(): void
{
    $manager = app(ModuleManager::class);
    $manager->install('novfora/qa');
    $manager->enable('novfora/qa', acknowledgeTrust: true);
    app(ModuleLoader::class)->boot(app());                 // register the provider's seams (next-request sim)
}

function grantGlobally(User $user, string $permission): void
{
    AclEntry::create([
        'permission_key' => $permission, 'holder_type' => 'user', 'holder_id' => $user->getKey(),
        'scope_type' => 'global', 'scope_id' => null, 'value' => 1, // ALLOW
    ]);
    app(PermissionResolver::class)->flushMemo();
}

it('enables purely through the contract — migration, permission, and a plugin setting', function () {
    expect(array_map(fn ($m) => $m->slug, app(ModuleManager::class)->discover()))->toContain('novfora/qa');

    enableQa();

    expect(Schema::hasTable('qa_accepted_answers'))->toBeTrue()                       // plugin migration ran
        ->and(Permission::where('key', 'novfora.qa.accept')->exists())->toBeTrue()    // permission registered
        ->and(SettingsRegistry::has('qa.callout_enabled'))->toBeTrue()                // plugin setting registered
        ->and(app(Settings::class)->bool('qa.callout_enabled'))->toBeTrue();          // …and resolves (default)
});

it('applies the [answer] content filter only while its plugin setting is on', function () {
    enableQa();

    $on = app(ContentRenderer::class)->render('markdown', ['source' => 'Try [answer]do the thing[/answer] now'])['html'];
    expect($on)->toContain('qa-callout')->toContain('do the thing');

    app(Settings::class)->set('qa.callout_enabled', false);
    $off = app(ContentRenderer::class)->render('markdown', ['source' => 'Try [answer]do the thing[/answer] now'])['html'];
    expect($off)->not->toContain('qa-callout');
});

it('gates the accept action on the permission and badges the accepted post in the slot', function () {
    enableQa();
    $author = Users::inGroups(['members', 'tl1']);
    $forum = Forum::create(['slug' => 'qa', 'title' => 'QA', 'type' => 'forum']);
    $topic = app(PostService::class)->createTopic($author, $forum, 'How?', 'markdown', ['source' => 'a question']);
    $answer = app(PostService::class)->reply($author, $topic, 'markdown', ['source' => 'the answer']);

    // Without the permission the route is forbidden (resolved through the core engine — no plugin shortcut).
    $this->actingAs($author)->post('/qa/accept', ['topic_id' => $topic->id, 'post_id' => $answer->id])->assertForbidden();
    expect(DB::table('qa_accepted_answers')->count())->toBe(0);

    // Grant it → the action records the accepted answer.
    grantGlobally($author, 'novfora.qa.accept');
    $this->actingAs($author)->post('/qa/accept', ['topic_id' => $topic->id, 'post_id' => $answer->id])->assertRedirect();
    expect((int) DB::table('qa_accepted_answers')->where('topic_id', $topic->id)->value('post_id'))->toBe($answer->id);

    // The per-post slot renders the accepted badge on that post, and a "mark" affordance on a non-accepted one.
    $this->actingAs($author);
    $registry = app(SlotRegistry::class);
    expect($registry->render('topic.post.aside', ['post' => $answer->fresh(), 'topic' => $topic->fresh()]))
        ->toContain('Accepted answer');
    $op = $topic->posts()->orderBy('id')->first();
    expect($registry->render('topic.post.aside', ['post' => $op, 'topic' => $topic->fresh()]))
        ->toContain('Mark as answer'); // the granted viewer sees the affordance on the not-yet-accepted post
});
