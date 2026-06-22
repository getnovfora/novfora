<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Account\AccountDeletionException;
use App\Account\AccountDeletionService;
use App\Admin\AdminCoOwnerException;
use App\Admin\AdminCoOwnerService;
use App\AntiSpam\SpamCleaner;
use App\AntiSpam\WarningService;
use App\Forum\PostService;
use App\Models\AclEntry;
use App\Models\AuditLog;
use App\Models\Ban;
use App\Models\Forum;
use App\Models\Group;
use App\Models\Topic;
use App\Models\User;
use App\Models\Warning;
use App\Models\WarningType;
use App\Moderation\OwnerStrandException;
use App\Moderation\OwnerStrandGuard;
use App\Permissions\PermissionResolver;
use App\Permissions\PermissionValue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Content;
use Tests\Support\Users;

/*
| The OWNER-STRAND GUARD (apex, ADR-0100): no effective ban/suspend may strand the owner tier — the sole admin
| or the sole co-owner. A banned owner is blocked BEFORE ACL resolution, so they can never reach the panel to
| lift their own ban; the guard is the actor-independent, TOCTOU-safe backstop on every ban door (the bans
| page, the warning auto-consequence, the spam cleaner).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    app(PermissionResolver::class)->flushMemo();
    $this->seed();
});

function s5AdminsGroupId(): int
{
    return (int) Group::query()->where('slug', 'admins')->value('id');
}

function s5GrantBansManage(User $user): void
{
    AclEntry::updateOrCreate(
        ['permission_key' => 'bans.manage', 'holder_type' => 'user', 'holder_id' => (int) $user->getKey(),
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );
    app(PermissionResolver::class)->flushMemo();
}

/** Crown a co-owner exactly as the installer does: admins membership + the is_co_owner flag + the security grant. */
function s5CrownCoOwner(User $u): User
{
    $u->groups()->updateExistingPivot(s5AdminsGroupId(), ['is_co_owner' => true]);
    AclEntry::updateOrCreate(
        ['permission_key' => 'admin.security.access', 'holder_type' => 'user', 'holder_id' => (int) $u->getKey(),
            'scope_type' => 'global', 'scope_id' => null],
        ['value' => PermissionValue::Allow->value],
    );

    return $u->fresh();
}

/** The forum's ONLY owner: the lone admins-group member, also the sole co-owner. */
function s5SoleOwner(): User
{
    return s5CrownCoOwner(Users::inGroups(['admins']));
}

function s5SpamWarning(): WarningType
{
    return WarningType::where('slug', 'spam')->firstOrFail(); // 10 points each
}

// ── The guard predicate itself ────────────────────────────────────────────────────────────────────────────

it('refuses to ban the sole co-owner (also the last admin)', function () {
    $owner = s5SoleOwner();

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($owner))->toBeTrue();
    expect(fn () => app(OwnerStrandGuard::class)->assertBanWontStrandOwnerTier($owner))
        ->toThrow(OwnerStrandException::class);
});

it('refuses to ban the LAST admin even when they are not a co-owner', function () {
    $lastAdmin = Users::inGroups(['admins']); // a lone admin, never crowned co-owner

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($lastAdmin))->toBeTrue();
});

it('refuses to ban the SOLE co-owner even when other plain admins remain', function () {
    $owner = s5CrownCoOwner(Users::inGroups(['admins']));
    Users::inGroups(['admins']); // a second, plain admin — the co-owner is still the SOLE owner

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($owner))->toBeTrue();
});

it('ALLOWS banning a co-owner who has a peer co-owner', function () {
    $a = s5CrownCoOwner(Users::inGroups(['admins']));
    $b = s5CrownCoOwner(Users::inGroups(['admins']));

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($a))->toBeFalse();
    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($b))->toBeFalse();
    expect(fn () => app(OwnerStrandGuard::class)->assertBanWontStrandOwnerTier($a))->not->toThrow(OwnerStrandException::class);
});

it('ALLOWS banning a non-last admin and is wholly inert for ordinary users', function () {
    $admin1 = Users::inGroups(['admins']);
    Users::inGroups(['admins']); // a peer admin
    $ordinary = Users::inGroups(['members', 'tl1']);

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($admin1))->toBeFalse();
    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($ordinary))->toBeFalse();
});

it('counts only REACHABLE owners — a peer already BANNED no longer holds the tier open (HIGH#1 root)', function () {
    $a = s5CrownCoOwner(Users::inGroups(['admins']));
    $b = s5CrownCoOwner(Users::inGroups(['admins']));

    // Ban A for real — the committed state a prior (serialised) ban transaction would leave.
    $a->forceFill(['status' => 'banned'])->save();
    Ban::create(['user_id' => $a->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x']);

    // B is now the LAST reachable owner: banning OR removing B must be refused, even though group_user still
    // shows two co-owner rows (the bug the review caught: a ban never mutates group_user).
    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($b->fresh()))->toBeTrue();
    // Re-banning the already-banned A is inert and does NOT strand (B is still reachable).
    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($a->fresh()))->toBeFalse();
});

it('treats a live TEMP ban (ban row, status unchanged) as effectively banned (HIGH#1 temp leg)', function () {
    $a = s5CrownCoOwner(Users::inGroups(['admins']));
    $b = s5CrownCoOwner(Users::inGroups(['admins']));

    // The WarningService temp_ban shape: a live global ban row, status left active.
    Ban::create(['user_id' => $a->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x', 'expires_at' => now()->addDays(7)]);
    expect($a->fresh()->status)->not->toBe('banned');

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($b->fresh()))->toBeTrue();
});

it('an EXPIRED ban does NOT count as effectively banned (the owner is reachable again)', function () {
    $a = s5CrownCoOwner(Users::inGroups(['admins']));
    $b = s5CrownCoOwner(Users::inGroups(['admins']));

    Ban::create(['user_id' => $a->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x', 'expires_at' => now()->subDay()]);

    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($b->fresh()))->toBeFalse();
});

it('refuses, at the route, the SECOND ban that would leave only banned owners (HIGH#1 end-to-end)', function () {
    $actor = Users::inGroups(['admins']);
    s5GrantBansManage($actor);
    $a = s5CrownCoOwner(Users::inGroups(['admins']));
    $b = s5CrownCoOwner(Users::inGroups(['admins']));

    // First ban (A) is legitimate — B is a healthy peer.
    $this->actingAs($actor)->post(route('bans.store'), ['type' => 'user', 'user_id' => $a->id, 'reason' => 'x'])->assertRedirect();
    expect($a->fresh()->status)->toBe('banned');

    // Second ban (B) would leave BOTH co-owners banned — refused by the now ban-aware guard.
    $this->actingAs($actor)->post(route('bans.store'), ['type' => 'user', 'user_id' => $b->id, 'reason' => 'x'])
        ->assertRedirect()->assertSessionHas('error');
    expect($b->fresh()->status)->not->toBe('banned');
});

it('refuses to DELETE the last reachable owner while a peer is banned (HIGH#2 delete door)', function () {
    $alice = s5CrownCoOwner(Users::inGroups(['admins']));
    $bob = s5CrownCoOwner(Users::inGroups(['admins']));
    $bob->forceFill(['status' => 'banned'])->save();
    Ban::create(['user_id' => $bob->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x']);

    expect(fn () => app(AccountDeletionService::class)->deleteOwnAccount($alice->fresh()))
        ->toThrow(AccountDeletionException::class);
    expect(User::find($alice->id))->not->toBeNull();
});

it('refuses to DEMOTE the last reachable co-owner while a peer is banned (HIGH#2 demote door)', function () {
    $alice = s5CrownCoOwner(Users::inGroups(['admins']));
    $bob = s5CrownCoOwner(Users::inGroups(['admins']));
    $bob->forceFill(['status' => 'banned'])->save();
    Ban::create(['user_id' => $bob->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x']);

    expect(fn () => app(AdminCoOwnerService::class)->revoke($alice->fresh(), $alice->fresh()))
        ->toThrow(AdminCoOwnerException::class);
    expect(app(AdminCoOwnerService::class)->isCoOwner($alice->fresh()))->toBeTrue();
});

it('ALLOWS removing the BANNED owner itself while a healthy peer remains (no over-block)', function () {
    $alice = s5CrownCoOwner(Users::inGroups(['admins'])); // healthy
    $bob = s5CrownCoOwner(Users::inGroups(['admins']));
    $bob->forceFill(['status' => 'banned'])->save();
    Ban::create(['user_id' => $bob->id, 'type' => 'user', 'scope_type' => 'global', 'reason' => 'x']);

    expect(app(OwnerStrandGuard::class)->wouldStrandCoOwnerTierLocked((int) $bob->id))->toBeFalse();
    expect(app(OwnerStrandGuard::class)->wouldStrandAdminTierLocked((int) $bob->id))->toBeFalse();
});

it('re-reads LIVE state under the lock, not a stale snapshot (TOCTOU)', function () {
    $owner = s5CrownCoOwner(Users::inGroups(['admins']));
    $second = s5CrownCoOwner(Users::inGroups(['admins']));
    // With two co-owners, banning either is fine right now.
    expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($owner))->toBeFalse();

    // Simulate a CONCURRENT demotion committing: $second loses co-ownership via a direct DB write (no model
    // refresh on $owner). The in-transaction locked re-read must see the now-lone owner, not the start snapshot.
    DB::table('group_user')->where('user_id', $second->id)->where('group_id', s5AdminsGroupId())
        ->update(['is_co_owner' => false]);

    DB::transaction(function () use ($owner) {
        expect(app(OwnerStrandGuard::class)->wouldStrandOwnerTierLocked($owner))->toBeTrue();
        expect(fn () => app(OwnerStrandGuard::class)->assertBanWontStrandOwnerTier($owner))
            ->toThrow(OwnerStrandException::class);
    });
});

// ── The INDIRECT door: warning auto-consequence (the real trap) ───────────────────────────────────────────

it('SUPPRESSES a warn-to-auto-ban on the sole owner but still records the warning', function () {
    $owner = s5SoleOwner();
    $mod = Users::inGroups(['moderators']);

    $last = null;
    foreach (range(1, 3) as $i) { // 3 × 10 = 30 ≥ ban threshold
        $last = app(WarningService::class)->issue($mod, $owner, s5SpamWarning());
    }

    // The owner is NOT banned — no status flip, no ban row — yet every warning is on the record.
    expect($owner->fresh()->status)->not->toBe('banned');
    expect(Ban::where('user_id', $owner->id)->where('type', 'user')->exists())->toBeFalse();
    expect(Warning::where('user_id', $owner->id)->count())->toBe(3);

    // The suppression is recorded on the warning and audited.
    expect($last->fresh()->action_taken)->toMatchArray(['action' => 'ban', 'suppressed' => 'owner_strand']);
    expect(AuditLog::where('action', 'warning.consequence_suppressed')->exists())->toBeTrue();
});

it('SUPPRESSES a warn-to-auto-TEMP-ban on the sole owner', function () {
    $owner = s5SoleOwner();
    $mod = Users::inGroups(['moderators']);

    $last = null;
    foreach (range(1, 2) as $i) { // 2 × 10 = 20 ≥ temp_ban (15), < ban (30)
        $last = app(WarningService::class)->issue($mod, $owner, s5SpamWarning());
    }

    expect($owner->fresh()->status)->not->toBe('banned');
    expect(Ban::where('user_id', $owner->id)->where('type', 'user')->exists())->toBeFalse();
    expect($last->fresh()->action_taken)->toMatchArray(['action' => 'temp_ban', 'suppressed' => 'owner_strand']);
});

it('SUPPRESSES a warn-to-auto-ban on the sole co-owner even when other admins remain', function () {
    $owner = s5CrownCoOwner(Users::inGroups(['admins']));
    Users::inGroups(['admins']); // a peer admin — the co-owner is still the SOLE owner
    $mod = Users::inGroups(['moderators']);

    foreach (range(1, 3) as $i) {
        app(WarningService::class)->issue($mod, $owner, s5SpamWarning());
    }

    expect($owner->fresh()->status)->not->toBe('banned');
    expect(Ban::where('user_id', $owner->id)->where('type', 'user')->exists())->toBeFalse();
});

it('still bans an ORDINARY member who crosses the ban threshold (the guard is surgical)', function () {
    $target = Users::inGroups(['members', 'tl1']);
    $mod = Users::inGroups(['moderators']);

    foreach (range(1, 3) as $i) {
        app(WarningService::class)->issue($mod, $target, s5SpamWarning());
    }

    expect($target->fresh()->status)->toBe('banned');
    expect(Ban::where('user_id', $target->id)->where('type', 'user')->exists())->toBeTrue();
});

// ── The DIRECT door: the bans page (BanController) ────────────────────────────────────────────────────────

it('refuses, at the route, an admin banning the sole co-owner — the backstop beyond the rank guard', function () {
    // A plain admin OUTRANKS no one specially, but ActorRank lets any admin act on any admin — so the rank
    // guard passes and ONLY the owner-strand backstop stands between this actor and a forum-fatal ban.
    $actor = Users::inGroups(['admins']);
    s5GrantBansManage($actor);
    $owner = s5CrownCoOwner(Users::inGroups(['admins'])); // sole co-owner; a second admin ($actor) exists

    $this->actingAs($actor)
        ->post(route('bans.store'), ['type' => 'user', 'user_id' => $owner->id, 'reason' => 'x'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($owner->fresh()->status)->not->toBe('banned');
    expect(Ban::where('user_id', $owner->id)->where('type', 'user')->exists())->toBeFalse();
});

it('ALLOWS, at the route, a co-owner banning a peer co-owner', function () {
    $actor = s5CrownCoOwner(Users::inGroups(['admins']));
    s5GrantBansManage($actor);
    $peer = s5CrownCoOwner(Users::inGroups(['admins']));

    $this->actingAs($actor)
        ->post(route('bans.store'), ['type' => 'user', 'user_id' => $peer->id, 'reason' => 'x'])
        ->assertRedirect();

    expect($peer->fresh()->status)->toBe('banned');
    expect(Ban::where('user_id', $peer->id)->where('type', 'user')->exists())->toBeTrue();
});

it('leaves the ordinary staff ban path untouched (regression)', function () {
    $target = Users::inGroups(['members', 'tl1']);

    $this->actingAs(Users::inGroups(['moderators']))
        ->post(route('bans.store'), ['type' => 'user', 'user_id' => $target->id, 'reason' => 'spam'])
        ->assertRedirect();

    expect($target->fresh()->status)->toBe('banned');
});

// ── The DIRECT door: the spam cleaner ─────────────────────────────────────────────────────────────────────

it('refuses to spam-clean the sole owner and rolls back — no content deleted, no ban', function () {
    $forum = Forum::create(['slug' => 'general', 'title' => 'General', 'type' => 'forum']);
    $owner = s5SoleOwner();
    $topic = app(PostService::class)->createTopic($owner->fresh(), $forum, 'T', 'tiptap_json', Content::doc('op'));

    expect(fn () => app(SpamCleaner::class)->clean(Users::inGroups(['admins']), $owner, 'spam'))
        ->toThrow(OwnerStrandException::class);

    // The whole transaction rolled back: the topic is still live and the owner is not banned.
    expect(Topic::find($topic->id))->not->toBeNull();
    expect($owner->fresh()->status)->not->toBe('banned');
    expect(Ban::where('user_id', $owner->id)->exists())->toBeFalse();
});
