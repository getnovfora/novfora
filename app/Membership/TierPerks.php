<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership;

/**
 * The fixed catalogue of perk permission keys a membership tier may grant (Phase 4 · M5.1). Keeping the
 * universe FIXED (not free-form) is a security boundary: {@see TierProjector} only ever writes/clears
 * acl_entries within this set, so a tier can never grant an arbitrary capability (e.g. `admin.access`) and a
 * mis-typed perk simply does nothing. Each key resolves through the normal engine at global scope, so a
 * feature gates a perk with `$user->canDo('tier.ad_free', Scope::global())`.
 *
 * The keys are namespaced `tier.*`. Enforcing each perk in its feature (hiding ads, allowing a custom title,
 * etc.) is wired per-feature; M5.1 delivers the GATING mechanism — the grant/revoke through the engine.
 */
final class TierPerks
{
    /** @var array<string,string> perk key => human label */
    public const ALL = [
        'tier.ad_free' => 'Ad-free browsing',
        'tier.custom_title' => 'Custom member title',
        'tier.colored_username' => 'Coloured username',
        'tier.signature_images' => 'Images in signature',
        'tier.increased_uploads' => 'Increased upload quota',
        'tier.early_access' => 'Early access to new features',
        'tier.create_clubs' => 'Create clubs (when memberships gate club creation)',
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function isValid(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    /**
     * Filter an arbitrary list down to the valid perk keys (dedup, preserve order). The input may be untrusted
     * (decoded JSON from the DB), hence the mixed element type and the explicit is_string guard.
     *
     * @param  iterable<mixed>  $keys
     * @return list<string>
     */
    public static function sanitize(iterable $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            if (is_string($key) && self::isValid($key) && ! in_array($key, $out, true)) {
                $out[] = $key;
            }
        }

        return $out;
    }
}
