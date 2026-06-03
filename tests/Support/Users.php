<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Group;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

/** Test helpers for building authenticated users with group memberships and 2FA state. */
final class Users
{
    /**
     * Create a user and place them in the given (already-seeded) groups; the first is primary.
     *
     * @param  array<int,string>  $slugs
     */
    public static function inGroups(array $slugs, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);

        foreach (array_values($slugs) as $i => $slug) {
            $group = Group::where('slug', $slug)->first();
            if ($group instanceof Group) {
                $user->groups()->attach($group->id, ['is_primary' => $i === 0]);
            }
        }

        return $user->refresh();
    }

    /** Give a user a fully-confirmed authenticator (for login-challenge + staff-gate tests). */
    public static function withTwoFactor(User $user): User
    {
        $secret = (new Google2FA)->generateSecretKey();

        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => encrypt(json_encode(['RECOVERY-1', 'RECOVERY-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user->refresh();
    }

    /** A currently-valid TOTP for a user whose 2FA secret is set. */
    public static function totp(User $user): string
    {
        return (new Google2FA)->getCurrentOtp(decrypt($user->two_factor_secret));
    }
}
