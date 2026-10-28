<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Mail;

/**
 * The one deliverability self-test sender (U16 / NOV-114). Consolidates the two byte-identical `Mail::raw()`
 * self-tests that had drifted into the CLI command (`novfora:mail:test`) and the Admin → Settings → Email
 * page. It sends a single raw message through the configured transport; callers decide how to surface
 * success/failure and whether to make saved SMTP overrides live first (the ACP does via `applyToConfig()`;
 * the CLI uses the ambient config). Throws on a transport failure so the caller can report it.
 */
final class TestMailer
{
    /** Send the deliverability self-test to $to through the current mail transport. */
    public function send(string $to): void
    {
        Mail::raw(
            ' — if you received this, outbound email is working. For reliable delivery, '
            .'verify SPF, DKIM and DMARC DNS records for your sending domain.',
            fn ($message) => $message->to($to)->subject(''),
        );
    }
}
