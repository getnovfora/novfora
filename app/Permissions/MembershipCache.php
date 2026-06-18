<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\User;

/**
 * Invalidate a single user's resolved-permission caches after a GROUP-MEMBERSHIP change (ACP v3 · v3-e,
 * ADR-0083). This is the sibling of guardrail G9 (a query-builder `acl_entries` write skips model events):
 * a group is a permission HOLDER, so adding/removing a user from a group changes that user's effective
 * permissions WITHOUT touching `acl_entries` — and the pivot helpers (`attach()` / `detach()` /
 * `updateExistingPivot()` / `syncWithoutDetaching()`) fire NO Eloquent model events, so nothing invalidates
 * the resolver caches automatically. EVERY join / leave / promote / auto-promote / approval / admin-assign
 * must therefore call this explicitly, or the resolver serves stale grants.
 *
 * The resolver has three caches; this seam addresses each:
 *
 *   1. The cross-request resolved-verdict cache (`PermissionResolver::can()`), whose key embeds BOTH the global
 *      `AclVersion` AND the user's `groupSignature()` (a digest of the user's group-set). We refresh the user's
 *      `groups` relation here so the NEXT `groupSignature()` reflects the new set, re-keying this user's cache.
 *      Those two key components together invalidate the two ways a verdict can change: an `acl_entries` write
 *      bumps the version (so every cached verdict re-keys), and a membership change moves the signature. We do
 *      NOT bump `AclVersion` on the ADDITIVE hot paths (join / approve / auto-promote): the per-user signature
 *      already scopes the invalidation, and a global bump on every auto-promotion during a cron sweep would
 *      cold-start every other viewer's cache (the signature mechanism exists precisely to avoid that herd).
 *
 *      BUT `groupSignature()` is a pure function of the id-set, not monotonic — a leave/remove/demote can land a
 *      user back on a PRIOR signature whose cached entries may still be within their TTL. On the membership+ACL
 *      axes that is harmless (the version prefix already re-keyed if any ACL changed; if nothing changed, the
 *      old verdict for that exact group-set is still correct). To also dominate a recurring signature on the
 *      ORTHOGONAL axes (e.g. a ban/status change cached against the same key) and be robust to any future write
 *      path that forgets to bump, the REDUCTION/SWAP paths (leave / remove / delete-reassign / trust demotion)
 *      pass `$bumpVersion = true` — cheap defence-in-depth on rare paths, never on the additive hot path.
 *
 *   2. The per-request memo (`PermissionResolver::$memo`), keyed by user|permission|scope WITHOUT the
 *      signature — so a verdict resolved earlier in THIS request would survive a mid-request membership change.
 *      Flush it.
 *
 *   3. The per-request `VisibleForumIds` memo, keyed by viewer id WITHOUT the signature — same staleness. Flush it.
 *
 * Refreshing the relation (1) also guarantees a same-request re-resolution recomputes against the NEW holder
 * set rather than the stale in-memory `groups` collection — without it, flushing the memo alone would recompute
 * using the old `groupIds()`.
 */
final class MembershipCache
{
    /**
     * Invalidate every resolver cache a change to $user's group-set could have made stale.
     *
     * @param  bool  $bumpVersion  pass true on a REDUCTION/SWAP (leave/remove/demote) that can return the user to
     *                             a previously-cached group signature; bumps the global AclVersion so a recurring
     *                             signature can never re-serve a pre-change cross-request entry. Leave false on the
     *                             additive hot paths (join/approve/auto-promote) to avoid cron-sweep cache thrash.
     */
    public static function flushFor(User $user, bool $bumpVersion = false): void
    {
        // (1) Re-key the cross-request cache: drop the stale in-memory groups relation so the next
        //     groupIds()/groupSignature()/resolution reads the new set straight from the DB.
        if ($user->exists) {
            $user->load('groups');
        }

        // (2) + (3) the request-scoped memos that carry no signature in their key (+ optional version bump).
        self::flushRequestScopedMemos($bumpVersion);
    }

    /**
     * Flush only the request-scoped resolver memos (the per-request `PermissionResolver` memo + the
     * `VisibleForumIds` memo), optionally bumping the global `AclVersion`. Use the no-bump form on a BULK admin
     * ADD where the affected users are not re-resolved in the same request (the cross-request cache self-heals
     * via each user's group signature); use `$bumpVersion = true` on a bulk REDUCTION (reassign-and-delete).
     */
    public static function flushRequestScopedMemos(bool $bumpVersion = false): void
    {
        app(PermissionResolver::class)->flushMemo();
        VisibleForumIds::flush();

        if ($bumpVersion) {
            app(AclVersion::class)->bump();
        }
    }
}
