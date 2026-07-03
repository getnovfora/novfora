<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Admin\DelegationService;
use App\Admin\GroupManager;
use App\Jobs\CascadeDelegationsJob;
use App\Models\AclEntry;
use App\Models\Delegation;
use App\Models\Role;
use App\Models\User;
use App\Permissions\GroupPermissionEditor;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use App\Permissions\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;
use Tests\Support\Users;

/**
 * NOV-121 door #2 — the GroupPermissionEditor delegation fan-out (closes the ADR-0087 bounded gap). When a
 * group's standing mask is edited DOWN, a member who delegated a capability that flowed from that group must have
 * the delegation re-checked (and revoked if it now exceeds their reduced CURRENT mask) — the same invariant
 * GroupManager::removeMember already honours on the admins-removal door. Bounded to actual delegators + queued.
 */
uses(RefreshDatabase::class);

beforeEach(fn () => $this->seed());

/** A uniquely-named manager accessor (Pest declares test-file functions globally — avoid colliding with gm()). */
function fanoutGm(): GroupManager
{
    return app(GroupManager::class);
}

/** Construct the on-disk state of "$delegator delegated $key at global to $recipient" (provenance row + TTL grant). */
function seedDelegation(User $delegator, User $recipient, string $key = 'topic.moderate'): Delegation
{
    $delegation = Delegation::create([
        'delegator_id' => (int) $delegator->id,
        'recipient_id' => (int) $recipient->id,
        'permission_key' => $key,
        'scope_type' => 'global',
        'scope_id' => null,
        'expires_at' => now()->addDays(10),
    ]);

    AclEntry::create([
        'permission_key' => $key,
        'holder_type' => 'user',
        'holder_id' => (int) $recipient->id,
        'scope_type' => 'global',
        'scope_id' => null,
        'value' => PermissionValue::Allow->value,
        'expires_at' => now()->addDays(10),
    ]);

    return $delegation;
}

it('cascadeForGroups revokes a delegation stranded when the delegator’s group loses the key (ADR-0087 gap closed)', function () {
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $g = fanoutGm()->create(['name' => 'Delegators', 'role_id' => $moderator->id]); // grants topic.moderate

    // The delegator's ONLY source of topic.moderate is $g (plain member otherwise).
    $delegator = Users::inGroups(['members']);
    fanoutGm()->addMembers($g, [(int) $delegator->id]);
    app(PermissionResolver::class)->flushMemo();
    expect($delegator->fresh()->canDo('topic.moderate', Scope::global()))->toBeTrue();

    $recipient = Users::inGroups(['members']);
    $delegation = seedDelegation($delegator, $recipient);
    app(PermissionResolver::class)->flushMemo();
    expect($recipient->fresh()->canDo('topic.moderate', Scope::global()))->toBeTrue();

    // Reduce $g's mask — remove topic.moderate. The delegator (no other source) loses it.
    app(GroupPermissionEditor::class)->set($g, 'topic.moderate', Scope::global(), 'no');
    app(PermissionResolver::class)->flushMemo();
    expect($delegator->fresh()->canDo('topic.moderate', Scope::global()))->toBeFalse();

    // Fan out — the delegation now exceeds the delegator's current mask, so it is revoked and the grant torn down.
    app(DelegationService::class)->cascadeForGroups([(int) $g->id]);

    expect($delegation->fresh()->revoked_at)->not->toBeNull();
    app(PermissionResolver::class)->flushMemo();
    expect($recipient->fresh()->canDo('topic.moderate', Scope::global()))->toBeFalse();
});

it('cascadeForGroups keeps a delegation still backed by another of the delegator’s groups', function () {
    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $g = fanoutGm()->create(['name' => 'Delegators', 'role_id' => $moderator->id]);

    // The delegator ALSO holds topic.moderate via the moderators system group — a second, independent source.
    $delegator = Users::inGroups(['moderators']);
    fanoutGm()->addMembers($g, [(int) $delegator->id]);

    $recipient = Users::inGroups(['members']);
    $delegation = seedDelegation($delegator, $recipient);

    // Remove $g's grant — the delegator STILL holds topic.moderate via moderators, so nothing is stranded.
    app(GroupPermissionEditor::class)->set($g, 'topic.moderate', Scope::global(), 'no');
    app(PermissionResolver::class)->flushMemo();
    expect($delegator->fresh()->canDo('topic.moderate', Scope::global()))->toBeTrue();

    app(DelegationService::class)->cascadeForGroups([(int) $g->id]);

    expect($delegation->fresh()->revoked_at)->toBeNull();
});

it('onGroupMaskChanged enqueues the bounded job ONLY when an affected group has a live delegator', function () {
    Bus::fake();
    $g = fanoutGm()->create(['name' => 'Plain']);
    $member = Users::inGroups(['members']);
    fanoutGm()->addMembers($g, [(int) $member->id]);

    // No delegations in play → a routine permission save must NOT touch the queue.
    app(DelegationService::class)->onGroupMaskChanged([(int) $g->id]);
    Bus::assertNotDispatched(CascadeDelegationsJob::class);

    // Give the member a live delegation → a reduction on $g must now enqueue the bounded fan-out for $g.
    seedDelegation($member, Users::inGroups(['members']));
    app(DelegationService::class)->onGroupMaskChanged([(int) $g->id]);
    Bus::assertDispatched(
        CascadeDelegationsJob::class,
        fn (CascadeDelegationsJob $job) => in_array((int) $g->id, $job->groupIds, true),
    );
});

it('the permission-editor SFC fans out the re-check after a reduction (wiring)', function () {
    Bus::fake();
    $this->actingAs(Users::withTwoFactor(Users::inGroups(['admins'])));

    $moderator = Role::where('slug', 'moderator')->firstOrFail();
    $g = fanoutGm()->create(['name' => 'Delegators', 'role_id' => $moderator->id]);
    $delegator = Users::inGroups(['members']);
    fanoutGm()->addMembers($g, [(int) $delegator->id]);
    seedDelegation($delegator, Users::inGroups(['members']));

    // A reduction ('never') through the card editor must notify the delegation engine for $g.
    Livewire::test('permissions.group-editor')
        ->call('setState', (int) $g->id, 'topic.moderate', 'never')
        ->assertHasNoErrors();

    Bus::assertDispatched(
        CascadeDelegationsJob::class,
        fn (CascadeDelegationsJob $job) => in_array((int) $g->id, $job->groupIds, true),
    );

    // A pure ADDITION ('yes') is not a reduction → no fan-out enqueued.
    Bus::fake();
    Livewire::test('permissions.group-editor')
        ->call('setState', (int) $g->id, 'topic.moderate', 'yes')
        ->assertHasNoErrors();
    Bus::assertNotDispatched(CascadeDelegationsJob::class);
});
