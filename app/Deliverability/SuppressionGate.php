<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

use App\Models\DigestPreference;
use App\Models\EmailSuppression;
use App\Models\User;

/**
 * Spike P2 — the single send-time gate shared by the digest path (and available to the immediate path,
 * though this spike does NOT wire it into App\Notifications\Notifier). It answers one question — "may we
 * send this user mail right now?" — against PRIMITIVE DB state only (no enhanced service), so it works
 * identically on the baseline tier and under forced absence.
 *
 * It is consulted at BOTH digest-assembly time and again inside the send job, so an address suppressed (a
 * bounce) or a user who unsubscribed AFTER enqueue but BEFORE the cron drain is still skipped — satisfying
 * GO criterion 5 ("no mail to an opted-out / suppressed user").
 */
final class SuppressionGate
{
    /** Is this address on the deliverability suppression list (bounce / complaint / manual)? Case-insensitive. */
    public function suppressed(string $email): bool
    {
        $email = $this->normalize($email);

        return $email !== '' && EmailSuppression::query()->whereRaw('LOWER(email) = ?', [$email])->exists();
    }

    /**
     * May we send a DIGEST to this user now? False when: no email, the address is suppressed, or the user's
     * cadence is not a batched one (immediate/off — `off` is what 1-click unsubscribe sets).
     */
    public function allowsDigest(User $user): bool
    {
        if (! $user->email || $this->suppressed($user->email)) {
            return false;
        }

        return in_array($this->cadence($user), DigestPreference::BATCHED, true);
    }

    /** The user's effective cadence (absent row → 'immediate', the default live behaviour). */
    public function cadence(User $user): string
    {
        $pref = DigestPreference::query()->where('user_id', $user->getKey())->value('cadence');

        return is_string($pref) && $pref !== '' ? $pref : DigestPreference::IMMEDIATE;
    }

    private function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
