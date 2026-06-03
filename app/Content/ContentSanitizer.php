<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The allowlist HTML sanitizer — the authoritative safe surface (security §4 / ADR-0005).
 *
 * EVERY path that produces post HTML (the TipTap-JSON mapper in CanonicalRenderer and the Markdown
 * converter in ContentRenderer) passes its output through this gate; anything not explicitly allowed is
 * dropped. Client-submitted HTML is never trusted — HTML is always (re)generated server-side from the
 * canonical source and then sanitized here.
 */
final class ContentSanitizer
{
    /** @var list<string> */
    private const LINK_SCHEMES = ['http', 'https', 'mailto'];

    /** @var array<string, HtmlSanitizer> one cached sanitizer per restriction set */
    private array $sanitizers = [];

    /**
     * @param  list<string>  $restrict  anti-spam suppression for gated authors (security §2.4): 'links'
     *                                  drops <a> tags (keeping their text), 'images' removes <img>. The
     *                                  canonical source is untouched (ADR-0005); only this display HTML is
     *                                  restricted, recomputed whenever the cache is regenerated.
     */
    public function sanitize(string $html, array $restrict = []): string
    {
        sort($restrict);
        $key = implode(',', $restrict);

        return ($this->sanitizers[$key] ??= new HtmlSanitizer($this->config($restrict)))->sanitize($html);
    }

    /** @param list<string> $restrict */
    private function config(array $restrict = []): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('h1')->allowElement('h2')->allowElement('h3')
            ->allowElement('h4')->allowElement('h5')->allowElement('h6')
            ->allowElement('strong')->allowElement('em')
            ->allowElement('s')->allowElement('del')->allowElement('u')
            ->allowElement('code')->allowElement('pre')
            ->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('br')->allowElement('hr')
            ->allowElement('span', ['class'])
            ->allowElement('table')->allowElement('thead')->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('details')->allowElement('summary')
            ->allowRelativeMedias()
            ->allowMediaSchemes(['http', 'https'])
            ->allowLinkSchemes(self::LINK_SCHEMES)
            ->allowRelativeLinks()
            ->dropElement('script')
            ->dropElement('style');

        // NOTE: HtmlSanitizerConfig is IMMUTABLE — every method returns a NEW instance, so each call must be
        // reassigned. Links: blockElement strips the <a> tag but KEEPS its text (suppression, not deletion);
        // allow otherwise. Images carry no text → dropElement removes them entirely when suppressed.
        $config = in_array('links', $restrict, true)
            ? $config->blockElement('a')
            : $config->allowElement('a', ['href'])->forceAttribute('a', 'rel', 'nofollow noopener noreferrer');

        $config = in_array('images', $restrict, true)
            ? $config->dropElement('img')
            : $config->allowElement('img', ['src', 'alt']);

        return $config;
    }
}
