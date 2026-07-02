<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Members\UsernameService;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\UsernameHistory;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| U8 (ADR-0106) — username history + admin change/revert. UsernameService is the single write chokepoint
| (history row snapshotted BEFORE the overwrite + an explicit-actor audit row, inside one locked
| transaction); the ⚡admin.members.manage card is gated by users.manage + the no-self/rank guard; a
| revert into a now-taken name FAILS LOUD (never auto-suffixes into a name the admin didn't ask for).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** An admins-group member with confirmed 2FA — the administrator preset holds users.manage. */
function usernameAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins']));
}

// ── Service: change writes history + audit + the new username ────────────────────────────────────────────

it('records a history row + audit and updates the username through the service', function () {
    $admin = usernameAdmin();
    $target = Users::inGroups(['members'], ['username' => 'oldhandle']);

    app(UsernameService::class)->change($target, 'newhandle', $admin, 'requested via support');

    expect($target->fresh()->username)->toBe('newhandle');

    $row = UsernameHistory::where('user_id', $target->id)->first();
    expect($row)->not->toBeNull()
        ->and($row->old_username)->toBe('oldhandle')
        ->and($row->new_username)->toBe('newhandle')
        ->and((int) $row->changed_by)->toBe($admin->id)
        ->and($row->reason)->toBe('requested via support');

    $audit = AuditLog::where('action', 'user.username.changed')->where('auditable_id', $target->id)->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and((int) $audit->actor_id)->toBe($admin->id)
        ->and($audit->changes['from'])->toBe('oldhandle')
        ->and($audit->changes['to'])->toBe('newhandle')
        ->and($audit->changes['reason'])->toBe('requested via support');
});

it('rejects a taken, invalid, too-short, or unchanged username (no history side effects)', function () {
    $admin = usernameAdmin();
    Users::inGroups(['members'], ['username' => 'squatter']);
    $target = Users::inGroups(['members'], ['username' => 'currenthandle']);
    $svc = app(UsernameService::class);

    expect(fn () => $svc->change($target, 'squatter', $admin))->toThrow(ValidationException::class)        // taken
        ->and(fn () => $svc->change($target, 'bad name!', $admin))->toThrow(ValidationException::class)    // alpha_dash
        ->and(fn () => $svc->change($target, 'ab', $admin))->toThrow(ValidationException::class)           // min:3
        ->and(fn () => $svc->change($target, 'currenthandle', $admin))->toThrow(ValidationException::class); // no-op

    expect($target->fresh()->username)->toBe('currenthandle')
        ->and(UsernameHistory::where('user_id', $target->id)->count())->toBe(0)
        ->and(AuditLog::where('action', 'user.username.changed')->where('auditable_id', $target->id)->count())->toBe(0);
});

// ── Service: revert restores the old name, fails loud on collision, rejects foreign entries ─────────────

it('reverts to a previous username with its own history row + audit', function () {
    $admin = usernameAdmin();
    $target = Users::inGroups(['members'], ['username' => 'firstname']);
    $svc = app(UsernameService::class);

    $svc->change($target, 'secondname', $admin);
    $entry = UsernameHistory::where('user_id', $target->id)->firstOrFail();

    $svc->revertTo($target->fresh(), $entry, $admin);

    expect($target->fresh()->username)->toBe('firstname')
        ->and(UsernameHistory::where('user_id', $target->id)->count())->toBe(2);

    $revertRow = UsernameHistory::where('user_id', $target->id)->orderByDesc('id')->first();
    expect($revertRow->old_username)->toBe('secondname')
        ->and($revertRow->new_username)->toBe('firstname');

    $audit = AuditLog::where('action', 'user.username.reverted')->where('auditable_id', $target->id)->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changes['from'])->toBe('secondname')
        ->and($audit->changes['to'])->toBe('firstname');
});

it('fails loud when a revert collides with a now-taken name — never auto-suffixes', function () {
    $admin = usernameAdmin();
    $target = Users::inGroups(['members'], ['username' => 'vacated']);
    $svc = app(UsernameService::class);

    $svc->change($target, 'movedon', $admin);
    Users::inGroups(['members'], ['username' => 'vacated']); // someone else claims the vacated name
    $entry = UsernameHistory::where('user_id', $target->id)->firstOrFail();

    expect(fn () => $svc->revertTo($target->fresh(), $entry, $admin))->toThrow(ValidationException::class);

    expect($target->fresh()->username)->toBe('movedon') // unchanged — no silent rename
        ->and(UsernameHistory::where('user_id', $target->id)->count())->toBe(1)
        ->and(AuditLog::where('action', 'user.username.reverted')->where('auditable_id', $target->id)->count())->toBe(0);
});

it("rejects a revert using another member's history entry", function () {
    $admin = usernameAdmin();
    $other = Users::inGroups(['members'], ['username' => 'otherbefore']);
    $target = Users::inGroups(['members'], ['username' => 'unrelated']);
    $svc = app(UsernameService::class);

    $svc->change($other, 'otherafter', $admin);
    $foreign = UsernameHistory::where('user_id', $other->id)->firstOrFail();

    expect(fn () => $svc->revertTo($target->fresh(), $foreign, $admin))->toThrow(ValidationException::class);
    expect($target->fresh()->username)->toBe('unrelated');
});

// ── SFC: capability gate, rank guard, no-self, happy path ────────────────────────────────────────────────

it('403s an admin without users.manage and leaves the username unchanged', function () {
    $acl = Acl::make();
    $grp = $acl->group('unamerestricted', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow);
    $acl->grant($grp, 'admin.members.access', $acl->global, V::Allow);
    $restricted = $acl->user(['unamerestricted']);

    $target = Users::inGroups(['members'], ['username' => 'gatecheck']);

    Livewire::actingAs($restricted)->test('admin.members.manage', ['userId' => $target->id])
        ->set('newUsername', 'sneaky')->call('setUsername')->assertForbidden();

    expect($target->fresh()->username)->toBe('gatecheck')
        ->and(UsernameHistory::where('user_id', $target->id)->count())->toBe(0);
});

it('enforces the rank guard: a lower-ranked users.manage holder cannot rename a higher-ranked member', function () {
    $acl = Acl::make();
    $grp = $acl->group('lowunamemgr', ['priority' => 10]);
    foreach (['admin.access', 'admin.members.access', 'users.manage'] as $key) {
        $acl->grant($grp, $key, $acl->global, V::Allow);
    }
    $actor = $acl->user(['lowunamemgr']);
    $acl->group('unametop', ['priority' => 100]);
    $target = $acl->user(['unametop'], ['username' => 'outranked']);

    Livewire::actingAs($actor)->test('admin.members.manage', ['userId' => $target->id])
        ->set('newUsername', 'renamed')->call('setUsername')->assertForbidden();

    expect($target->fresh()->username)->toBe('outranked');
});

it('refuses a self-rename through the SFC (no-self guard)', function () {
    $admin = Users::withTwoFactor(Users::inGroups(['admins'], ['username' => 'selfadmin']));

    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $admin->id])
        ->set('newUsername', 'selfrename')->call('setUsername')->assertForbidden();

    expect($admin->fresh()->username)->toBe('selfadmin');
});

it('changes and reverts a username through the SFC (flash + history rows)', function () {
    $admin = usernameAdmin();
    $target = Users::inGroups(['members'], ['username' => 'sfcbefore']);

    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $target->id])
        ->set('newUsername', 'sfcafter')
        ->set('usernameReason', 'member request')
        ->call('setUsername')
        ->assertHasNoErrors()
        ->assertSet('flash', 'Username changed.')
        ->assertSet('newUsername', ''); // form reset

    expect($target->fresh()->username)->toBe('sfcafter');
    $entry = UsernameHistory::where('user_id', $target->id)->firstOrFail();
    expect($entry->old_username)->toBe('sfcbefore')
        ->and($entry->reason)->toBe('member request');

    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $target->id])
        ->call('revertUsername', $entry->id)
        ->assertHasNoErrors()
        ->assertSet('flash', 'Username reverted.');

    expect($target->fresh()->username)->toBe('sfcbefore')
        ->and(UsernameHistory::where('user_id', $target->id)->count())->toBe(2)
        ->and(AuditLog::where('action', 'user.username.reverted')->where('auditable_id', $target->id)->count())->toBe(1);
});

it('surfaces a taken name as a field error through the SFC — no exception leak', function () {
    $admin = usernameAdmin();
    Users::inGroups(['members'], ['username' => 'occupied']);
    $target = Users::inGroups(['members'], ['username' => 'wantsit']);

    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $target->id])
        ->set('newUsername', 'occupied')->call('setUsername')
        ->assertHasErrors('newUsername');

    expect($target->fresh()->username)->toBe('wantsit');
});

it("404s a revert that names another member's history entry (scoped lookup)", function () {
    $admin = usernameAdmin();
    $other = Users::inGroups(['members'], ['username' => 'othersfc']);
    $target = Users::inGroups(['members'], ['username' => 'targetsfc']);

    app(UsernameService::class)->change($other, 'othersfc2', $admin);
    $foreign = UsernameHistory::where('user_id', $other->id)->firstOrFail();

    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $target->id])
        ->call('revertUsername', $foreign->id)->assertNotFound();

    expect($target->fresh()->username)->toBe('targetsfc')
        ->and($other->fresh()->username)->toBe('othersfc2');
});
