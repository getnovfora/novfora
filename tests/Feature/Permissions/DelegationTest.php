<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\DelegationException;
use App\Admin\DelegationService;
use App\Admin\GroupManager;
use App\Models\AclEntry;
use App\Models\Delegation;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\RoleException;
use App\Permissions\RoleExpander;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\Users;

/*
| ACP v3 · v3-f (ADR-0087) — temporary-access delegation. The apex invariants: a delegator can only delegate a
| key they hold AT THE TARGET SCOPE now (the ceiling); co-owner / Administration-tier keys are never delegable;
| the window is capped at 30 days; revoke flips the recipient's can() AND bumps AclVersion; the row auto-expires
| via the v3-0 seam with NO prune run; and a delegation never outlives its delegator's current mask (cascade).
| The resolver is the oracle — explain() is uncached, so it reads the authoritative DB truth every time.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

const DK = 'topic.moderate'; // a real, delegable (non-Administration) catalog key the admin preset grants

function svc(): DelegationService
{
    return app(DelegationService::class);
}

function adminsId(): int
{
    return (int) Group::query()->where('slug', 'admins')->value('id');
}

/** A real co-owner: a full admin carrying the is_co_owner flag + the admin.security.access grant (as the installer crowns one). */
function coOwner(): User
{
    $u = Users::inGroups(['admins']);
    $u->groups()->updateExistingPivot(adminsId(), ['is_co_owner' => true]);
    grantUserKey($u, 'admin.security.access', Scope::global());

    return $u->fresh();
}

/** A user who holds admin.security.access per-user but is NOT a full admin → a bounded mask, for ceiling tests. */
function secKeyHolder(): User
{
    $u = Users::inGroups(['members']);
    grantUserKey($u, 'admin.security.access', Scope::global());

    return $u->fresh();
}

function grantUserKey(User $u, string $key, Scope $scope): void
{
    AclEntry::create([
        'permission_key' => $key, 'holder_type' => 'user', 'holder_id' => (int) $u->getKey(),
        'scope_type' => $scope->type, 'scope_id' => $scope->id, 'value' => PermissionValue::Allow->value,
    ]);
}

/** Authoritative (uncached) verdict — the resolver's explain() is the oracle the truth-table suite uses. */
function holds(User $u, string $key, Scope $scope): bool
{
    app(PermissionResolver::class)->flushMemo();

    return app(PermissionResolver::class)->explain($u->fresh(), $key, $scope)->granted;
}

function delegForum(): Forum
{
    return Forum::create(['slug' => 'gd', 'title' => 'General Discussion', 'type' => 'forum']);
}

// ── grant: happy path ─────────────────────────────────────────────────────────────────────────────────────

it('grants a time-boxed capability the co-owner holds, and the recipient resolves it', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $forum = delegForum();
    $scope = Scope::forum((int) $forum->id);

    expect($co->canDo(DK, $scope))->toBeTrue();           // precondition: the delegator holds it
    expect(holds($rec, DK, $scope))->toBeFalse();          // and the recipient does not — yet

    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));

    expect(holds($rec, DK, $scope))->toBeTrue();           // the recipient now resolves it
    expect($delegation->live()->whereKey($delegation->id)->exists())->toBeTrue();
    // The projection is ONE time-boxed user-holder ALLOW row — the resolver's only input.
    $row = AclEntry::query()->where('permission_key', DK)->where('holder_type', 'user')->where('holder_id', $rec->id)
        ->where('scope_type', 'forum')->where('scope_id', $forum->id)->first();
    expect($row)->not->toBeNull();
    expect((int) $row->value)->toBe(PermissionValue::Allow->value);
    expect($row->expires_at)->not->toBeNull();
});

// ── ceiling: delegate-what-you-don't-hold → rejected, ZERO rows ─────────────────────────────────────────────

it('refuses to delegate a key the delegator does not hold at the scope — and writes zero rows', function () {
    $d = secKeyHolder();           // holds the security key (passes the co-owner gate) but NOT topic.moderate
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);

    expect(fn () => svc()->grant($d, $rec, DK, $scope, now()->addDays(7)))->toThrow(RoleException::class);

    expect(Delegation::count())->toBe(0);
    expect(AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->exists())->toBeFalse();
});

it('refuses to delegate a key at a broader scope than the delegator holds it', function () {
    $d = secKeyHolder();
    $forum = delegForum();
    grantUserKey($d, DK, Scope::forum((int) $forum->id)); // holds it on THIS forum only
    $d = $d->fresh();
    $rec = Users::inGroups(['members']);

    // Holds it at forum scope → delegating at forum scope is fine …
    expect(fn () => svc()->grant($d, $rec, DK, Scope::forum((int) $forum->id), now()->addDays(3)))->not->toThrow(RoleException::class);
    // … but delegating GLOBALLY (broader) exceeds the ceiling.
    $rec2 = Users::inGroups(['members']);
    expect(fn () => svc()->grant($d, $rec2, DK, Scope::global(), now()->addDays(3)))->toThrow(RoleException::class);
});

// ── co-owner / Administration-tier keys are never delegable ─────────────────────────────────────────────────

it('refuses to delegate the co-owner security key or any Administration-tier key', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::global();

    expect(fn () => svc()->grant($co, $rec, 'admin.security.access', $scope, now()->addDays(1)))->toThrow(DelegationException::class);
    expect(fn () => svc()->grant($co, $rec, 'admin.access', $scope, now()->addDays(1)))->toThrow(DelegationException::class);
    expect(fn () => svc()->grant($co, $rec, 'users.manage', $scope, now()->addDays(1)))->toThrow(DelegationException::class);

    expect(Delegation::count())->toBe(0);
});

it('refuses a non-co-owner delegator', function () {
    $notCo = Users::inGroups(['members']);
    $rec = Users::inGroups(['members']);

    expect(fn () => svc()->grant($notCo, $rec, DK, Scope::global(), now()->addDays(1)))->toThrow(DelegationException::class);
    expect(Delegation::count())->toBe(0);
});

// ── 30-day cap ──────────────────────────────────────────────────────────────────────────────────────────────

it('clamps a longer-than-30-day request to the 30-day cap', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);

    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(100));

    expect($delegation->expires_at->lessThanOrEqualTo(now()->addDays(DelegationService::MAX_DAYS)->addMinute()))->toBeTrue();
    expect($delegation->expires_at->greaterThan(now()->addDays(DelegationService::MAX_DAYS)->subMinute()))->toBeTrue();
    // The projected acl row carries the SAME capped expiry.
    $row = AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->first();
    expect($row->expires_at->equalTo($delegation->expires_at))->toBeTrue();
});

it('refuses a window that is not in the future', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    expect(fn () => svc()->grant($co, $rec, DK, Scope::global(), now()->subDay()))->toThrow(DelegationException::class);
});

// ── revoke: recipient can() flips false AND AclVersion bumped ────────────────────────────────────────────────

it('revokes a delegation: the recipient loses it and AclVersion is bumped', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);
    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));
    expect(holds($rec, DK, $scope))->toBeTrue();

    $versionBefore = app(AclVersion::class)->current();
    svc()->revoke($co, $delegation);

    expect(holds($rec, DK, $scope))->toBeFalse();                                   // explain() (uncached) flips
    app(PermissionResolver::class)->flushMemo();
    expect(app(PermissionResolver::class)->can($rec->fresh(), DK, $scope))->toBeFalse(); // cached can() flips too
    expect(app(AclVersion::class)->current())->toBeGreaterThan($versionBefore);     // G9: the delete bumped
    expect($delegation->fresh()->revoked_at)->not->toBeNull();                      // provenance kept as history
    expect(AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->exists())->toBeFalse();
});

// ── auto-expiry seam: the cross-boundary can() flip needs NO prune run ───────────────────────────────────────

it('auto-expires via the resolver filter with no prune run, then the prune sweeps the dead row', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);
    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));
    expect(holds($rec, DK, $scope))->toBeTrue();

    $this->travel(8)->days(); // past the expiry — NO prune has run

    expect(holds($rec, DK, $scope))->toBeFalse();   // the resolver's expires_at filter drops the lapsed row
    // The rows still physically exist (prune has not run) — honouring did not depend on it.
    expect(AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->exists())->toBeTrue();
    expect($delegation->fresh()->revoked_at)->toBeNull();

    $this->artisan('novfora:acl:prune-expired')->assertSuccessful();

    // The prune hard-deleted the lapsed acl row; the delegation row stays as audit history.
    expect(AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->exists())->toBeFalse();
    expect(Delegation::whereKey($delegation->id)->exists())->toBeTrue();
});

// ── no-clobber: never time-box a permanent grant, never lift a NEVER ─────────────────────────────────────────

it('refuses to delegate onto a cell the recipient already holds permanently (no clobber)', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $forum = delegForum();
    $scope = Scope::forum((int) $forum->id);
    grantUserKey($rec, DK, $scope); // a PERMANENT user grant (e.g. a forum-moderator assignment)
    $rec = $rec->fresh();

    expect(fn () => svc()->grant($co, $rec, DK, $scope, now()->addDays(7)))->toThrow(DelegationException::class);
    expect(Delegation::count())->toBe(0);
    // The permanent grant is untouched — still has no TTL.
    $row = AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')->first();
    expect($row->expires_at)->toBeNull();
});

it('refuses to delegate over a recipient NEVER (cannot override a hard-deny)', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);
    AclEntry::create([
        'permission_key' => DK, 'holder_type' => 'user', 'holder_id' => (int) $rec->id,
        'scope_type' => $scope->type, 'scope_id' => $scope->id, 'value' => PermissionValue::Never->value,
    ]);

    expect(fn () => svc()->grant($co, $rec, DK, $scope, now()->addDays(7)))->toThrow(DelegationException::class);
    expect(Delegation::count())->toBe(0);
});

it('a later permanent role expansion onto a delegated cell clears the TTL, and revoke then spares it', function () {
    // The REVERSE ordering of the no-clobber hazard: a delegation TTL row exists, THEN the recipient is made a
    // permanent holder of the same key at the same cell via a role expansion (e.g. assigned a forum moderator).
    // acl_entries has no unique cell constraint, so RoleExpander's updateOrCreate lands on the delegation row;
    // it must write expires_at = null so the now-permanent grant is not silently time-boxed (ADR-0087).
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $forum = delegForum();
    $scope = Scope::forum((int) $forum->id);
    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));

    $role = Role::create(['slug' => 'temp-mod-role', 'name' => 'Temp Mod', 'is_preset' => false]);
    RolePermission::create(['role_id' => $role->id, 'permission_key' => DK, 'value' => PermissionValue::Allow->value]);
    app(RoleExpander::class)->assign($role, 'user', (int) $rec->id, $scope);

    // The cell is now a PERMANENT grant — the TTL was cleared, not inherited.
    $row = AclEntry::query()->where('permission_key', DK)->where('holder_type', 'user')->where('holder_id', $rec->id)
        ->where('scope_type', 'forum')->where('scope_id', $forum->id)->first();
    expect($row->expires_at)->toBeNull();

    // It survives BOTH the delegation's expiry and an early revoke (the permanent grant is authoritative).
    $this->travel(8)->days();
    expect(holds($rec, DK, $scope))->toBeTrue();
    $this->travelBack();
    svc()->revoke($co, $delegation);
    expect(holds($rec->fresh(), DK, $scope))->toBeTrue();
});

it('revoke deletes only the time-boxed row, never a permanent grant at the same cell', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $forum = delegForum();
    $scope = Scope::forum((int) $forum->id);
    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));

    // A PERMANENT user grant lands at the same cell AFTER the delegation (e.g. a later forum-mod assignment).
    grantUserKey($rec, DK, $scope);

    svc()->revoke($co, $delegation);

    // The delegation's TTL row is gone, but the permanent grant survives → the recipient still holds the key.
    expect(AclEntry::query()->where('permission_key', DK)->where('holder_id', $rec->id)->where('holder_type', 'user')
        ->whereNotNull('expires_at')->exists())->toBeFalse();
    expect(holds($rec->fresh(), DK, $scope))->toBeTrue();
});

// ── cascade: a delegation never outlives the delegator's current mask ────────────────────────────────────────

it('cascade-revokes a delegation once the delegator no longer holds the key', function () {
    // A delegator whose delegable key comes from a CUSTOM group (so we can take it away), plus the security key.
    $group = Group::create(['slug' => 'temp-mods', 'name' => 'Temp Mods', 'type' => 'custom']);
    AclEntry::create([
        'permission_key' => DK, 'holder_type' => 'group', 'holder_id' => (int) $group->id,
        'scope_type' => 'global', 'scope_id' => null, 'value' => PermissionValue::Allow->value,
    ]);
    $d = Users::inGroups(['temp-mods']);
    grantUserKey($d, 'admin.security.access', Scope::global());
    $d = $d->fresh();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);

    expect($d->canDo(DK, $scope))->toBeTrue();
    $delegation = svc()->grant($d, $rec, DK, $scope, now()->addDays(7));
    expect(holds($rec, DK, $scope))->toBeTrue();

    // Drop the delegator below the key by removing them from the granting group — the REAL demotion path.
    app(GroupManager::class)->removeMember($group, (int) $d->id);

    expect($delegation->fresh()->revoked_at)->not->toBeNull();   // cascade fired
    expect(holds($rec, DK, $scope))->toBeFalse();                // and the recipient lost it
});

it('cascade leaves intact a delegation whose key the delegator still holds', function () {
    $co = coOwner();
    $rec = Users::inGroups(['members']);
    $scope = Scope::forum((int) delegForum()->id);
    $delegation = svc()->grant($co, $rec, DK, $scope, now()->addDays(7));

    svc()->cascadeForActor($co->fresh()); // the co-owner still holds DK via the admin preset → no-op

    expect($delegation->fresh()->revoked_at)->toBeNull();
    expect(holds($rec, DK, $scope))->toBeTrue();
});

// ── the SFC: co-owner-gated, and the round-trip works ────────────────────────────────────────────────────────

it('403s the Active Delegations route for a non-co-owner and renders it for a co-owner', function () {
    $member = Users::inGroups(['members']);
    $this->actingAs($member)->get(route('admin.security.delegations'))->assertForbidden();

    $co = Users::withTwoFactor(coOwner());
    $this->actingAs($co)->get(route('admin.security.delegations'))->assertOk()->assertSee('Active delegations');
});

it('grants and revokes through the Livewire panel', function () {
    $co = Users::withTwoFactor(coOwner());
    $rec = Users::inGroups(['members'], ['username' => 'jamie']);
    $forum = delegForum();
    $scope = Scope::forum((int) $forum->id);

    $component = Livewire::actingAs($co)->test('admin.security.active-delegations')
        ->set('recipientRef', 'jamie')
        ->set('permission', DK)
        ->set('scopeRef', 'forum:'.$forum->id)
        ->set('days', 7)
        ->call('grant')
        ->assertSet('messageVariant', 'success');

    expect(holds($rec, DK, $scope))->toBeTrue();
    $delegation = Delegation::query()->where('recipient_id', $rec->id)->firstOrFail();

    $component->call('revoke', $delegation->id)->assertSet('messageVariant', 'success');
    expect(holds($rec, DK, $scope))->toBeFalse();
});
