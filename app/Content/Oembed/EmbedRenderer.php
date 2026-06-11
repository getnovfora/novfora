<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

/**
 * Injects trusted embed HTML into a post's ALREADY-SANITIZED display HTML (P2-M1). The canonical 'embed' node
 * renders (via CanonicalRenderer) to a sanitizer-surviving placeholder span whose class carries a URL token;
 * the post ContentSanitizer never sees an iframe (so it still strips raw ones — amendment #2). AFTER
 * sanitization, this replaces each placeholder with the OEmbedService-built iframe/facade. Called by
 * PostService when (re)building body_html_cache.
 */
final class EmbedRenderer
{
    public function __construct(private readonly OEmbedService $oembed) {}

    /**
     * @param  array<string,mixed>  $canonical  the post's canonical doc (TipTap)
     */
    public function inject(string $html, array $canonical): string
    {
        foreach ($this->embedUrls($canonical) as $url) {
            $token = self::token($url);
            $trusted = $this->oembed->render($url);
            // The sanitizer keeps span[class]; match the placeholder by its class token (tolerating extra
            // attributes/content) and swap in the trusted HTML. preg_replace's replacement is a literal here
            // (no backrefs in $trusted are interpreted because we pass it as the replacement of a callback-free
            // call) — but to be safe against '$'/'\\' in the embed HTML, use a callback.
            $pattern = '~<span class="novfora-embed embed-'.preg_quote($token, '~').'"[^>]*>.*?</span>~s';
            $html = preg_replace_callback($pattern, fn (): string => $trusted, $html) ?? $html;
        }

        return $html;
    }

    /** The 16-hex placeholder token for a URL. Must match CanonicalRenderer's embed placeholder. */
    public static function token(string $url): string
    {
        return substr(hash('sha256', $url), 0, 16);
    }

    /**
     * Embed URLs in the canonical doc, in document order.
     *
     * @param  array<string,mixed>  $canonical
     * @return list<string>
     */
    private function embedUrls(array $canonical): array
    {
        $urls = [];
        $walk = function ($nodes) use (&$walk, &$urls): void {
            foreach ((array) $nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? '') === 'embed' && isset($node['attrs']['url']) && is_string($node['attrs']['url'])) {
                    $urls[] = $node['attrs']['url'];
                }
                if (isset($node['content'])) {
                    $walk($node['content']);
                }
            }
        };
        $walk($canonical['content'] ?? []);

        return array_values(array_unique($urls));
    }
}
