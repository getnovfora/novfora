<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Clubs\ClubService;
use App\Models\AclEntry;
use App\Models\Group;
use App\Models\Permission;
use App\Permissions\CapabilityMap;
use App\Permissions\PermissionValue as V;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| Simple-mode permissions (ADR-0089). A layman capability-toggle WRITE surface over the SAME acl_entries + the
| SAME write primitive (GroupPermissionEditor) as the card editor — NOT an engine change. Engine correctness is
| proven through the PermissionInspector oracle (Acl::assertDecision), never a hand-rolled check (G4). The crux
| is the capability→key mapping: ON writes EXACTLY its keys as ALLOW at the right scope; OFF inherits; simple
| mode never writes `never`; admin/moderation keys are excluded; a hard trust-NEVER is locked, not lifted.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed(DatabaseSeeder::class)); // catalog + role presets + system/trust groups + trust gates

describe('CapabilityMap — the mapping core (apex review)', function () {
    it('excludes every Administration-tier, Moderation, and club.manage key', function () {
        $excluded = Permission::query()->whereIn('group', ['Administration', 'Moderation'])->pluck('key')->push('club.manage')->all();

        expect(array_values(array_intersect(CapabilityMap::allKeys(), $excluded)))->toBe([]);
    });

    it('maps only real catalog keys', function () {
        $catalog = Permission::query()->pluck('key')->all();

        foreach (CapabilityMap::allKeys() as $key) {
            expect($catalog)->toContain($key);
        }
    });

    it('shows all 7 capabilities at global but only the forum-scoped ones at forum/club (no inert row)', function () {
        expect(CapabilityMap::for('global'))->toBe(['read_reply', 'start_topics', 'post_media', 'react_vote', 'polls_tags', 'follow', 'pm']);
        expect(CapabilityMap::for('forum'))->toBe(['read_reply', 'start_topics', 'post_media', 'react_vote']);
        expect(CapabilityMap::for('club'))->toBe(['read_reply', 'start_topics', 'post_media', 'react_vote']);
    });
});

describe('capability round-trip through the SFC (inspector oracle)', function () {
    it('ON writes exactly its keys as ALLOW the resolver honours; OFF deletes them (inherit); never writes never', function () {
        $acl = Acl::make();
        $testers = $acl->group('testers', ['priority' => 30]); // custom group, no preset → clean assertion
        $u = $acl->user(['testers']);
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        $component = Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'global']);

        // ON → every key in the bundle is an ALLOW row at global, and the resolver grants it (inherited to the forum).
        $component->call('setCapability', $testers->id, 'read_reply', true)->assertHasNoErrors();
        foreach (CapabilityMap::keys('read_reply') as $key) {
            expect(AclEntry::where('holder_type', 'group')->where('holder_id', $testers->id)
                ->where('permission_key', $key)->where('scope_type', 'global')->value('value'))->toBe(V::Allow->value);
        }
        $acl->assertDecision($u, 'forum.view', $acl->forumScope, true, 'group_allow');
        $acl->assertDecision($u, 'post.create', $acl->forumScope, true, 'group_allow');

        // OFF → the rows are deleted (inherit / deny-by-default); never a `never` row anywhere.
        $component->call('setCapability', $testers->id, 'read_reply', false)->assertHasNoErrors();
        foreach (CapabilityMap::keys('read_reply') as $key) {
            expect(AclEntry::where('holder_type', 'group')->where('holder_id', $testers->id)->where('permission_key', $key)->count())->toBe(0);
        }
        $acl->assertDecision($u, 'forum.view', $acl->forumScope, false, 'default');
        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $testers->id)->where('value', V::Never->value)->count())->toBe(0);
    });

    it('writes a forum-scope capability at the forum scope only', function () {
        $acl = Acl::make();
        $testers = $acl->group('testers', ['priority' => 30]);
        $u = $acl->user(['testers']);
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'forum', 'scopeId' => $acl->forum->id])
            ->call('setCapability', $testers->id, 'start_topics', true)->assertHasNoErrors();

        $acl->assertDecision($u, 'topic.create', $acl->forumScope, true, 'group_allow'); // granted here
        $acl->assertDecision($u, 'topic.create', $acl->global, false, 'default');         // not globally (scoped write)
    });

    it('toggles a soft-gated capability normally (it is admin-liftable by design)', function () {
        $acl = Acl::make();
        $members = $acl->group('members'); // no NEVER; follow.create withheld (soft) → not restricted
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'global'])
            ->call('setCapability', $members->id, 'follow', true)->assertHasNoErrors();

        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $members->id)
            ->where('permission_key', 'follow.create')->where('scope_type', 'global')->value('value'))->toBe(V::Allow->value);
    });
});

describe('correctness fences (apex review)', function () {
    it('refuses a global-only capability at forum scope (422 — never a silently-inert row)', function () {
        $acl = Acl::make();
        $members = $acl->group('members');
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'forum', 'scopeId' => $acl->forum->id])
            ->call('setCapability', $members->id, 'pm', true) // pm is global-only
            ->assertStatus(422);
    });

    it('rejects an unknown capability slug (422)', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $members = Group::where('slug', 'members')->firstOrFail();

        Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'global'])
            ->call('setCapability', $members->id, 'moderate', true) // not a capability
            ->assertStatus(422);
    });

    it('locks a hard-NEVER capability and refuses to lift it (the tl0 trust gate survives)', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $tl0 = Group::where('slug', 'tl0')->firstOrFail(); // post.links / post.images = NEVER (TrustGateSeeder)

        Livewire::actingAs($admin)->test('permissions.group-simple-editor', ['scopeType' => 'global'])
            ->call('setCapability', $tl0->id, 'post_media', true)
            ->assertStatus(403);

        // The NEVER is untouched — simple mode never lifts a hard gate.
        expect(AclEntry::where('holder_type', 'group')->where('holder_id', $tl0->id)
            ->where('permission_key', 'post.links')->where('scope_type', 'global')->value('value'))->toBe(V::Never->value);
    });

    it('403s a non-permission-admin (admin.access but not permissions.manage)', function () {
        $acl = Acl::make();
        $frontdesk = $acl->group('frontdesk', ['priority' => 40]);
        $acl->grant($frontdesk, 'admin.access', $acl->global, V::Allow);
        $u = $acl->user(['frontdesk']);

        Livewire::actingAs($u->fresh())->test('permissions.group-simple-editor', ['scopeType' => 'global'])->assertStatus(403);
    });

    it('enforces the rank guard (cannot edit a group at/above the actor rank)', function () {
        $acl = Acl::make();
        $manager = $acl->group('permmgr', ['priority' => 50]);
        $acl->grant($manager, 'admin.access', $acl->global, V::Allow);
        $acl->grant($manager, 'permissions.manage', $acl->global, V::Allow);
        $u = $acl->user(['permmgr']);
        $moderators = Group::where('slug', 'moderators')->firstOrFail(); // priority 80 > 50

        Livewire::actingAs($u->fresh())->test('permissions.group-simple-editor', ['scopeType' => 'global'])
            ->call('setCapability', $moderators->id, 'read_reply', true)
            ->assertStatus(403);
    });
});

describe('the Simple / Advanced switch on the three homes', function () {
    it('defaults to Simple and shows Advanced via ?mode (global Group permissions)', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));

        $this->actingAs($admin)->get(route('admin.groups.permissions'))
            ->assertOk()->assertSee('Read & reply')->assertSee('Advanced'); // simple editor + the switch

        $this->actingAs($admin)->get(route('admin.groups.permissions', ['mode' => 'advanced']))
            ->assertOk()->assertSee('View forums & topics'); // the card editor's catalog labels (advanced only)
    });

    it('offers both modes on the per-forum permissions page', function () {
        $admin = Users::withTwoFactor(Users::inGroups(['admins']));
        $acl = Acl::make();

        $this->actingAs($admin)->get(route('admin.forums.permissions', $acl->forum))
            ->assertOk()->assertSee('Read & reply');
        $this->actingAs($admin)->get(route('admin.forums.permissions', ['forum' => $acl->forum->id, 'mode' => 'advanced']))
            ->assertOk()->assertSee('View forums & topics');
    });

    it('offers both modes on the club manage page', function () {
        $owner = Users::inGroups(['members', 'tl2'], ['email' => 'simpleclubowner@t.test']);
        $club = app(ClubService::class)->create($owner, ['name' => 'Simple Mode Club', 'privacy' => 'public']);

        $this->actingAs($owner->fresh())->get(route('clubs.edit', $club))
            ->assertOk()->assertSee('Read & reply');
        $this->actingAs($owner->fresh())->get(route('clubs.edit', ['club' => $club->slug, 'mode' => 'advanced']))
            ->assertOk()->assertSee('View forums & topics');
    });
});
