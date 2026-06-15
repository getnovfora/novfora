<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Membership\Payments;

use App\Settings\Settings;
use Illuminate\Http\Request;

/**
 * Cryptographic verification of an inbound Stripe webhook (Phase 4 · M5.3). The endpoint is UNAUTHENTICATED
 * and receives UNTRUSTED input, so trust is the HMAC, never reachability — mirrors the mail WebhookVerifier
 * (ADR Spike-P2). Stripe signs the canonical string "{t}.{rawBody}" with the per-install webhook secret and
 * sends it in `Stripe-Signature: t=...,v1=...`. We recompute with hash_hmac + constant-time compare; a
 * missing/old timestamp (outside the replay window) or any mismatch fails closed. now() is Carbon-controllable
 * (testable). The secret is read from the ENCRYPTED settings store and is empty/disabled by default.
 */
final class StripeWebhookVerifier
{
    private const MAX_BODY_BYTES = 524288; // 512 KB

    private const TOLERANCE_SECONDS = 300;

    public function __construct(private readonly Settings $settings) {}

    public function configured(): bool
    {
        return $this->settings->bool('payments.stripe.enabled')
            && $this->settings->secretIsSet('payments.stripe.webhook_secret');
    }

    public function verify(Request $request): bool
    {
        $secret = $this->settings->string('payments.stripe.webhook_secret');
        if ($secret === '') {
            return false;
        }

        $raw = $request->getContent();
        if ($raw === '' || strlen($raw) > self::MAX_BODY_BYTES) {
            return false;
        }

        [$timestamp, $signature] = $this->parseSignatureHeader((string) $request->header('Stripe-Signature', ''));
        if ($timestamp === null || $signature === null) {
            return false;
        }

        if (abs(now()->getTimestamp() - $timestamp) > self::TOLERANCE_SECONDS) {
            return false; // stale / replayed
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$raw}", $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Parse `t=TIMESTAMP,v1=HEX[,v1=...]`. Returns [timestamp, firstV1] or [null, null] when malformed.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function parseSignatureHeader(string $header): array
    {
        $timestamp = null;
        $signature = null;

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) {
                continue;
            }
            [$key, $value] = $pair;
            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $signature === null && $value !== '') {
                $signature = $value;
            }
        }

        return [$timestamp, $signature];
    }
}
