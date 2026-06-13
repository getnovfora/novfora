<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Turns a domain event into pending webhook DELIVERIES (ADR-0033) — one per active endpoint subscribed to the
 * event. Only cheap inserts happen here (on the triggering action's path); the actual HTTP POST is deferred to
 * the cron-driven runner, so the baseline tier never blocks a post/reply on an outbound request. Defensive by
 * design: any failure (missing table pre-install, a bad endpoint row) is swallowed — a webhook must NEVER break
 * the action that triggered it.
 */
final class WebhookDispatcher
{
    /**
     * @param  array<string,mixed>  $data
     */
    public function dispatch(string $event, array $data): void
    {
        if (! in_array($event, WebhookManager::EVENTS, true)) {
            return;
        }
        try {
            // Hot-path short-circuit: with no active endpoints (the common case) this is a single cache read
            // and ZERO DB queries, so a post/reaction/follow stays within its query budget. The flag is kept
            // fresh by WebhookManager on every endpoint write.
            if (! Cache::get(WebhookManager::ACTIVE_FLAG, false)) {
                return;
            }
            if (! Schema::hasTable('webhook_endpoints')) {
                return;
            }
            $endpoints = WebhookEndpoint::query()->where('is_active', true)->get()
                ->filter(fn (WebhookEndpoint $endpoint): bool => $endpoint->subscribesTo($event));
            if ($endpoints->isEmpty()) {
                return;
            }
            $payload = ['event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data];
            foreach ($endpoints as $endpoint) {
                WebhookDelivery::create([
                    'webhook_endpoint_id' => $endpoint->getKey(),
                    'event' => $event,
                    'payload' => $payload,
                    'status' => 'pending',
                    'attempts' => 0,
                    'next_attempt_at' => now(),
                ]);
            }
        } catch (\Throwable) {
            // A webhook dispatch must never break the triggering action.
        }
    }
}
