<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Models\Permission;

/**
 * Simple-mode permissions (ADR-0089) — the layman capability→catalog-key mapping, the correctness core.
 *
 * Each capability is a plain-language bundle of catalog keys that simple mode writes together via the SAME
 * write primitive (App\Permissions\GroupPermissionEditor::set) the card editor uses. This class is the SINGLE
 * SOURCE OF TRUTH for the mapping: the ⚡group-simple-editor SFC and the tests both read it. It is a UI write
 * layer — NOT a new engine path, NOT role machinery, and it NEVER changes the resolver or the catalog.
 *
 * Deliberate exclusions (Advanced-only — see ADR-0089): every Administration-tier key, the Moderation cluster
 * (bans.manage / post.edit.any / post.delete.any / post.history.view / topic.moderate), and club.manage —
 * silently granting those behind a friendly toggle is the exact mis-grant simple mode exists to avoid. The
 * exclusion is pinned by a test that intersects allKeys() with the catalog's Administration + Moderation
 * clusters and club.manage.
 */
final class CapabilityMap
{
    /**
     * Ordered capability slug => its catalog keys (the load-bearing mapping). The slug doubles as the i18n key
     * (admin.perms.capabilities.<slug>.label/.subtitle).
     *
     * @var array<string, list<string>>
     */
    public const CAPABILITIES = [
        'read_reply' => ['forum.view', 'post.create', 'post.edit.own', 'post.delete.own'],
        'start_topics' => ['topic.create'],
        'post_media' => ['post.links', 'post.images', 'attachment.create'],
        'react_vote' => ['react.create', 'poll.vote'],
        'polls_tags' => ['poll.create', 'tag.apply', 'tag.create'],
        'follow' => ['follow.create', 'follow.delete'],
        'pm' => ['pm.send'],
    ];

    /** The ordered capability slugs. @return list<string> */
    public static function capabilities(): array
    {
        return array_keys(self::CAPABILITIES);
    }

    public static function has(string $capability): bool
    {
        return isset(self::CAPABILITIES[$capability]);
    }

    /** The catalog keys of one capability (empty for an unknown slug). @return list<string> */
    public static function keys(string $capability): array
    {
        return self::CAPABILITIES[$capability] ?? [];
    }

    /** Every catalog key referenced by any capability (the exclusion-invariant test reads this). @return list<string> */
    public static function allKeys(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CAPABILITIES))));
    }

    /**
     * The capabilities applicable at a scope: those whose EVERY key is settable there, so simple mode never
     * writes a silently-inert row (ADR-0089 pin 2). Settability mirrors the card editor's visiblePermissions:
     * a scope_kind='forum' key is settable at any scope (global baseline / per-forum / per-club override); a
     * scope_kind='global' key only at global scope. scope_kind is read from the Permission catalog — the SINGLE
     * source, so the rule can never drift from the keys' real scopes.
     *
     * @return list<string>
     */
    public static function for(string $scopeType): array
    {
        if ($scopeType === 'global') {
            return self::capabilities(); // every key is settable at global scope
        }

        /** @var array<string,string> $scopeKind key => scope_kind */
        $scopeKind = Permission::query()
            ->whereIn('key', self::allKeys())
            ->pluck('scope_kind', 'key')
            ->all();

        return array_values(array_filter(self::capabilities(), function (string $capability) use ($scopeKind): bool {
            foreach (self::keys($capability) as $key) {
                // A key absent from the catalog, or one whose scope_kind isn't 'forum', isn't settable below
                // global — so the whole capability is withheld at this scope (Advanced handles it).
                if (($scopeKind[$key] ?? 'global') !== 'forum') {
                    return false;
                }
            }

            return true;
        }));
    }
}
