<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Post;
use App\Models\User;

/**
 * New-user / restricted-account moderation gate (ADR-0007 §2.4). Posts are held when:
 *   - the account is `pending` — a registration flagged by the screener, or an account under a moderation
 *     warning consequence — which is how flagged/restricted users are routed into the queue; OR
 *   - the author is a TL0 new account whose first N posts have not yet been approved (keyed on TL0 GROUP
 *     membership, not the trust_level integer, so it never catches established or staff accounts).
 */
final class NewUserModeration
{
    public function shouldHold(User $author): bool
    {
        if (! $author->getKey()) {
            return false;
        }

        // A flagged / restricted account: everything they post is held until staff clear them.
        if (($author->status ?? 'active') === 'pending') {
            return true;
        }

        $limit = (int) config('hearth.antispam.new_user_moderation.posts', 2);
        if ($limit <= 0 || ! $author->groups()->where('slug', 'tl0')->exists()) {
            return false;
        }

        $approved = Post::where('user_id', $author->getKey())->where('approved_state', 'approved')->count();

        return $approved < $limit;
    }
}
