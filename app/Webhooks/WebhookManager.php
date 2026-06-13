<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Webhooks;

use App\Models\WebhookEndpoint;
use App\Support\Audit;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Manages outbound webhook endpoints (ADR-0033) — the single audited writer. The DELIVERABLE EVENTS are a
 * closed set (the core domain events); an endpoint's subscription is intersected with it, so a stored endpoint
 * can never subscribe to something undeliverable.
 *
 * SSRF GUARD (apex): the destination URL is validated on create/update — http(s) only, and (unless explicitly
 * allowed in config for local development) loopback / private / link-local / reserved hosts are refused, so a
 * webhook can't be pointed at an internal service. Full DNS-rebinding protection is out of scope and documented.
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

    public function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new InvalidArgumentException('Webhook URL must be a valid http(s) URL.');
        }
        if (config('novfora.webhooks.allow_private', false)) {
            return;
        }
        if ($this->isPrivateHost($host)) {
            throw new InvalidArgumentException('Webhook URL may not target a loopback, private, or reserved address.');
        }
    }

    private function isPrivateHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]')); // strip IPv6 brackets
        if (in_array($host, ['localhost', '0.0.0.0'], true)) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            // A literal IP is private/reserved unless it passes the NO_PRIV_RANGE + NO_RES_RANGE filter.
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        // Hostnames: refuse the obvious internal suffixes; DNS resolution (rebinding) is out of scope.
        return str_ends_with($host, '.local') || str_ends_with($host, '.internal') || $host === 'localhost';
    }
}
