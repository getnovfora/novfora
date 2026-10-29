<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Listeners;

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;

/**
 * OAuth pre-hijack guard (NF-04 apex-review MEDIUM). A provider sign-in without evidence of a verified email
 * creates an UNVERIFIED account and links the provider identity to it — so whoever controls that provider
 * account may not own the address. When the address's real owner recovers the account through a password
 * reset (the reset link is mailed to the address, which is proof of ownership) while the email is still
 * unverified, every identity linked before that proof is PROVISIONAL and is dropped, and every session on the
 * account is ended, so a squatter's provider login and live session cannot ride into the recovered account.
 *
 * Only unverified accounts are touched: the explicit link flow sits behind `verified`, so links on a verified
 * account were made by a proven owner. Synchronous on purpose — the revocation must land before the next
 * provider login can happen. AUTO-DISCOVERED via the handle(PasswordReset) signature.
 */
final class RevokeProvisionalSocialIdentities
{
    public function handle(PasswordReset $event): void
    {
        $user = $event->user;
        if (! $user instanceof User || $user->email_verified_at !== null) {
            return;
        }

        $revoked = SocialAccount::query()->where('user_id', $user->getKey())->pluck('provider')->all();
        if ($revoked !== []) {
            SocialAccount::query()->where('user_id', $user->getKey())->delete();
            Audit::log('auth.social.provisional_revoked', $user, ['providers' => $revoked]);
        }

        // End every session on the recovered account (a squatter may still hold one from the provider login).
        // Database sessions are the default; other drivers carry no user index, so there is nothing to sweep.
        if (config('session.driver') === 'database') {
            DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        }
        $user->forceFill(['remember_token' => null])->saveQuietly();
    }
}
