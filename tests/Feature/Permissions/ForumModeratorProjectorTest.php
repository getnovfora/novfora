<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\ModeratorAssignment;
use App\Models\Role;
use App\Permissions\AclVersion;
use App\Permissions\ForumModeratorProjector;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\RoleException;
use App\Permissions\Scope;
use Database\Seeders\ModeratorBundleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Acl;
use Tests\Support\Users;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** The engine memoises per request + caches cross-request; after ACL writes a test must drop both. */
function modFreshAcl(): void
{
    app(PermissionResolver::class)->flushMemo();
    Cache::flush();
}

function projector(): ForumModeratorProjector
{
    return app(ForumModeratorProjector::class);
}

function bundle(string $slug): Role
{
    return Role::where('slug', $slug)->firstOrFail();
}

// ── G4 inspector-oracle, forum scope ────────────────────────────────────────────────────────────────────

it('case 1: a forum-scoped USER grant resolves user_allow for topic.moderate on that forum', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin1@v3b.test']);
    $target = Users::inGroups(['members'], ['email' => 'mod-t1@v3b.test']);

    projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-full'));
    modFreshAcl();

    $acl->assertDecision($target->fresh(), 'topic.moderate', $acl->forumScope, true, 'user_allow');
});

it('case 2: the same user is DENIED on a different forum (scope isolation)', function () {
    $acl = Acl::make();
    $other = Forum::create(['slug' => 'other-forum', 'title' => 'Other', 'type' => 'forum', 'parent_id' => $acl->category->id]);
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin2@v3b.test']);
    $target = Users::inGroups(['members'], ['email' => 'mod-t2@v3b.test']);

    projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-full'));
    modFreshAcl();

    $acl->assertDecision($target->fresh(), 'topic.moderate', $acl->forumScope, true, 'user_allow');
    $acl->assertDecision($target->fresh(), 'topic.moderate', Scope::forum((int) $other->id), false);
});

it('case 3: a forum-scoped GROUP grant resolves group_allow for members of that group', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin3@v3b.test']);
    $modGroup = Group::firstOrCreate(['slug' => 'forum-mods-x'], ['name' => 'Forum Mods X', 'type' => 'custom']);
    $member = Users::inGroups(['members'], ['email' => 'mod-gm@v3b.test']);
    $member->groups()->attach($modGroup->id, ['is_primary' => false]);

    projector()->assign($admin, 'group', (int) $modGroup->id, (int) $acl->forum->id, bundle('forum-mod-full'));
    modFreshAcl();

    $acl->assertDecision($member->fresh(), 'topic.moderate', $acl->forumScope, true, 'group_allow');
});

it('case 4: a preset bundle expands EXACTLY its keys at forum scope', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin4@v3b.test']);
    $target = Users::inGroups(['members'], ['email' => 'mod-t4@v3b.test']);

    projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-content'));

    $written = AclEntry::query()
        ->where('holder_type', 'user')->where('holder_id', $target->id)
        ->where('scope_type', 'forum')->where('scope_id', $acl->forum->id)
        ->pluck('permission_key')->sort()->values()->all();

    $expected = collect(ModeratorBundleSeeder::bundles()['forum-mod-content']['permissions'])->sort()->values()->all();
    expect($written)->toBe($expected);
});

it('case 5: revoke deletes the rows, flips the verdict to denied, and bumps AclVersion', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin5@v3b.test']);
    $target = Users::inGroups(['members'], ['email' => 'mod-t5@v3b.test']);

    projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-full'));
    modFreshAcl();
    $acl->assertDecision($target->fresh(), 'topic.moderate', $acl->forumScope, true, 'user_allow');

    $before = app(AclVersion::class)->current();
    projector()->revoke('user', (int) $target->id, (int) $acl->forum->id);
    expect(app(AclVersion::class)->current())->toBeGreaterThan($before); // measure before any Cache::flush

    modFreshAcl();
    expect(ModeratorAssignment::where('holder_id', $target->id)->where('forum_id', $acl->forum->id)->count())->toBe(0);
    expect(AclEntry::where('holder_type', 'user')->where('holder_id', $target->id)
        ->where('scope_type', 'forum')->where('scope_id', $acl->forum->id)->count())->toBe(0);
    $acl->assertDecision($target->fresh(), 'topic.moderate', $acl->forumScope, false);
});

it('case 6: the rank guard refuses assigning a USER who ranks at or above a non-admin actor', function () {
    $acl = Acl::make();
    $actor = Users::inGroups(['moderators'], ['email' => 'mod-actor6@v3b.test']);  // non-admin, holds the mod keys (ceiling OK), rank 80
    $target = Users::inGroups(['admins'], ['email' => 'mod-target6@v3b.test']);    // rank 100 — outranks the actor

    expect(fn () => projector()->assign($actor, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-content')))
        ->toThrow(RoleException::class);

    // nothing was written (fences run before any write)
    expect(ModeratorAssignment::where('forum_id', $acl->forum->id)->count())->toBe(0);
});

it('case 7a: the ceiling refuses granting a key the actor cannot exercise on the forum', function () {
    $acl = Acl::make();
    $actor = Users::inGroups(['members'], ['email' => 'mod-actor7a@v3b.test']); // non-admin, holds NO moderation keys
    $target = Users::inGroups(['members'], ['email' => 'mod-target7a@v3b.test']);

    expect(fn () => projector()->assign($actor, 'user', (int) $target->id, (int) $acl->forum->id, bundle('forum-mod-content')))
        ->toThrow(RoleException::class);
});

it('case 7b: admin.access can NEVER be granted as a forum-moderator capability (even by a full admin)', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin7b@v3b.test']); // would pass the ceiling
    $target = Users::inGroups(['members'], ['email' => 'mod-target7b@v3b.test']);

    $evil = Role::create(['slug' => 'evil-admin-role', 'name' => 'Evil', 'is_preset' => false]);
    $evil->permissions()->create(['permission_key' => 'admin.access', 'value' => PermissionValue::Allow->value]);

    expect(fn () => projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, $evil->fresh()))
        ->toThrow(RoleException::class);
});

it('case 8: refuses a role carrying a NEVER — a forum-mod role may only GRANT, never deny (adversarial-review fix)', function () {
    $acl = Acl::make();
    $admin = Users::inGroups(['admins'], ['email' => 'mod-admin8@v3b.test']); // would pass the ceiling + rank
    $target = Users::inGroups(['members'], ['email' => 'mod-target8@v3b.test']);

    // A non-admin-tier NEVER (forum.view is catalog group 'Reading', not 'Administration') — it sails past the
    // admin-tier loop and is ceiling-exempt, so without the grant-only fence it would mint a forum-scope hard-deny.
    $deny = Role::create(['slug' => 'sneaky-deny', 'name' => 'Sneaky Deny', 'is_preset' => false]);
    $deny->permissions()->create(['permission_key' => 'forum.view', 'value' => PermissionValue::Never->value]);

    expect(fn () => projector()->assign($admin, 'user', (int) $target->id, (int) $acl->forum->id, $deny->fresh()))
        ->toThrow(RoleException::class);

    // nothing written → the holder is NOT hard-denied forum.view on this forum.
    expect(AclEntry::where('holder_type', 'user')->where('holder_id', $target->id)
        ->where('scope_type', 'forum')->where('scope_id', $acl->forum->id)->count())->toBe(0);
});
