<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;

/**
 * Drains pending webhook deliveries (ADR-0033) — the cron-driven egress, so delivery degrades gracefully on
 * the baseline (no persistent worker) tier. Each due delivery is signed (WebhookSigner) and POSTed through the
 * SSRF-safe {@see WebhookUrlGuard} (resolve→classify→pin→re-validate each redirect hop) with a short timeout;
 * a 2xx marks it delivered, anything else (incl. an SSRF block) schedules an exponential-backoff retry and
 * gives up after `max_attempts`. Idempotent at the row level: a delivery only advances its own status, so a
 * mid-kill re-run just re-attempts the rows still pending.
 */
final class WebhookDeliveryRunner
{
    public function __construct(
        private readonly WebhookSigner $signer,
        private readonly WebhookUrlGuard $guard,
    ) {}

    public function runPending(int $limit = 100): int
    {
        $due = WebhookDelivery::query()
            ->where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->with('endpoint')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        foreach ($due as $delivery) {
            $this->deliver($delivery);
            $processed++;
        }

        return $processed;
    }

    private function deliver(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;
        if (! $endpoint instanceof WebhookEndpoint || ! $endpoint->is_active) {
            $delivery->update(['status' => 'failed', 'last_error' => 'endpoint missing or inactive']);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES) ?: '{}';
        $headers = $this->signer->headers($body, $endpoint->secret);

        try {
            // The guard resolves + validates the host, pins the connection to a validated public IP, and
            // re-validates every redirect hop. An SSRF block throws (caught below → scheduled retry).
            $response = $this->guard->deliver($endpoint->url, $body, $headers, 10);

            if ($response->successful()) {
                $delivery->update([
                    'status' => 'delivered',
                    'attempts' => $delivery->attempts + 1,
                    'response_status' => $response->status(),
                    'delivered_at' => now(),
                    'last_error' => null,
                ]);

                return;
            }
            $this->scheduleRetry($delivery, "HTTP {$response->status()}", $response->status());
        } catch (\Throwable $e) {
            $this->scheduleRetry($delivery, mb_substr($e->getMessage(), 0, 250), null);
        }
    }

    private function scheduleRetry(WebhookDelivery $delivery, string $error, ?int $status): void
    {
        $attempts = $delivery->attempts + 1;
        if ($attempts >= $delivery->max_attempts) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $attempts,
                'response_status' => $status,
                'last_error' => $error,
            ]);

            return;
        }
        // Exponential backoff in minutes (2,4,8,16,…), capped at an hour.
        $backoff = min(60, 2 ** $attempts);
        $delivery->update([
            'attempts' => $attempts,
            'response_status' => $status,
            'last_error' => $error,
            'next_attempt_at' => now()->addMinutes($backoff),
        ]);
    }
}
