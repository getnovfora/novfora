<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\WebhookEndpoint;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Manages outbound webhook endpoints (ADR-0033) — the single audited writer. The DELIVERABLE EVENTS are a
 * closed set (the core domain events); an endpoint's subscription is intersected with it, so a stored endpoint
 * can never subscribe to something undeliverable.
 *
 * SSRF GUARD (apex): the destination URL is validated by {@see WebhookUrlGuard} — a cheap http(s) / literal-IP
 * / internal-hostname check on create/update, plus the AUTHORITATIVE resolve→classify→pin→re-validate-each-
 * redirect-hop check at delivery time ({@see WebhookDeliveryRunner}), which closes the DNS-rebinding gap.
 * `novfora.webhooks.allow_private` opens it for local dev only.
 */
final class WebhookManager
{
    /** The closed set of domain events a webhook may subscribe to. */
    public const EVENTS = ['post.created', 'topic.created', 'user.followed', 'reputation.awarded', 'message.sent'];

    /**
     * A cached "are there any active endpoints?" flag. The dispatcher reads it on the (hot) event path so that
     * with NO endpoints configured — the overwhelmingly common case — a post/reaction/follow adds ZERO webhook
     * DB queries (it stays under the documented per-action query budgets). Refreshed on every endpoint write.
     */
    public const ACTIVE_FLAG = 'novfora:webhooks:has_active';

    public function __construct(private readonly WebhookUrlGuard $guard) {}

    /**
     * @param  list<string>  $events
     */
    public function create(string $url, array $events, ?string $description = null): WebhookEndpoint
    {
        $this->assertSafeUrl($url);
        $endpoint = WebhookEndpoint::create([
            'url' => $url,
            'secret' => 'whsec_'.Str::random(48),
            'events' => $this->cleanEvents($events),
            'is_active' => true,
            'description' => $description,
        ]);
        Audit::log('webhook.endpoint.created', $endpoint, ['url' => $url, 'events' => $endpoint->events]);
        $this->refreshActiveFlag();

        return $endpoint;
    }

    /**
     * @param  array{url?:string,events?:list<string>,is_active?:bool,description?:?string}  $attrs
     */
    public function update(WebhookEndpoint $endpoint, array $attrs): void
    {
        $changes = [];
        if (array_key_exists('url', $attrs)) {
            $this->assertSafeUrl($attrs['url']);
            $changes['url'] = $attrs['url'];
        }
        if (array_key_exists('events', $attrs)) {
            $changes['events'] = $this->cleanEvents($attrs['events']);
        }
        if (array_key_exists('is_active', $attrs)) {
            $changes['is_active'] = (bool) $attrs['is_active'];
        }
        if (array_key_exists('description', $attrs)) {
            $changes['description'] = $attrs['description'];
        }
        $endpoint->update($changes);
        Audit::log('webhook.endpoint.updated', $endpoint, ['url' => $endpoint->url]);
        $this->refreshActiveFlag();
    }

    public function delete(WebhookEndpoint $endpoint): void
    {
        Audit::log('webhook.endpoint.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();
        $this->refreshActiveFlag();
    }

    /** Recompute the cached active-endpoints flag (cheap, only on a rare endpoint write). */
    private function refreshActiveFlag(): void
    {
        try {
            Cache::forever(self::ACTIVE_FLAG, WebhookEndpoint::query()->where('is_active', true)->exists());
        } catch (\Throwable) {
            // A flag we can't write just means the dispatcher will fall through to its own table check.
        }
    }

    /** @param list<string> $events @return list<string> */
    private function cleanEvents(array $events): array
    {
        return array_values(array_intersect(self::EVENTS, $events));
    }

    /** Create/update-time URL validation — delegates to the apex {@see WebhookUrlGuard}. */
    public function assertSafeUrl(string $url): void
    {
        $this->guard->assertConfigUrl($url);
    }
}
