<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Models\AclEntry;
use App\Models\Delegation;
use App\Models\Permission;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\ForumModeratorProjector;
use App\Permissions\MembershipCache;
use App\Permissions\PermissionValue;
use App\Permissions\RoleException;
use App\Permissions\RoleManager;
use App\Permissions\Scope;
use App\Support\Audit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ACP v3 · v3-f — temporary-access delegation (ADR-0087). A co-owner hands an individual ONE capability for a
 * bounded window (≤ 30 days), auto-expiring and ceiling-bounded. The {@see Delegation} table is the provenance
 * source-of-truth (G10); this service projects each live row into ONE time-boxed `acl_entries` row — the SAME
 * row the resolver reads (G1: no parallel evaluation). It rides the v3-0 `expires_at` seam: the resolver's
 * expires_at filter, the cached-can() TTL cap, and `novfora:acl:prune-expired` auto-expire the row with NO new
 * code here. Mirrors the v3-b {@see ForumModeratorProjector} provenance+projection pattern.
 *
 * APEX FENCES on grant (all run before any write, so a rejected grant leaves zero rows):
 *   • CO-OWNER ONLY — only a holder of admin.security.access may delegate (the actor-independent backstop to the
 *     SFC mount() gate; Livewire actions skip route middleware).
 *   • NON-DELEGABLE KEYS — admin.security.access and any Administration-cluster key are NEVER delegable, for any
 *     actor (stricter than the ceiling fence, which a co-owner — a full admin — would pass).
 *   • CEILING — reusing {@see RoleManager::assertWithinCeiling()} at the TARGET scope: the delegator must hold the
 *     key THEMSELVES, there and now. The recipient therefore never resolves above the delegator's current mask.
 *   • NO CLOBBER — refuse if a live user grant (or any NEVER) already occupies the recipient's (key, scope) cell,
 *     so a delegation never time-boxes a recipient's PERMANENT grant nor lifts a hard-deny.
 */
final class DelegationService
{
    /** Temporary access can never outlive this window (the v3-f cap). */
    public const MAX_DAYS = 30;

    public function __construct(
        private readonly RoleManager $roles,
        private readonly AclVersion $version,
    ) {}

    /**
     * A co-owner delegates ONE capability to $recipient at $scope until $requestedExpiry (clamped to ≤ 30 days).
     * Records the provenance row and projects the time-boxed `acl_entries` grant in one transaction.
     *
     * @throws DelegationException|RoleException on any fence rejection (zero rows written)
     */
    public function grant(User $actor, User $recipient, string $permissionKey, Scope $scope, \DateTimeInterface $requestedExpiry): Delegation
    {
        $permissionKey = trim($permissionKey);

        $this->assertActorIsCoOwner($actor);
        $this->assertCatalogKey($permissionKey);
        $this->assertDelegableKey($permissionKey);
        // The ceiling: the delegator must hold the key at the target scope NOW (the reused engine fence). NEVER is
        // not in play — a delegation only ever ALLOWs — so this is a pure "you can't grant beyond your reach" check.
        $this->roles->assertWithinCeiling([$permissionKey => PermissionValue::Allow->value], $actor, $scope);
        $this->assertCellFree($recipient, $permissionKey, $scope);

        $expiresAt = $this->cap($requestedExpiry);

        $delegation = DB::transaction(function () use ($actor, $recipient, $permissionKey, $scope, $expiresAt): Delegation {
            $delegation = Delegation::create([
                'delegator_id' => (int) $actor->getKey(),
                'recipient_id' => (int) $recipient->getKey(),
                'permission_key' => $permissionKey,
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'expires_at' => $expiresAt,
            ]);

            // Project into the resolver's ONLY input: one time-boxed user-holder ALLOW row. updateOrCreate is safe
            // here because assertCellFree() already proved no LIVE row (and no NEVER) occupies the cell — at worst
            // it revives a not-yet-pruned dead TTL row. The model write fires AclEntry::saved → bumps AclVersion.
            AclEntry::updateOrCreate(
                [
                    'permission_key' => $permissionKey,
                    'holder_type' => 'user',
                    'holder_id' => (int) $recipient->getKey(),
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                ],
                ['value' => PermissionValue::Allow->value, 'expires_at' => $expiresAt],
            );

            Audit::log('delegation.granted', $delegation, [
                'by' => (int) $actor->getKey(),
                'to' => (int) $recipient->getKey(),
                'key' => $permissionKey,
                'scope' => $scope->key(),
                'expires_at' => $expiresAt->toIso8601String(),
            ]);

            return $delegation;
        });

        MembershipCache::flushFor($recipient);

        return $delegation;
    }

    /**
     * A co-owner revokes a delegation early. Sets revoked_at, deletes the mirrored TTL row, bumps AclVersion (G9),
     * and flushes the recipient's resolver caches — so the recipient's can() flips false immediately, with no
     * dependence on the prune cron. Idempotent on an already-revoked row.
     */
    public function revoke(User $actor, Delegation $delegation): void
    {
        $this->assertActorIsCoOwner($actor);

        if ($delegation->revoked_at !== null) {
            return; // already revoked — nothing to do
        }

        $this->revokeRow($delegation);

        Audit::log('delegation.revoked', $delegation, ['by' => (int) $actor->getKey()]);
    }

    /**
     * Honour "the recipient never exceeds the delegator's CURRENT mask": when $delegator loses a capability (a
     * co-owner/restricted-admin demotion), revoke every LIVE delegation they granted for a key they no longer hold
     * at its scope. Called by the demotion paths AFTER they commit + flush, so canDo() reflects the reduced mask.
     * System-triggered, so it is NOT co-owner-gated (the actor is the demotion, not a co-owner UI action).
     */
    public function cascadeForActor(User $delegator): void
    {
        // Re-read the delegator's group-set + flush the resolver memo so canDo() below reflects the post-demotion
        // mask rather than a verdict resolved earlier in this request.
        MembershipCache::flushFor($delegator);

        $live = Delegation::query()->live()->where('delegator_id', $delegator->getKey())->get();

        foreach ($live as $delegation) {
            if (! $delegator->canDo($delegation->permission_key, $delegation->scope())) {
                $this->revokeRow($delegation);
                Audit::log('delegation.cascade_revoked', $delegation, [
                    'delegator' => (int) $delegator->getKey(),
                    'key' => $delegation->permission_key,
                ]);
            }
        }
    }

    /**
     * Live delegations a recipient currently holds (for the Active Delegations list + recipient lookups).
     *
     * @return Builder<Delegation>
     */
    public function liveForRecipient(User $recipient): Builder
    {
        return Delegation::query()->live()->where('recipient_id', $recipient->getKey());
    }

    // ── core ───────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The shared revoke mutation, used by BOTH the co-owner UI revoke and the cascade. One transaction: mark the
     * provenance row revoked, KEY-SCOPED delete the mirrored TTL row, bump AclVersion (G9 — the query-builder
     * delete skips the AclEntry `deleted` event). `whereNotNull('expires_at')` is the apex guard: it deletes ONLY
     * a time-boxed (delegation) row, never a PERMANENT user grant the recipient may also hold at the same cell
     * (e.g. a forum-moderator assignment). Flushes the recipient's caches after commit.
     */
    private function revokeRow(Delegation $delegation): void
    {
        DB::transaction(function () use ($delegation): void {
            $delegation->update(['revoked_at' => now()]);

            $this->cellQuery((int) $delegation->recipient_id, (string) $delegation->permission_key, $delegation->scope())
                ->where('value', PermissionValue::Allow->value)
                ->whereNotNull('expires_at')
                ->delete();

            $this->version->bump();
        });

        $recipient = User::find($delegation->recipient_id);
        if ($recipient !== null) {
            MembershipCache::flushFor($recipient);
        }
    }

    // ── fences ─────────────────────────────────────────────────────────────────────────────────────────────

    private function assertActorIsCoOwner(User $actor): void
    {
        if (! $actor->canDo(AdminCoOwnerService::SECURITY_KEY, Scope::global())) {
            throw new DelegationException('Only a co-owner can delegate temporary access.');
        }
    }

    private function assertCatalogKey(string $key): void
    {
        if (! Permission::query()->where('key', $key)->exists()) {
            throw new DelegationException("“{$key}” is not a known permission.");
        }
    }

    /**
     * admin.security.access and every Administration-cluster key are NEVER delegable — for ANY actor. A co-owner
     * is a full admin, so {@see RoleManager::assertWithinCeiling()}'s admin-tier rule would NOT stop them; this
     * explicit fence does (stricter, mirroring {@see ForumModeratorProjector}'s admin-tier refusal).
     */
    private function assertDelegableKey(string $key): void
    {
        if ($key === AdminCoOwnerService::SECURITY_KEY) {
            throw new DelegationException('The co-owner security key cannot be delegated.');
        }
        if (in_array($key, $this->roles->adminTierKeys(), true)) {
            throw new DelegationException("“{$key}” is an administration capability and cannot be delegated.");
        }
    }

    /**
     * Refuse if a LIVE user grant — or any NEVER — already occupies the recipient's (key, scope) cell. Delegating
     * would otherwise either time-box a permanent grant (updateOrCreate clobbering its expires_at) or lift a
     * hard-deny. A dead, not-yet-pruned TTL row does NOT block (it is revived by the grant's updateOrCreate).
     */
    private function assertCellFree(User $recipient, string $key, Scope $scope): void
    {
        $existing = $this->cellQuery((int) $recipient->getKey(), $key, $scope)
            ->where(function (Builder $q): void {
                $q->whereNull('expires_at')                                  // a permanent grant …
                    ->orWhere('expires_at', '>', now())                      // … or a live TTL grant …
                    ->orWhere('value', PermissionValue::Never->value);       // … or any NEVER (hard-deny)
            })
            ->first();

        if ($existing !== null) {
            throw (int) $existing->value === PermissionValue::Never->value
                ? new DelegationException('That user is hard-denied this capability here — a delegation cannot override a NEVER.')
                : new DelegationException('That user already holds this capability here.');
        }
    }

    // ── helpers ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The recipient's user-holder rows at one exact (permission, scope) cell — the projection target.
     *
     * @return Builder<AclEntry>
     */
    private function cellQuery(int $recipientId, string $key, Scope $scope): Builder
    {
        return AclEntry::query()
            ->where('permission_key', $key)
            ->where('holder_type', 'user')
            ->where('holder_id', $recipientId)
            ->where('scope_type', $scope->type)
            ->where(fn (Builder $q) => $scope->id === null ? $q->whereNull('scope_id') : $q->where('scope_id', $scope->id));
    }

    /** Clamp the requested expiry to the 30-day cap, and refuse a window that is not in the future. */
    private function cap(\DateTimeInterface $requested): Carbon
    {
        $expiry = Carbon::instance($requested);
        if ($expiry->lessThanOrEqualTo(now())) {
            throw new DelegationException('The delegation must expire in the future.');
        }

        $max = now()->addDays(self::MAX_DAYS);

        return $expiry->greaterThan($max) ? $max : $expiry;
    }
}
