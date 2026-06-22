<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionService;
use App\Models\Ban;
use App\Models\Group;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use App\Moderation\UserBanService;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| ACP v4 · A2 (ADR-0096) — the ⚡admin.members.manage per-member screen. APEX: gate (admin.access +
| admin.members.access + staff-2FA on every entry), per-action capability (ban/warn=bans.manage,
| reset+IP=users.manage), the rank guard + the explicit NO-SELF guard (ActorRank alone returns true for
| admin-on-self), and the email/IP PII gate. Ban/warn reuse the engine (UserBanService / WarningService).
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function a2FullAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'])); // users.manage + bans.manage + admin.members.access
}

/** A restricted admin: admin.members.access but NO bans.manage / users.manage (not a staff group → no 2FA). */
function a2RestrictedAdmin(Acl $acl): User
{
    $grp = $acl->group('a2viewer', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow);
    $acl->grant($grp, 'admin.members.access', $acl->global, V::Allow);

    return $acl->user(['a2viewer']);
}

function minorWarningType(): WarningType
{
    return WarningType::firstOrCreate(
        ['slug' => 'minor'],
        ['label' => 'Minor warning', 'default_points' => 1, 'decay_days' => 30, 'is_active' => true],
    );
}

// ── Route + component gate ───────────────────────────────────────────────────────────────────────────────

it('redirects a guest + forbids a non-admin from the manage route', function () {
    $target = Users::inGroups(['members'], ['username' => 'subjectone']);
    $this->get(route('admin.members.show', $target))->assertRedirect();
    $this->actingAs(Users::inGroups(['members']))->get(route('admin.members.show', $target))->assertForbidden();
});

it('loads the manage screen for a 2FA admin', function () {
    $target = Users::inGroups(['members'], ['username' => 'subjecttwo']);
    $this->actingAs(a2FullAdmin())->get(route('admin.members.show', $target))->assertOk()->assertSee('Manage member');
});

it('403s the component for a non-admin and for a staff admin without 2FA', function () {
    $target = Users::inGroups(['members']);
    Livewire::actingAs(Users::inGroups(['members']))->test('admin.members.manage', ['userId' => $target->id])->assertForbidden();
    Livewire::actingAs(Users::inGroups(['admins']))->test('admin.members.manage', ['userId' => $target->id])->assertForbidden();
});

// ── Ban / lift ───────────────────────────────────────────────────────────────────────────────────────────

it('bans and then lifts a member (reusing UserBanService)', function () {
    $target = Users::inGroups(['members'], ['username' => 'troublemaker']);

    Livewire::actingAs(a2FullAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('banReason', 'persistent spam')
        ->call('banUser')
        ->assertHasNoErrors();

    expect($target->fresh()->status)->toBe('banned')
        ->and(Ban::where('user_id', $target->id)->where('type', 'user')->exists())->toBeTrue();

    Livewire::actingAs(a2FullAdmin())->test('admin.members.manage', ['userId' => $target->id])->call('liftBan');

    expect($target->fresh()->status)->toBe('active')
        ->and(Ban::where('user_id', $target->id)->where('type', 'user')->exists())->toBeFalse();
});

it('refuses a self-ban (no-self guard — ActorRank is true for admin-on-self)', function () {
    $admin = a2FullAdmin();
    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $admin->id])->call('banUser')->assertForbidden();
    expect($admin->fresh()->status)->not->toBe('banned');
});

it('refuses to ban a higher-ranked target (rank guard)', function () {
    $acl = Acl::make();
    $modGroup = $acl->group('lowmod', ['priority' => 10]);
    $acl->grant($modGroup, 'admin.access', $acl->global, V::Allow);
    $acl->grant($modGroup, 'admin.members.access', $acl->global, V::Allow);
    $acl->grant($modGroup, 'bans.manage', $acl->global, V::Allow);
    $mod = $acl->user(['lowmod']);
    $acl->group('bigwig', ['priority' => 100]);
    $target = $acl->user(['bigwig']);

    Livewire::actingAs($mod)->test('admin.members.manage', ['userId' => $target->id])->call('banUser')->assertForbidden();
    expect($target->fresh()->status)->not->toBe('banned');
});

it('403s ban for an admin lacking bans.manage', function () {
    $acl = Acl::make();
    $admin = a2RestrictedAdmin($acl);
    $target = Users::inGroups(['members']);
    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $target->id])->call('banUser')->assertForbidden();
});

// ── Warnings ─────────────────────────────────────────────────────────────────────────────────────────────

it('issues a warning and lists it in history', function () {
    $target = Users::inGroups(['members'], ['username' => 'warnme']);
    $type = minorWarningType();

    Livewire::actingAs(a2FullAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('warningTypeId', $type->id)
        ->set('warnReason', 'be civil')
        ->call('warnMember')
        ->assertHasNoErrors()
        ->assertSee('Minor warning');

    expect(Warning::where('user_id', $target->id)->exists())->toBeTrue();
});

it('refuses a self-warning', function () {
    $admin = a2FullAdmin();
    $type = minorWarningType();
    Livewire::actingAs($admin)->test('admin.members.manage', ['userId' => $admin->id])
        ->set('warningTypeId', $type->id)->call('warnMember')->assertForbidden();
    expect(Warning::where('user_id', $admin->id)->exists())->toBeFalse();
});

// ── Force password reset + IP/PII gate ───────────────────────────────────────────────────────────────────

it('initiates a password reset for a users.manage admin', function () {
    $target = Users::inGroups(['members'], ['username' => 'resetme', 'email' => 'resetme@example.test']);

    Livewire::actingAs(a2FullAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->call('forcePasswordReset')->assertHasNoErrors();

    expect(DB::table('password_reset_tokens')->where('email', 'resetme@example.test')->exists())->toBeTrue();
});

it('403s force-reset + hides email/sessions from a restricted admin (no users.manage)', function () {
    $acl = Acl::make();
    $restricted = a2RestrictedAdmin($acl);
    $target = Users::inGroups(['members'], ['username' => 'shielded', 'email' => 'shielded@example.test']);

    Livewire::actingAs($restricted)->test('admin.members.manage', ['userId' => $target->id])
        ->assertDontSee('shielded@example.test') // email PII hidden
        ->assertDontSee('Account security')      // the reset + sessions (IP) card is users.manage-only
        ->call('forcePasswordReset')->assertForbidden();
});

// ── Last-owner guard (apex-review HIGH — mirror the deletion path) ────────────────────────────────────────

it('refuses to ban or warn the sole co-owner (last-owner strand guard)', function () {
    $actor = a2FullAdmin(); // a plain admin (not the co-owner)
    $adminsId = Group::where('slug', 'admins')->value('id');
    $target = Users::inGroups(['admins'], ['username' => 'lastowner']);

    // Make $target the SOLE co-owner: clear every co-owner flag, then crown only the target.
    DB::table('group_user')->update(['is_co_owner' => false]);
    DB::table('group_user')->where('user_id', $target->id)->where('group_id', $adminsId)->update(['is_co_owner' => true]);
    expect(app(AccountDeletionService::class)->isSoleCoOwner($target->fresh()))->toBeTrue();

    Livewire::actingAs($actor)->test('admin.members.manage', ['userId' => $target->id])->call('banUser')->assertForbidden();
    Livewire::actingAs($actor)->test('admin.members.manage', ['userId' => $target->id])
        ->set('warningTypeId', minorWarningType()->id)->call('warnMember')->assertForbidden();

    expect($target->fresh()->status)->not->toBe('banned')
        ->and(Warning::where('user_id', $target->id)->exists())->toBeFalse();
});

it('enforces the rank guard on liftBan (defense-in-depth, the fixed MEDIUM)', function () {
    $acl = Acl::make();
    $modGroup = $acl->group('lowmod2', ['priority' => 10]);
    $acl->grant($modGroup, 'admin.access', $acl->global, V::Allow);
    $acl->grant($modGroup, 'admin.members.access', $acl->global, V::Allow);
    $acl->grant($modGroup, 'bans.manage', $acl->global, V::Allow);
    $mod = $acl->user(['lowmod2']);
    $acl->group('bigwig2', ['priority' => 100]);
    $target = $acl->user(['bigwig2']);
    app(UserBanService::class)->ban($target); // a ban exists to attempt lifting

    Livewire::actingAs($mod)->test('admin.members.manage', ['userId' => $target->id])->call('liftBan')->assertForbidden();
    expect($target->fresh()->status)->toBe('banned'); // not lifted by the outranked actor
});
