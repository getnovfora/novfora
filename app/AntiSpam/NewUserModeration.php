<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\Post;
use App\Models\User;

/**
 * New-user moderation queue gate (ADR-0007 §2.4): a TL0 author's first N APPROVED posts are held for staff
 * approval. Keyed on TL0 GROUP membership (not the trust_level integer), so it never catches established or
 * staff accounts — and it stops holding once N posts have been approved.
 */
final class NewUserModeration
{
    public function shouldHold(User $author): bool
    {
        $limit = (int) config('hearth.antispam.new_user_moderation.posts', 2);
        if ($limit <= 0) {
            return false;
        }

        if (! $author->getKey() || ! $author->groups()->where('slug', 'tl0')->exists()) {
            return false;
        }

        $approved = Post::where('user_id', $author->getKey())->where('approved_state', 'approved')->count();

        return $approved < $limit;
    }
}
