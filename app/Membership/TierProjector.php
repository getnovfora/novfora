<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership;

use App\Clubs\ClubRoleProjector;
use App\Models\AclEntry;
use App\Models\MemberSubscription;
use App\Models\User;
use App\Permissions\AclVersion;
use App\Permissions\PermissionValue;

/**
 * Projects a member's ACTIVE subscriptions into per-user, global-scope acl_entries (Phase 4 · M5.1) — so the
 * SAME PermissionResolver that gates the board also resolves a paying member's perks. Mirrors
 * {@see ClubRoleProjector}: the `member_subscriptions` rows are the source of truth; this is their
 * mirror into the engine, re-derived idempotently on every change.
 *
 * SCOPE ISOLATION & SAFETY. Grants are written at scope_type='global', scope_id=null, holder_type='user', and
 * the clear step is BOUNDED to the {@see TierPerks} universe — it never touches any other global user entry,
 * and a tier can never grant a key outside that fixed perk set. A perk is granted only while the subscription
 * is `active` AND its tier `is_active`; expiry/cancellation re-derives to the empty (or remaining) set.
 */
final class TierProjector
{
    /** Re-derive a user's tier perks from their ACTIVE subscriptions. Idempotent; bumps AclVersion. */
    public function syncUser(User $user): void
    {
        $userId = (int) $user->getKey();

        $perks = TierPerks::sanitize(
            MemberSubscription::query()
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->with('tier')
                ->get()
                ->flatMap(fn (MemberSubscription $s) => ($s->tier && $s->tier->is_active) ? ($s->tier->perks ?? []) : [])
                ->all()
        );

        // Clear ONLY the user's global tier-perk entries (bounded to the fixed perk universe).
        AclEntry::query()
            ->where('holder_type', 'user')
            ->where('holder_id', $userId)
            ->where('scope_type', 'global')
            ->whereNull('scope_id')
            ->whereIn('permission_key', TierPerks::keys())
            ->delete();

        foreach ($perks as $key) {
            AclEntry::create([
                'permission_key' => $key,
                'holder_type' => 'user',
                'holder_id' => $userId,
                'scope_type' => 'global',
                'scope_id' => null,
                'value' => PermissionValue::Allow->value,
            ]);
        }

        app(AclVersion::class)->bump();
    }
}
