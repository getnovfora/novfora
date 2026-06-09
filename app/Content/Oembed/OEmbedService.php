<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

use App\Models\OembedCache;

/**
 * oEmbed orchestrator (P2-M1). Given a URL, returns TRUSTED embed HTML:
 *   - allowlisted provider → a single sandboxed iframe (EmbedPolicy) from a constructed player URL, with an
 *     optional best-effort title from the provider's oEmbed endpoint fetched through SsrfGuard;
 *   - anything else        → a NevoBB link-card facade (no fetch, no provider HTML).
 * The result is cached per URL so the (at most one) network fetch and the render run once per TTL.
 */
final class OEmbedService
{
    private const TTL_DAYS = 7;

    public function __construct(
        private readonly EmbedPolicy $policy,
        private readonly SsrfGuard $guard,
    ) {}

    public function render(string $url): string
    {
        if (! (bool) config('hearth.oembed.enabled', true)) {
            return $this->policy->facade($url); // feature off → always a facade, never an embed
        }

        $hash = hash('sha256', $url);
        $cached = OembedCache::query()->where('url_hash', $hash)->first();
        if ($cached instanceof OembedCache && $cached->expires_at !== null && $cached->expires_at->isFuture()) {
            return (string) $cached->html;
        }

        $match = $this->policy->match($url);

        if ($match === null) {
            $html = $this->policy->facade($url);
            $this->store($hash, $url, $html, 'facade');

            return $html;
        }

        // Allowlisted: build OUR iframe from the validated src; the provider HTML is never used. The title is
        // a best-effort enrichment fetched through the SSRF guard — a failure just yields a generic title.
        $html = $this->policy->iframe($match['src'], $this->fetchTitle($match));
        $this->store($hash, $url, $html, $match['provider']);

        return $html;
    }

    /** Best-effort title from the provider's oEmbed endpoint, via the SSRF guard. Null on any failure. */
    private function fetchTitle(array $match): ?string
    {
        $endpoint = $this->oembedEndpoint($match);
        if ($endpoint === null) {
            return null;
        }

        $body = $this->guard->safeGet($endpoint, (array) config('hearth.oembed', []));
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) && isset($data['title']) && is_string($data['title']) ? $data['title'] : null;
    }

    /** @param array{provider:string, id:string, src:string, host:string} $match */
    private function oembedEndpoint(array $match): ?string
    {
        return match ($match['provider']) {
            'youtube' => 'https://www.youtube.com/oembed?format=json&url='.rawurlencode('https://www.youtube.com/watch?v='.$match['id']),
            'vimeo' => 'https://vimeo.com/api/oembed.json?url='.rawurlencode('https://vimeo.com/'.$match['id']),
            default => null,
        };
    }

    private function store(string $hash, string $url, string $html, string $provider): void
    {
        OembedCache::query()->updateOrCreate(
            ['url_hash' => $hash],
            ['url' => $url, 'html' => $html, 'provider' => $provider, 'expires_at' => now()->addDays(self::TTL_DAYS)],
        );
    }
}
