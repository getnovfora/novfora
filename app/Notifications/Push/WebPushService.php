<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Notifications\Push;

use App\Models\PushSubscription as PushSubscriptionModel;
use App\Settings\Settings;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Sends an encrypted Web Push message to a single subscription via minishlink/web-push, signed with the site's
 * VAPID keypair (read from encrypted settings) — Phase 4 · M3.2. Cron-tolerant: callers dispatch this from a
 * queued job drained by the baseline cron, so no persistent worker is needed.
 *
 * ⚠ The actual delivery is NOT validated against a live push service in this environment (no browser
 * subscription / push endpoint here); the library is real but the round-trip is unproven. See ADR-0058.
 */
class WebPushService
{
    public function __construct(private readonly Settings $settings) {}

    /** Push is sendable only when a full VAPID keypair + subject are configured. */
    public function isConfigured(): bool
    {
        return $this->settings->string('push.vapid_public_key') !== ''
            && $this->settings->secretIsSet('push.vapid_private_key')
            && $this->settings->string('push.vapid_subject') !== '';
    }

    /**
     * Send one push. Returns TRUE if the subscription is still valid, FALSE if it is gone (HTTP 410/404) and
     * the caller should prune it. A transient/library error returns TRUE (do not prune on a blip).
     *
     * @param  array<string, mixed>  $message
     */
    public function send(PushSubscriptionModel $subscription, array $message): bool
    {
        if (! $this->isConfigured()) {
            return true; // misconfigured — nothing to send, never prune
        }

        try {
            $report = $this->client()->sendOneNotification(
                $this->toLibrarySubscription($subscription),
                (string) json_encode($message, JSON_THROW_ON_ERROR),
            );

            return ! $report->isSubscriptionExpired();
        } catch (\Throwable $e) {
            Log::warning('Web push send failed', ['endpoint_hash' => $subscription->getAttribute('endpoint_hash'), 'error' => $e->getMessage()]);

            return true; // transient — keep the subscription
        }
    }

    private function client(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => $this->settings->string('push.vapid_subject'),
                'publicKey' => $this->settings->string('push.vapid_public_key'),
                'privateKey' => (string) $this->settings->get('push.vapid_private_key'),
            ],
        ]);
    }

    private function toLibrarySubscription(PushSubscriptionModel $subscription): Subscription
    {
        return Subscription::create([
            'endpoint' => (string) $subscription->endpoint,
            'keys' => [
                'p256dh' => (string) $subscription->public_key,
                'auth' => (string) $subscription->auth_token,
            ],
            'contentEncoding' => (string) ($subscription->getAttribute('content_encoding') ?: 'aes128gcm'),
        ]);
    }
}
