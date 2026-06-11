<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Permissions\PermissionInspector;
use App\Permissions\PermissionValue as V;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| The "why can / can't X?" inspector (security §1.4): the service report, the novfora:why CLI, and the
| ACP Livewire panel. All three read the same resolution the engine uses — never a re-implementation.
*/

uses(RefreshDatabase::class);

const PI = 'forum.post';

it('reports an ALLOW with the decisive rule, scope chain and candidate entries', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PI, $acl->global, V::Allow);

    $report = app(PermissionInspector::class)->inspect($u->fresh(), PI, $acl->forumScope);

    expect($report['granted'])->toBeTrue();
    expect($report['reason'])->toBe('group_allow');
    expect($report['decided_at_scope'])->toBe('global:*');
    expect($report['scope_chain'])->toBe(['global:*', 'category:'.$acl->category->id, 'forum:'.$acl->forum->id]);
    expect($report['holders'])->toContain('user#'.$u->id);
    expect($report['entries'])->toHaveCount(1);
});

it('reports a DENY by NEVER with the offending entry', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PI, $acl->forumScope, V::Allow);
    $acl->grant('members', PI, $acl->forumScope, V::Never);

    $report = app(PermissionInspector::class)->inspect($u->fresh(), PI, $acl->forumScope);

    expect($report['granted'])->toBeFalse();
    expect($report['reason'])->toBe('never');
});

it('reports deny-by-default with no candidate entries', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);

    $report = app(PermissionInspector::class)->inspect($u->fresh(), PI, $acl->forumScope);

    expect($report['granted'])->toBeFalse();
    expect($report['reason'])->toBe('default');
    expect($report['entries'])->toBe([]);
});

it('explains a decision through the novfora:why command', function () {
    $acl = Acl::make();
    $u = $acl->user(['members'], ['email' => 'mod@novfora.test']);
    $acl->grant('members', PI, $acl->forumScope, V::Allow);

    $this->artisan('novfora:why', [
        'user' => 'mod@novfora.test',
        'permission' => PI,
        'scope' => 'forum:'.$acl->forum->id,
    ])->assertSuccessful();
});

it('fails the novfora:why command cleanly for an unknown user', function () {
    $this->artisan('novfora:why', ['user' => 'ghost@nowhere.test', 'permission' => PI, 'scope' => 'global'])
        ->assertFailed();
});

it('renders the ACP inspector page for an authenticated admin', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = Users::withTwoFactor(Users::inGroups(['admins']));

    $this->actingAs($admin)->get(route('admin.system.permissions'))
        ->assertOk()
        ->assertSee('Permission Inspector');
});

it('inspects through the Livewire ACP panel', function () {
    $acl = Acl::make();
    $u = $acl->user(['members']);
    $acl->grant('members', PI, $acl->forumScope, V::Allow);

    Livewire::test('admin.permission-inspector')
        ->set('userRef', (string) $u->id)
        ->set('permission', PI)
        ->set('scopeRef', 'forum:'.$acl->forum->id)
        ->call('inspect')
        ->assertSet('error', null)
        ->assertSet('report.granted', true);
});

it('surfaces a friendly error for a bad scope in the panel', function () {
    $acl = Acl::make();
    $u = $acl->user();

    Livewire::test('admin.permission-inspector')
        ->set('userRef', (string) $u->id)
        ->set('permission', PI)
        ->set('scopeRef', 'planet:9')
        ->call('inspect')
        ->assertSet('report', null)
        ->assertSeeText('Unrecognised scope reference');
});
