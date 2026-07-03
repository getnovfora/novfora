<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Permissions;

use App\Exceptions\FriendlyDenialException;

/**
 * The permission-aware UI contract (NOV-96 / ADR-0109). ONE resolver that maps an authorization result +
 * the current actor + a declared fallback intent onto exactly one UX outcome, so an action affordance is never
 * hand-rolled per surface again (the ghost-UI bug class — BETA-4/ADR-0105 — killed permanently).
 *
 * The four IN-VIEW states are rendered by the <x-action> Blade component; the fifth outcome — a route-level
 * FRIENDLY-403 — is {@see FriendlyDenialException} (a full-page denial made into a contextual
 * explainer instead of a bare 403). Together they are the five outcomes the spec names: show /
 * show-disabled-with-reason / sign-in CTA / hide / friendly-403.
 *
 * WHY the caller declares intent. The SAME denial means different things per action: a control a signed-in user
 * can never remedy should HIDE (rendering it only teases a 403 — the ghost-UI kill), while a control blocked by
 * transient state the user CAN understand (a locked thread, a closed forum) should show DISABLED WITH A REASON.
 * A resolver cannot infer that product intent, so the caller passes `whenDenied` / `whenGuest`.
 */
enum Affordance: string
{
    /** The actor may act → render the live control. */
    case Allow = 'allow';

    /** Authenticated but not permitted, and the block is worth explaining → render disabled + a reason. */
    case DisabledWithReason = 'disabled';

    /** A guest, and signing in COULD grant it → render a sign-in call-to-action. */
    case SignInCta = 'sign_in';

    /** Not permitted with no user-actionable remedy → render nothing (the ghost-UI kill). */
    case Hidden = 'hidden';

    /**
     * Resolve the outcome for one action.
     *
     * @param  bool  $can  the authorization verdict for the CURRENT actor (a canDo() / policy result / precomputed flag)
     * @param  bool  $isGuest  the actor is unauthenticated
     * @param  'hide'|'disabled'  $whenDenied  outcome for an AUTHENTICATED actor who lacks the permission
     * @param  'hide'|'cta'  $whenGuest  outcome for an unauthenticated actor
     */
    public static function resolve(bool $can, bool $isGuest, string $whenDenied = 'hide', string $whenGuest = 'hide'): self
    {
        if ($can) {
            return self::Allow;
        }

        if ($isGuest) {
            return $whenGuest === 'cta' ? self::SignInCta : self::Hidden;
        }

        return $whenDenied === 'disabled' ? self::DisabledWithReason : self::Hidden;
    }
}
