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

    private ?HtmlSanitizer $sanitizer = null;

    public function sanitize(string $html): string
    {
        return ($this->sanitizer ??= new HtmlSanitizer($this->config()))->sanitize($html);
    }

    private function config(): HtmlSanitizerConfig
    {
        return (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('h1')->allowElement('h2')->allowElement('h3')
            ->allowElement('h4')->allowElement('h5')->allowElement('h6')
            ->allowElement('strong')->allowElement('em')
            ->allowElement('s')->allowElement('del')->allowElement('u')
            ->allowElement('code')->allowElement('pre')
            ->allowElement('ul')->allowElement('ol')->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('br')->allowElement('hr')
            ->allowElement('a', ['href'])
            ->allowElement('span', ['class'])
            ->allowElement('img', ['src', 'alt'])
            ->allowElement('table')->allowElement('thead')->allowElement('tbody')
            ->allowElement('tr')
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('details')->allowElement('summary')
            ->allowRelativeMedias()
            ->allowMediaSchemes(['http', 'https'])
            ->allowLinkSchemes(self::LINK_SCHEMES)
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->dropElement('script')
            ->dropElement('style');
    }
}
