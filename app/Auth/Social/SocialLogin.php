<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Auth\Social;

use App\AntiSpam\RegistrationGuard;
use App\Models\Group;
use App\Models\SocialAccount;
use App\Models\User;
use App\Settings\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\AbstractUser;
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

        // P5.1 — just-in-time provisioning must honour the SAME server-side controls as the password
        // registration path (CreateNewUser); a provider identity is not a bypass. (1) the registration toggle;
        // (2) the anti-spam screener — an email/IP ban or a high-confidence listing BLOCKS, a borderline
        // signal FLAGS the account into the moderation queue (status='pending'). Whether the account starts
        // email-verified is decided separately, from provider-specific evidence (NF-04, providerVerifiedEmail).
        if (! app(Settings::class)->bool('registration.enabled')) {
            throw new SocialAuthException('New account registration is currently closed.');
        }

        $screening = app(RegistrationGuard::class)->screen([
            'email' => $email,
            'username' => $socialUser->getNickname() ?: ($socialUser->getName() ?: ''),
            'ip' => (string) request()->ip(),
        ]);
        if ($screening->blocked()) {
            throw new SocialAuthException('Sign-up is not available from your network right now.');
        }

        $verified = $this->providerVerifiedEmail($provider, $socialUser);
        $user = $this->createUser($provider, $providerUserId, $email, $socialUser, $screening->flagged(), $verified);

        // NF-04: without provider evidence of ownership the address is just a claim — the account starts
        // UNVERIFIED and goes through NovFora's own verification (the `verified` middleware gates the rest).
        if (! $verified) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    /**
     * Provider-specific evidence that the provider itself verified the email it returned (NF-04, NOV-147). A
     * syntactically valid address proves nothing — an unverified provider email must never mint a verified
     * local account. Absent or false evidence → false; an unknown provider → false.
     *   • google  — the OIDC userinfo claim `email_verified` (v3 userinfo, which Socialite's Google driver reads).
     *   • discord — the user object's `verified` flag (Discord sets it only once the email is confirmed).
     *   • github  — SocialProviders always requests the `user:email` scope, under which Socialite's GitHub
     *               driver REPLACES the profile email with the primary address from /user/emails only when
     *               GitHub marks it `verified` (else null, which resolveForLogin already refuses). The scope is
     *               pinned by a test, since dropping it would let an unverified public profile email through.
     */
    private function providerVerifiedEmail(string $provider, SocialiteUser $socialUser): bool
    {
        $raw = $socialUser instanceof AbstractUser ? (array) $socialUser->getRaw() : [];
        $flag = static fn (mixed $v): bool => $v === true || $v === 'true' || $v === 1 || $v === '1';

        return match ($provider) {
            'google' => $flag($raw['email_verified'] ?? null),
            'discord' => $flag($raw['verified'] ?? null),
            'github' => $this->normaliseEmail($socialUser->getEmail()) !== null,
            default => false,
        };
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
            'nickname' => $this->clampName($socialUser->getNickname() ?: $socialUser->getName()),
            'avatar' => $this->clampAvatar($socialUser->getAvatar()),
            'linked_at' => now(),
        ]);
    }

    /**
     * Unlink a provider from an account (M2.2). Always safe: the account keeps its email + password (or can
     * reset it), so it is never locked out. Idempotent — a no-op if the provider is not linked.
     */
    public function unlink(User $user, string $provider): void
    {
        SocialAccount::query()
            ->where('user_id', $user->getKey())
            ->where('provider', $provider)
            ->delete();
    }

    /** Create a fresh local account from a provider profile and attach the identity. */
    private function createUser(string $provider, string $providerUserId, string $email, SocialiteUser $socialUser, bool $flagged, bool $emailVerified): User
    {
        return DB::transaction(function () use ($provider, $providerUserId, $email, $socialUser, $flagged, $emailVerified): User {
            $user = new User;
            $username = $this->uniqueUsername($socialUser->getNickname() ?: $socialUser->getName() ?: explode('@', $email)[0]);
            $user->fill([
                'username' => $username,
                'name' => $username,
                'display_name' => $this->clampName($socialUser->getName()) ?: $username,
                'email' => $email,
                // A random, unknown password: the account signs in via the provider, or sets one through
                // password reset. It is never the empty string (the column is NOT NULL) and never guessable.
                'password' => Hash::make(Str::random(48)),
            ]);
            // A flagged screen routes the account into the moderation queue (pending) — matching CreateNewUser.
            $user->status = $flagged ? 'pending' : 'active';
            // Verified ONLY on provider evidence (NF-04); otherwise NovFora's own email verification applies.
            $user->forceFill(['email_verified_at' => $emailVerified ? now() : null])->save();

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
                'nickname' => $this->clampName($socialUser->getNickname() ?: $socialUser->getName()),
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

    /**
     * Clamp + normalise a provider-supplied display name / nickname (P5.1). A provider profile is
     * attacker-controlled, so strip control + bidi-override characters (display-spoofing) and bound the
     * length, the same hardening uniqueUsername() applies to the username. Returns '' when nothing usable
     * remains (the caller falls back to the username).
     */
    private function clampName(?string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string) $name) ?? '';

        return mb_substr(trim($name), 0, 255);
    }

    private function clampAvatar(?string $avatar): ?string
    {
        $avatar = $avatar === null ? null : trim($avatar);

        return $avatar !== null && $avatar !== '' && mb_strlen($avatar) <= 1024
            && str_starts_with($avatar, 'https://') ? $avatar : null;
    }
}
