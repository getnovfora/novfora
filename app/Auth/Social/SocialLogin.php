<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Social;

use App\Models\Group;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * The OAuth identity resolver (Phase 4 · M2 — APEX auth boundary). Two flows, both written to the
 * NON-NEGOTIABLE rule: NEVER auto-merge a provider identity onto an existing local account on an email
 * collision without proven control.
 *
 *  • resolveForLogin() — an UNAUTHENTICATED sign-in. A known (provider, provider_user_id) logs that user in.
 *    A new identity creates a fresh account — UNLESS the provider email already belongs to a local account,
 *    in which case it REFUSES (no merge) and tells the user to sign in with their password and link from
 *    settings (M2.2). Account control is proven by the password session, never asserted by a matching email.
 *  • link() — an AUTHENTICATED user attaches a provider to THEIR account; refuses if that identity is already
 *    linked to a different account.
 */
class SocialLogin
{
    /**
     * Resolve (or create) the local account for a provider sign-in.
     *
     * @throws SocialAuthException on a missing provider email or an email collision with an existing account
     */
    public function resolveForLogin(string $provider, SocialiteUser $socialUser): User
    {
        $providerUserId = (string) $socialUser->getId();

        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing instanceof SocialAccount && $existing->user instanceof User) {
            return $existing->user; // a known identity → always the SAME account, never a duplicate
        }

        $email = $this->normaliseEmail($socialUser->getEmail());
        if ($email === null) {
            throw new SocialAuthException('Your '.$provider.' account did not share a verified email address, so we cannot sign you in.');
        }

        // APEX RULE — email collision: an account already owns this email. Do NOT merge. Require the user to
        // prove control by signing in with their password, then link the provider from their settings.
        if (User::query()->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw new SocialAuthException(
                'An account with this email already exists. Please sign in with your password, then link '.
                ucfirst($provider).' from your account settings.'
            );
        }

        return $this->createUser($provider, $providerUserId, $email, $socialUser);
    }

    /**
     * Link a provider identity to an already-authenticated account (M2.2).
     *
     * @throws SocialAuthException if the identity is linked to a different account
     */
    public function link(User $user, string $provider, SocialiteUser $socialUser): SocialAccount
    {
        $providerUserId = (string) $socialUser->getId();

        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing instanceof SocialAccount) {
            if ((int) $existing->user_id === (int) $user->getKey()) {
                return $existing; // already linked to this account (idempotent)
            }

            throw new SocialAuthException('That '.ucfirst($provider).' account is already linked to a different user.');
        }

        return SocialAccount::create([
            'user_id' => $user->getKey(),
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'nickname' => $socialUser->getNickname() ?: $socialUser->getName(),
            'avatar' => $this->clampAvatar($socialUser->getAvatar()),
            'linked_at' => now(),
        ]);
    }

    /** Create a fresh local account from a provider profile and attach the identity. */
    private function createUser(string $provider, string $providerUserId, string $email, SocialiteUser $socialUser): User
    {
        return DB::transaction(function () use ($provider, $providerUserId, $email, $socialUser): User {
            $user = new User;
            $username = $this->uniqueUsername($socialUser->getNickname() ?: $socialUser->getName() ?: explode('@', $email)[0]);
            $user->fill([
                'username' => $username,
                'name' => $username,
                'display_name' => $socialUser->getName() ?: $username,
                'email' => $email,
                // A random, unknown password: the account signs in via the provider, or sets one through
                // password reset. It is never the empty string (the column is NOT NULL) and never guessable.
                'password' => Hash::make(Str::random(48)),
            ]);
            $user->status = 'active';
            // The provider verified this email and there was no local collision, so the account starts verified.
            $user->forceFill(['email_verified_at' => now()])->save();

            foreach (['members' => true, 'tl0' => false] as $slug => $isPrimary) {
                $group = Group::where('slug', $slug)->first();
                if ($group instanceof Group) {
                    $user->groups()->attach($group->id, ['is_primary' => $isPrimary]);
                }
            }

            SocialAccount::create([
                'user_id' => $user->getKey(),
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'nickname' => $socialUser->getNickname() ?: $socialUser->getName(),
                'avatar' => $this->clampAvatar($socialUser->getAvatar()),
                'linked_at' => now(),
            ]);

            return $user;
        });
    }

    private function normaliseEmail(?string $email): ?string
    {
        $email = $email === null ? null : mb_strtolower(trim($email));

        return $email !== null && $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** A unique, valid (alpha_dash, 3–30) username derived from a provider handle. */
    private function uniqueUsername(string $seed): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]/', '', str_replace(' ', '-', trim($seed))) ?? '';
        $base = trim((string) $base, '-_');
        if (mb_strlen($base) < 3) {
            $base = 'user-'.Str::lower(Str::random(5));
        }
        $base = mb_substr($base, 0, 24);

        $username = $base;
        $n = 1;
        while (User::query()->where('username', $username)->exists()) {
            $username = mb_substr($base, 0, 24).'-'.(++$n);
        }

        return $username;
    }

    private function clampAvatar(?string $avatar): ?string
    {
        $avatar = $avatar === null ? null : trim($avatar);

        return $avatar !== null && $avatar !== '' && mb_strlen($avatar) <= 1024
            && str_starts_with($avatar, 'https://') ? $avatar : null;
    }
}
