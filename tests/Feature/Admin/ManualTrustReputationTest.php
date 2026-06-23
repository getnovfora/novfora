<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\AntiSpam\TrustLevelManager;
use App\Models\AuditLog;
use App\Models\Forum;
use App\Models\ReputationEvent;
use App\Models\User;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue as V;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Acl;
use Tests\Support\Users;

/*
| v1.x F2 (APEX · ADR-0101) — manual trust + reputation editing on the ⚡admin.members.manage screen. New
| capability keys members.trust.manage / members.reputation.manage gate the two actions; each runs the rank
| guard, audits every change, flushes the membership/resolver cache (trust ↔ effective permissions), keeps a
| manual trust set STICKY against the auto-recompute, and routes reputation through the existing ledger.
*/

uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

function trustRepAdmin(): User
{
    return Users::withTwoFactor(Users::inGroups(['admins'])); // the administrator preset holds both F2 keys
}

/** A restricted admin: admin.members.access but NO members.trust.manage / members.reputation.manage (no 2FA). */
function restrictedTrustAdmin(Acl $acl): User
{
    $grp = $acl->group('traviewer', ['priority' => 40]);
    $acl->grant($grp, 'admin.access', $acl->global, V::Allow);
    $acl->grant($grp, 'admin.members.access', $acl->global, V::Allow);

    return $acl->user(['traviewer']);
}

// ── Trust level: gated, audited, sticky ──────────────────────────────────────────────────────────────────

it('lets a holder set a member trust level (audited, locked); 403s an admin without the key', function () {
    $target = Users::inGroups(['members', 'tl0'], ['username' => 'climber']);

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('trustLevel', 3)
        ->set('trustReason', 'long-standing helper')
        ->call('setTrustLevel')
        ->assertHasNoErrors();

    expect($target->fresh()->trust_level)->toBe(3)
        ->and($target->fresh()->trust_locked)->toBeTrue()
        ->and($target->fresh()->trustLevel())->toBe(3); // the tl3 GROUP is now authoritative

    $audit = AuditLog::where('action', 'user.trust.manual_set')->where('auditable_id', $target->id)->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changes['from'])->toBe(0)
        ->and($audit->changes['to'])->toBe(3)
        ->and($audit->changes['reason'])->toBe('long-standing helper');

    // A restricted admin without members.trust.manage cannot set it.
    $acl = Acl::make();
    Livewire::actingAs(restrictedTrustAdmin($acl))->test('admin.members.manage', ['userId' => $target->id])
        ->set('trustLevel', 0)->call('setTrustLevel')->assertForbidden();
    expect($target->fresh()->trust_level)->toBe(3); // unchanged
});

it('flips effective trust-gated permissions after a manual set (cache invalidation → inspector verdict)', function () {
    $forum = Forum::create(['slug' => 'trf', 'title' => 'TR Forum', 'type' => 'forum']);
    $scope = $forum->permissionScope();
    $target = Users::inGroups(['members', 'tl0'], ['username' => 'flipme']);

    expect($target->canDo('post.links', $scope))->toBeFalse(); // TL0 hard-gates links (NEVER)

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('trustLevel', 1)->call('setTrustLevel')->assertHasNoErrors();

    app(PermissionResolver::class)->flushMemo();
    expect($target->fresh()->canDo('post.links', $scope))->toBeTrue(); // TL1 ALLOW — the resolved verdict flipped
});

it('keeps a manual override sticky: the auto-recompute does not structurally demote below it', function () {
    // A member with no posts/tenure earns only TL0 organically, but is manually lifted to TL3.
    $target = Users::inGroups(['members', 'tl0'], ['username' => 'sticky']);

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('trustLevel', 3)->call('setTrustLevel')->assertHasNoErrors();
    expect($target->fresh()->trust_level)->toBe(3);

    // The cron recompute must NOT undo the admin's deliberate TL3 (they'd otherwise earn TL0).
    app(TrustLevelManager::class)->recompute($target->fresh());
    expect($target->fresh()->trust_level)->toBe(3);

    // Control: an UNLOCKED member sitting at TL3 with nothing earned recomputes back down to TL0 — proving the
    // lock (not some other quirk) is what holds the override.
    $unlocked = Users::inGroups(['members'], ['username' => 'unlocked']);
    $unlocked->forceFill(['trust_level' => 3, 'trust_locked' => false])->save();
    app(TrustLevelManager::class)->recompute($unlocked->fresh());
    expect($unlocked->fresh()->trust_level)->toBe(0);
});

// ── Reputation: through the ledger, audited, signed ──────────────────────────────────────────────────────

it('applies a signed reputation adjustment through the ledger (audited); 403s without the key', function () {
    $target = Users::inGroups(['members'], ['username' => 'repsubject']);
    $before = (int) $target->reputation_points;

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '7')
        ->set('repReason', 'great contributions')
        ->call('adjustReputation')
        ->assertHasNoErrors();

    expect($target->fresh()->reputation_points)->toBe($before + 7)
        ->and(ReputationEvent::where('user_id', $target->id)->where('points', 7)->exists())->toBeTrue(); // ledger row, not a side write

    $audit = AuditLog::where('action', 'user.reputation.admin_adjusted')->where('auditable_id', $target->id)->latest('id')->first();
    expect($audit)->not->toBeNull()
        ->and($audit->changes['delta'])->toBe(7)
        ->and($audit->changes['reason'])->toBe('great contributions');

    // A negative delta decrements (still through the ledger).
    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '-3')->set('repReason', 'minor penalty')->call('adjustReputation')->assertHasNoErrors();
    expect($target->fresh()->reputation_points)->toBe($before + 4)
        ->and(ReputationEvent::where('user_id', $target->id)->where('points', -3)->exists())->toBeTrue();

    // A restricted admin without members.reputation.manage cannot.
    $acl = Acl::make();
    Livewire::actingAs(restrictedTrustAdmin($acl))->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '100')->set('repReason', 'no')->call('adjustReputation')->assertForbidden();
    expect($target->fresh()->reputation_points)->toBe($before + 4); // unchanged
});

it('rejects a zero or reasonless reputation adjustment (signed delta + required reason)', function () {
    $target = Users::inGroups(['members'], ['username' => 'repvalidate']);

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '0')->set('repReason', 'has reason')->call('adjustReputation')->assertHasErrors('repDelta');

    Livewire::actingAs(trustRepAdmin())->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '5')->set('repReason', '')->call('adjustReputation')->assertHasErrors('repReason');

    expect((int) $target->fresh()->reputation_points)->toBe(0);
});

// ── Rank guard (no escalation beyond the actor's ceiling) ────────────────────────────────────────────────

it('enforces the rank guard: a lower-ranked holder cannot edit a higher-ranked member', function () {
    $acl = Acl::make();
    $grp = $acl->group('lowtrustmgr', ['priority' => 10]);
    foreach (['admin.access', 'admin.members.access', 'members.trust.manage', 'members.reputation.manage'] as $key) {
        $acl->grant($grp, $key, $acl->global, V::Allow);
    }
    $actor = $acl->user(['lowtrustmgr']);
    $acl->group('toprank', ['priority' => 100]);
    $target = $acl->user(['toprank']);

    Livewire::actingAs($actor)->test('admin.members.manage', ['userId' => $target->id])
        ->set('trustLevel', 0)->call('setTrustLevel')->assertForbidden();
    Livewire::actingAs($actor)->test('admin.members.manage', ['userId' => $target->id])
        ->set('repDelta', '5')->set('repReason', 'x')->call('adjustReputation')->assertForbidden();

    expect((int) $target->fresh()->reputation_points)->toBe(0);
});
