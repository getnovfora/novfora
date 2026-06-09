<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability;

use App\Models\DigestPreference;
use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Spike P2 — stateless 1-click unsubscribe (RFC 8058). The link is a Laravel SIGNED URL (HMAC over the
 * route + user id under APP_KEY), so no server-side token table is needed — baseline-safe. Following or
 * one-click-POSTing it sets the user's digest cadence to 'off', which the send gate honours at the next
 * assembly/send (GO criterion 5). Distinct from a bounce suppression: unsubscribe is consent, not reputation.
 */
final class Unsubscribe
{
    public const ROUTE = 'deliverability.unsubscribe';

    /** A signed, non-expiring 1-click unsubscribe URL for a user (used for List-Unsubscribe + the human link). */
    public static function urlFor(User $user): string
    {
        return URL::signedRoute(self::ROUTE, ['user' => $user->getKey()]);
    }

    /** Apply the opt-out: silence digest mail for this user. Idempotent. */
    public static function apply(User $user): void
    {
        DigestPreference::query()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['cadence' => DigestPreference::OFF],
        );
    }
}
