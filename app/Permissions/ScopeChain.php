<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\Forum;
use App\Models\Topic;

/**
 * Builds the scope chain (root → target): global → …ancestor categories… → forum → thread.
 * Ancestors come from the materialised `forums.path` (ADR-0004), so this is O(depth), one query.
 * Deleted/moved scopes degrade gracefully: a missing node is skipped, so resolution inherits from
 * the surviving parent (security §1.5).
 */
final class ScopeChain
{
    /** @return list<Scope> ordered root (global) → target */
    public static function for(Scope $target): array
    {
        if ($target->isGlobal()) {
            return [Scope::global()];
        }

        // A club scope inherits only from global (Phase 4 · M1.2) — club membership grants live at club scope,
        // and a club's discussion forums inject this club node into THEIR chain in M1.4 (forums.club_id), so a
        // club moderator's club-scoped capability reaches every topic in the club.
        if ($target->type === 'club') {
            return [Scope::global(), Scope::club((int) $target->id)];
        }

        if ($target->type === 'thread') {
            $topic = Topic::find($target->id);
            if (! $topic) {
                return [Scope::global()]; // deleted thread → inherit from global/surviving parent
            }
            $chain = self::forumChain((int) $topic->forum_id);
            $chain[] = Scope::thread((int) $topic->id);

            return $chain;
        }

        // category or forum target
        return self::forumChain((int) $target->id);
    }

    /** @return list<Scope> [global, (club,) …ancestor forum/category nodes…, the forum itself] */
    private static function forumChain(int $forumId): array
    {
        $forum = Forum::find($forumId);
        if (! $forum) {
            return [Scope::global()]; // deleted/moved forum → inherit from surviving parent
        }

        // path like "/1/4/" → [1, 4] (root → self)
        $ids = array_values(array_filter(
            array_map('intval', explode('/', trim((string) $forum->path, '/'))),
        ));
        if ($ids === []) {
            $ids = [(int) $forum->id];
        }

        $nodes = Forum::whereIn('id', $ids)->get()->keyBy('id');

        $chain = [Scope::global()];

        // Phase 4 · M1.4: a club forum injects its club scope right after global, so a club owner/moderator's
        // club-scoped grants (ClubRoleProjector) resolve for every topic in the club — and a private club's
        // guests-group forum.view=NEVER (M1.5) hard-denies anonymous reads through this node.
        if ($forum->club_id !== null) {
            $chain[] = Scope::club((int) $forum->club_id);
        }

        foreach ($ids as $id) {
            $node = $nodes->get($id);
            if ($node) { // skip a deleted ancestor → inherit from surviving parent
                $chain[] = new Scope($node->type === 'category' ? 'category' : 'forum', (int) $node->id);
            }
        }

        return $chain;
    }
}
