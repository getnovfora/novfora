<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\Ban;
use App\Models\User;

/** Step 1 of resolution (security §1.2): bans are enforced BEFORE any ACL evaluation. */
final class BanChecker
{
    public function isBanned(User $user, Scope $scope): bool
    {
        // A "banned" account status is an absolute, global ban.
        if ($user->status === 'banned') {
            return true;
        }

        // A scoped ban covers its whole subtree: being banned from a category/forum means being
        // banned from every forum/thread beneath it. So we match any ban whose scope is the target
        // OR one of its ancestors (the global root is always in the chain), excluding expired bans.
        $chain = ScopeChain::for($scope);

        return Ban::query()
            ->where('user_id', $user->getKey())
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->where(function ($q) use ($chain) {
                foreach ($chain as $s) {
                    $q->orWhere(function ($q2) use ($s) {
                        $q2->where('scope_type', $s->type);
                        $s->id === null
                            ? $q2->whereNull('scope_id')
                            : $q2->where('scope_id', $s->id);
                    });
                }
            })
            ->exists();
    }
}
