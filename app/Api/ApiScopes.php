<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Api;

/**
 * The fixed Admin-API scope taxonomy (E1 / NOV-135, ADR-0115). Scopes mirror the ACP sections; there is
 * deliberately **no `admin:*` super-scope** — every capability must be granted explicitly. A scope narrows what a
 * token may do; the effective ability is always `token scopes ∩ user canDo` (both must pass), so a token can
 * never do more than its owner. Secret-bearing / destructive scopes are co-owner-mintable only.
 */
final class ApiScopes
{
    /** The complete, closed set of valid admin scope strings. */
    public const ALL = [
        'admin:settings.read', 'admin:settings.write',
        'admin:structure.read', 'admin:structure.write',
        'admin:members.read', 'admin:members.write',
        'admin:moderation',
        'admin:backups.read', 'admin:backups.create',
        'admin:restore',
        'admin:maintenance',
        'admin:upgrade',
        'admin:populate',
    ];

    /**
     * Scopes that may only be minted by a co-owner: a backup archive holds every secret + every PM; restore and
     * upgrade can replace the whole install; populate can write to a live board.
     */
    public const CO_OWNER_ONLY = [
        'admin:backups.read', 'admin:backups.create', 'admin:restore', 'admin:upgrade', 'admin:populate',
    ];

    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::ALL, true);
    }

    /**
     * Keep only valid scopes, de-duplicated and re-indexed (untrusted input from a mint request).
     *
     * @param  array<int|string, mixed>  $scopes
     * @return list<string>
     */
    public static function sanitize(array $scopes): array
    {
        $clean = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && self::isValid($scope)) {
                $clean[] = $scope;
            }
        }

        return array_values(array_unique($clean));
    }

    /** Does this scope set include any co-owner-only scope? @param list<string> $scopes */
    public static function requiresCoOwner(array $scopes): bool
    {
        return array_intersect($scopes, self::CO_OWNER_ONLY) !== [];
    }
}
