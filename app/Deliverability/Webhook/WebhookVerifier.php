<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Webhook;

use Illuminate\Http\Request;

/**
 * Spike P2 — cryptographic verification of an inbound bounce/complaint webhook. The endpoint is
 * UNAUTHENTICATED and receives UNTRUSTED input, so trust is the HMAC, never reachability. We sign the
 * canonical string "{timestamp}.{rawBody}" with a per-install secret (NOT APP_KEY) and constant-time
 * compare; a missing/old timestamp (outside the replay window) or any mismatch fails closed → no DB write,
 * no suppression. Timestamp uses now() (Carbon-controllable, testable) rather than the wall clock.
 */
final class WebhookVerifier
{
    public function configured(): bool
    {
        return (bool) config('hearth.deliverability.webhook.enabled')
            && (string) config('hearth.deliverability.webhook.secret', '') !== '';
    }

    /** True only when the signature + timestamp verify against the raw body. */
    public function verify(Request $request): bool
    {
        $secret = (string) config('hearth.deliverability.webhook.secret', '');
        if ($secret === '') {
            return false;
        }

        $raw = $request->getContent();
        $maxBytes = (int) config('hearth.deliverability.webhook.max_body_bytes', 262144);
        if (! is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) {
            return false;
        }

        $signature = (string) $request->header('X-Hearth-Signature', '');
        $timestamp = (string) $request->header('X-Hearth-Timestamp', '');
        if ($signature === '' || $timestamp === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        $tolerance = max(1, (int) config('hearth.deliverability.webhook.tolerance_seconds', 300));
        if (abs(now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false; // stale / replayed
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);

        return hash_equals($expected, $signature);
    }
}
