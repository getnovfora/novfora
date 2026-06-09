<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content\Oembed;

/**
 * The DEDICATED embed render policy (P2-M1, amendment #2) — SEPARATE from the post ContentSanitizer, which
 * forbids iframes. This is trusted server output: a provider URL is matched against a fixed allowlist; an
 * allowlisted match becomes a SINGLE sandboxed <iframe> whose src is a constructed player URL on an
 * allowlisted EMBED host (never the provider's own returned HTML). Anything else is a NevoBB link-card facade.
 */
final class EmbedPolicy
{
    /**
     * Match a URL to an allowlisted provider; return the constructed embed src (+ provider/id), or null.
     *
     * @return array{provider:string, src:string, host:string, id:string}|null
     */
    public function match(string $url): ?array
    {
        foreach ((array) config('hearth.oembed.providers', []) as $name => $provider) {
            if (! is_array($provider) || ! isset($provider['pattern'], $provider['embed'], $provider['host'])) {
                continue;
            }
            if (preg_match((string) $provider['pattern'], $url, $m) && isset($m[1]) && $m[1] !== '') {
                $src = sprintf((string) $provider['embed'], rawurlencode($m[1]));
                // Defence: the CONSTRUCTED src host must equal the provider's declared embed host. (It always
                // will, since `embed` is a fixed template — but this pins the invariant against a config typo.)
                if (parse_url($src, PHP_URL_HOST) !== $provider['host']) {
                    continue;
                }

                return ['provider' => (string) $name, 'src' => $src, 'host' => (string) $provider['host'], 'id' => (string) $m[1]];
            }
        }

        return null;
    }

    /** Build the single sandboxed iframe for an allowlisted embed src. Trusted output — NOT the post sanitizer. */
    public function iframe(string $src, ?string $title = null): string
    {
        $sandbox = (string) config('hearth.oembed.sandbox', 'allow-scripts allow-same-origin allow-popups allow-presentation');
        $allow = (string) config('hearth.oembed.allow', 'encrypted-media; fullscreen; picture-in-picture');

        return '<div class="hearth-embed-frame">'
            .'<iframe src="'.$this->esc($src).'"'
            .' title="'.$this->esc($title !== null && trim($title) !== '' ? $title : 'Embedded media').'"'
            .' sandbox="'.$this->esc($sandbox).'"'
            .' allow="'.$this->esc($allow).'"'
            .' loading="lazy" referrerpolicy="strict-origin-when-cross-origin"'
            .' frameborder="0" allowfullscreen></iframe></div>';
    }

    /** Build a NevoBB link-card facade for a non-allowlisted (or unresolved) URL. Plain, safe — no provider HTML. */
    public function facade(string $url, ?string $title = null): string
    {
        $safe = $this->safeHttpUrl($url);
        if ($safe === null) {
            return ''; // not a safe http(s) link → render nothing
        }

        $label = $title !== null && trim($title) !== '' ? $title : $safe;
        $host = (string) (parse_url($safe, PHP_URL_HOST) ?? '');

        return '<a class="hearth-embed-facade" href="'.$this->esc($safe).'" rel="nofollow noopener noreferrer" target="_blank">'
            .'<span class="hearth-embed-facade-title">'.$this->esc($label).'</span>'
            .'<span class="hearth-embed-facade-host">'.$this->esc($host).'</span></a>';
    }

    private function safeHttpUrl(string $url): ?string
    {
        $scheme = strtolower((string) (parse_url($url, PHP_URL_SCHEME) ?? ''));

        return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) ? $url : null;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
