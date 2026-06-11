<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Deliverability\Suppressor;
use App\Deliverability\Webhook\ProviderWebhookParser;
use App\Deliverability\Webhook\WebhookVerifier;
use App\Models\MailWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Spike P2 — inbound provider bounce/complaint webhook (POST /webhooks/mail/{provider}). UNAUTHENTICATED +
 * UNTRUSTED: trust is the HMAC over the raw body (never reachability). Defends against oversize (413),
 * forged/missing signature (401, no DB write), malformed JSON (422), and replay (a verified event is
 * recorded under a UNIQUE key; a duplicate is acknowledged 200 without re-suppressing). It only ever WRITES
 * to the suppression list — it never fetches a URL from the payload, so it is not an SSRF sink. Registered
 * only when the webhook path is configured (dormant by default).
 */
final class MailWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $provider,
        WebhookVerifier $verifier,
        ProviderWebhookParser $parser,
        Suppressor $suppressor,
    ): JsonResponse {
        if (! $verifier->configured()) {
            abort(404);
        }

        $raw = (string) $request->getContent();
        $max = (int) config('novfora.deliverability.webhook.max_body_bytes', 262144);
        if (strlen($raw) > $max) {
            return response()->json(['error' => 'payload too large'], 413);
        }

        // Cryptographic gate FIRST — a bad/missing signature never touches the DB or the suppression list.
        if (! $verifier->verify($request)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $payload = json_decode($raw, true);
        if (! is_array($payload)) {
            return response()->json(['error' => 'malformed payload'], 422);
        }

        $result = $parser->parse($provider, $payload);

        // Replay/idempotency: namespace the dedupe key by provider; fall back to a body hash when the
        // provider gives no event id. A duplicate (provider retry / replay) is acknowledged, not re-applied.
        $dedupeKey = substr($provider.'|'.($result['eventKey'] ?? ('sha256:'.hash('sha256', $raw))), 0, 191);
        $event = MailWebhookEvent::firstOrCreate(
            ['event_key' => $dedupeKey],
            ['provider' => $provider, 'created_at' => now()],
        );
        if (! $event->wasRecentlyCreated) {
            return response()->json(['status' => 'duplicate'], 200);
        }

        $suppressed = 0;
        foreach ($result['events'] as $bounceEvent) {
            if ($suppressor->applyEvent($bounceEvent)) {
                $suppressed++;
            }
        }

        return response()->json(['status' => 'ok', 'suppressed' => $suppressed], 200);
    }
}
