<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Models\AclEntry;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Permission;
use App\Permissions\GroupPermissionEditor;
use App\Permissions\PermissionValue as V;
use App\Permissions\Scope;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v3 · v3-c — the card-per-group permission editor. Engine correctness is proved through the
| PermissionInspector (Acl::assertDecision is the oracle — it checks the cached can() agrees with the full
| explain() trace), never a hand-rolled check (G4). The SFC tests cover the gate (manage-permissions capability)
| and the rank guard.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class)); // permission catalog + role presets + system groups

/** @return list<string> the forum-scoped permission keys the editor exposes at a forum/club scope */
function forumKeys(): array
{
    return Permission::query()->where('scope_kind', 'forum')->pluck('key')->all();
}

describe('engine correctness through the editor (inspector oracle)', function () {
    it('Yes writes an ALLOW the resolver honours; No removes the row so inheritance applies', function () {
        $acl = Acl::make();
        $editor = app(GroupPermissionEditor::class);
        $members = $acl->group('members');
        $u = $acl->user(['members']);

        // Global Yes → granted at the forum (inherited from global).
        $editor->set($members, 'forum.view', $acl->global, 'yes');
        $acl->assertDecision($u, 'forum.view', $acl->forumScope, true, 'group_allow');

        // A forum-scope "No" writes NO row → still inherits the global Yes, decided at global.
        $editor->set($members, 'forum.view', $acl->forumScope, 'no');
        $d = $acl->assertDecision($u, 'forum.view', $acl->forumScope, true, 'group_allow');
        expect($d->decidedAtScope?->key())->toBe('global:*');
        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'forum.view')->where('scope_type', 'forum')->count())->toBe(0);

        // Removing the global Yes (No) deletes the row → deny-by-default everywhere.
        $editor->set($members, 'forum.view', $acl->global, 'no');
        $acl->assertDecision($u, 'forum.view', $acl->forumScope, false, 'default');
        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'forum.view')->count())->toBe(0);
    });

    it('a forum-scope Never overrides an inherited global Yes (the override is scoped)', function () {
        $acl = Acl::make();
        $editor = app(GroupPermissionEditor::class);
        $members = $acl->group('members');
        $u = $acl->user(['members']);

        $editor->set($members, 'topic.create', $acl->global, 'yes');     // global allow
        $editor->set($members, 'topic.create', $acl->forumScope, 'never'); // hard-deny this forum

        $acl->assertDecision($u, 'topic.create', $acl->forumScope, false, 'never'); // the cursed forum
        $acl->assertDecision($u, 'topic.create', $acl->catScope, true, 'group_allow'); // elsewhere: inherited allow
    });

    it('Never is absolute — it beats even a higher-priority group’s Yes', function () {
        $acl = Acl::make();
        $editor = app(GroupPermissionEditor::class);
        $hi = $acl->group('vip', ['priority' => 70]); // higher priority than members (10)
        $lo = $acl->group('members');
        $u = $acl->user(['vip', 'members']);

        $editor->set($hi, 'topic.create', $acl->forumScope, 'yes');   // high-priority YES
        $editor->set($lo, 'topic.create', $acl->forumScope, 'never'); // low-priority NEVER

        $acl->assertDecision($u, 'topic.create', $acl->forumScope, false, 'never');
    });

    it('writes a club-scope grant the resolver honours at the club scope', function () {
        $acl = Acl::make();
        $editor = app(GroupPermissionEditor::class);
        $members = $acl->group('members');
        $u = $acl->user(['members']);
        $club = Scope::club(4242); // the chain global → club resolves a club-scoped group entry

        $editor->set($members, 'forum.view', $club, 'yes');
        $acl->assertDecision($u, 'forum.view', $club, true, 'group_allow');

        $editor->set($members, 'forum.view', $club, 'never');
        $acl->assertDecision($u, 'forum.view', $club, false, 'never');
    });
});

describe('category bulk-apply', function () {
    it('copies a forum’s group overrides onto every other forum in its category', function () {
        $acl = Acl::make(); // category → forum → subforum
        $editor = app(GroupPermissionEditor::class);
        // A CUSTOM group with no seeded role preset, so it has no standing global grant to muddy the assertion.
        $testers = $acl->group('testers', ['priority' => 30]);
        $u = $acl->user(['testers']);

        // A SIBLING forum under the same category — it does NOT inherit from $acl->forum (siblings are isolated).
        $sibling = Forum::create(['slug' => 'forum2', 'title' => 'Forum 2', 'type' => 'forum', 'parent_id' => $acl->category->id]);
        $siblingScope = Scope::forum((int) $sibling->id);

        // Seed the SOURCE forum with a Yes the sibling lacks.
        $editor->set($testers, 'topic.create', $acl->forumScope, 'yes');
        $acl->assertDecision($u, 'topic.create', $siblingScope, false, 'default'); // sibling has nothing, no inheritance

        $written = $editor->copyForumToCategory($acl->forum, forumKeys());

        expect($written)->toBeGreaterThanOrEqual(1); // sibling (+ subforum) are under the category
        // The sibling now carries the same Yes at ITS OWN scope (proving the per-forum write reached it).
        $acl->assertDecision($u, 'topic.create', $siblingScope, true, 'group_allow');
        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $testers->id)
            ->where('permission_key', 'topic.create')->where('scope_type', 'forum')
            ->where('scope_id', $sibling->id)->value('value'))->toBe(V::Allow->value);
    });

    it('throws when the source forum has no parent category', function () {
        $acl = Acl::make();
        $editor = app(GroupPermissionEditor::class);
        // The top category itself has no category ancestor.
        expect(fn () => $editor->copyForumToCategory($acl->category, forumKeys()))
            ->toThrow(RuntimeException::class);
    });
});

describe('the editor SFC — gate + rank guard', function () {
    it('403s a non-permission-admin (has admin.access but not permissions.manage)', function () {
        $acl = Acl::make();
        $frontdesk = $acl->group('frontdesk', ['priority' => 40]);
        $acl->grant($frontdesk, 'admin.access', $acl->global, V::Allow); // ACP access, but NOT permissions.manage
        $u = $acl->user(['frontdesk']);

        Livewire::actingAs($u->fresh())
            ->test('permissions.group-editor', ['scopeType' => 'global'])
            ->assertStatus(403);
    });

    it('admits a 2FA admin and writes Yes/Never/No through the toggle', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $members = Group::where('slug', 'members')->firstOrFail();

        $row = fn () => AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'forum.view')->where('scope_type', 'global')->first();

        $component = Livewire::actingAs($admin)->test('permissions.group-editor', ['scopeType' => 'global']);

        $component->call('setState', $members->id, 'forum.view', 'never')->assertHasNoErrors();
        expect($row()?->value)->toBe(V::Never->value);

        $component->call('setState', $members->id, 'forum.view', 'yes')->assertHasNoErrors();
        expect($row()?->value)->toBe(V::Allow->value);

        $component->call('setState', $members->id, 'forum.view', 'no')->assertHasNoErrors();
        expect($row())->toBeNull(); // No deletes the row
    });

    it('rejects an unknown / out-of-scope permission key (422)', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $members = Group::where('slug', 'members')->firstOrFail();

        // admin.access is a GLOBAL-only key — it must not be writable on a FORUM-scope editor.
        Livewire::actingAs($admin)
            ->test('permissions.group-editor', ['scopeType' => 'forum', 'scopeId' => Acl::make()->forum->id])
            ->call('setState', $members->id, 'admin.access', 'yes')
            ->assertStatus(422);
    });

    it('enforces the rank guard: a non-admin manager cannot edit a group at/above their rank', function () {
        $acl = Acl::make();
        $manager = $acl->group('permmgr', ['priority' => 50]);
        $acl->grant($manager, 'admin.access', $acl->global, V::Allow);
        $acl->grant($manager, 'permissions.manage', $acl->global, V::Allow);
        $u = $acl->user(['permmgr']);

        $moderators = Group::where('slug', 'moderators')->firstOrFail(); // priority 80 > 50
        $members = Group::where('slug', 'members')->firstOrFail();        // priority 10 < 50

        Livewire::actingAs($u->fresh())
            ->test('permissions.group-editor', ['scopeType' => 'global'])
            ->call('setState', $moderators->id, 'forum.view', 'yes')
            ->assertStatus(403); // outranks the actor → blocked

        Livewire::actingAs($u->fresh())
            ->test('permissions.group-editor', ['scopeType' => 'global'])
            ->call('setState', $members->id, 'forum.view', 'yes')
            ->assertHasNoErrors(); // below the actor → allowed
    });
});

describe('the editor SFC — club scope', function () {
    it('admits the club manager and 403s an outsider, writing a club-scope grant', function () {
        $owner = Users::inGroups(['members', 'tl2'], ['email' => 'clubowner@v3c.test']);
        $club = app(ClubService::class)->create($owner, ['name' => 'Editor Test Club', 'privacy' => 'public']);
        $outsider = Users::inGroups(['members'], ['email' => 'outsider@v3c.test']);
        $members = Group::where('slug', 'members')->firstOrFail();

        Livewire::actingAs($outsider)
            ->test('permissions.group-editor', ['scopeType' => 'club', 'scopeId' => $club->id])
            ->assertStatus(403);

        Livewire::actingAs($owner->fresh())
            ->test('permissions.group-editor', ['scopeType' => 'club', 'scopeId' => $club->id])
            ->call('setState', $members->id, 'forum.view', 'yes')
            ->assertHasNoErrors();

        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'forum.view')->where('scope_type', 'club')
            ->where('scope_id', $club->id)->value('value'))->toBe(V::Allow->value);
    });
});

describe('escalation fences (apex review)', function () {
    it('blocks a non-admin permission manager from granting an admin-tier key (no escalation)', function () {
        $acl = Acl::make();
        $manager = $acl->group('permmgr', ['priority' => 50]);
        $acl->grant($manager, 'admin.access', $acl->global, V::Allow);
        $acl->grant($manager, 'permissions.manage', $acl->global, V::Allow);
        $u = $acl->user(['permmgr']);
        $members = Group::where('slug', 'members')->firstOrFail(); // rank 10 < 50 → passes the rank guard

        // The rank guard alone would allow editing members; the per-KEY fence blocks the admin-tier grant.
        Livewire::actingAs($u->fresh())
            ->test('permissions.group-editor', ['scopeType' => 'global'])
            ->call('setState', $members->id, 'admin.access', 'yes')
            ->assertStatus(403);

        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'admin.access')->count())->toBe(0); // nothing was written
    });

    it('lets a full admin grant an admin-tier key to a lower group', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $members = Group::where('slug', 'members')->firstOrFail();

        Livewire::actingAs($admin)
            ->test('permissions.group-editor', ['scopeType' => 'global'])
            ->call('setState', $members->id, 'users.manage', 'yes')
            ->assertHasNoErrors();

        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'users.manage')->where('scope_type', 'global')->value('value'))->toBe(V::Allow->value);
    });

    it('refuses to strip the admins group’s own admin.access at global — no self-lockout (SFC + service)', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $admins = Group::where('slug', 'admins')->firstOrFail();

        foreach (['never', 'no'] as $state) {
            Livewire::actingAs($admin)
                ->test('permissions.group-editor', ['scopeType' => 'global'])
                ->call('setState', $admins->id, 'admin.access', $state)
                ->assertStatus(403);
        }

        expect($admin->fresh()->canDo('admin.access', Scope::global()))->toBeTrue(); // recovery key untouched

        // The service throws as an actor-independent backstop for any non-SFC caller.
        expect(fn () => app(GroupPermissionEditor::class)->set($admins, 'permissions.manage', Scope::global(), 'never'))
            ->toThrow(RuntimeException::class);
    });
});

describe('the editor pages render', function () {
    it('renders the global Group permissions page + the per-forum page for a 2FA admin', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $acl = Acl::make();

        $this->actingAs($admin)->get(route('admin.groups.permissions'))->assertOk()->assertSee('Group permissions');
        $this->actingAs($admin)->get(route('admin.forums.permissions', $acl->forum))->assertOk()->assertSee('Group permissions');
    });
});
