<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Membership\MembershipService;
use App\Membership\Payments\StripeWebhookVerifier;
use App\Models\MembershipTier;
use App\Models\MemberSubscription;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound Stripe webhook (POST /webhooks/stripe) — Phase 4 · M5.3. UNAUTHENTICATED + UNTRUSTED: trust is the
 * HMAC over the raw body (StripeWebhookVerifier), never reachability. Fail-closed, mirroring the mail webhook:
 * dormant (404) until Stripe is enabled + a webhook secret is set; oversize → 413; forged/missing signature →
 * 401 (no DB write); malformed JSON → 422; a verified `checkout.session.completed` GRANTS the tier idempotently
 * (re-deduped on the Stripe session id); any other event type is acknowledged 200 without action.
 *
 * It NEVER fetches a URL from the payload (not an SSRF sink) and NEVER charges — it only RECEIVES the result of
 * a payment a real customer made on Stripe's hosted page after the operator enabled live keys.
 */
final class StripeWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 524288;

    public function __invoke(Request $request, StripeWebhookVerifier $verifier, MembershipService $memberships): JsonResponse
    {
        if (! $verifier->configured()) {
            abort(404);
        }

        $raw = (string) $request->getContent();
        if (strlen($raw) > self::MAX_BODY_BYTES) {
            return response()->json(['error' => 'payload too large'], 413);
        }

        // Cryptographic gate FIRST — a bad/missing signature never touches the DB.
        if (! $verifier->verify($request)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'malformed payload'], 422);
        }

        $type = (string) ($payload['type'] ?? '');
        $object = $payload['data']['object'] ?? null;

        // Shape-only: act on a completed checkout; acknowledge everything else without action.
        if ($type !== 'checkout.session.completed' || ! is_array($object)) {
            return response()->json(['status' => 'ignored'], 200);
        }

        $sessionId = (string) ($object['id'] ?? '');
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];
        $userId = (int) ($metadata['user_id'] ?? 0);
        $tierId = (int) ($metadata['tier_id'] ?? 0);

        if ($sessionId === '' || $userId === 0 || $tierId === 0) {
            return response()->json(['status' => 'incomplete'], 422);
        }

        // Idempotency: a session id already granted is acknowledged, not re-granted (provider retry / replay).
        if (MemberSubscription::query()->where('provider', 'stripe')->where('provider_ref', $sessionId)->exists()) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $user = User::find($userId);
        $tier = MembershipTier::query()->where('is_active', true)->find($tierId);
        if (! $user instanceof User || ! $tier instanceof MembershipTier) {
            return response()->json(['status' => 'unknown'], 200); // acknowledge; cannot map → no grant
        }

        // Grant. Subscriptions carry an interval-length expiry; renewal events (invoice.*) are out of scope for
        // this shape-only receiver (documented in ADR-0065). A one-time purchase has no expiry.
        $expiresAt = match ($tier->interval) {
            'yearly' => now()->addYear(),
            'monthly' => now()->addMonth(),
            default => null,
        };
        $memberships->activate($user, $tier, 'stripe', $sessionId, $expiresAt);

        return response()->json(['status' => 'ok'], 200);
    }
}
