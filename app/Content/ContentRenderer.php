<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content;

use Illuminate\Support\Str;

/**
 * Orchestrates canonical → display rendering (ADR-0005). Given a post's body_format + body_canonical it
 * produces the sanitized HTML cache and the plain-text search projection. Both formats funnel through the
 * same {@see ContentSanitizer} allowlist, so the security boundary is uniform regardless of input mode.
 *
 *   tiptap_json → {@see CanonicalRenderer} (JSON → escaped HTML → sanitize)
 *   markdown    → CommonMark (raw HTML escaped, unsafe links denied) → sanitize
 */
final class ContentRenderer
{
    public function __construct(
        private readonly CanonicalRenderer $canonical,
        private readonly ContentSanitizer $sanitizer,
    ) {}

    /**
     * @param  array<string,mixed>  $doc  the canonical document (TipTap doc, or {"source": "...markdown"})
     * @param  list<string>  $restrict  anti-spam suppression for gated authors (security §2.4): 'links' /
     *                                  'images'. Applied at the (shared) sanitize step, so it is uniform
     *                                  across both input modes. Default [] = unrestricted.
     * @return array{html:string, text:string}
     */
    public function render(string $format, array $doc, array $restrict = []): array
    {
        return match ($format) {
            'markdown' => $this->renderMarkdown((string) ($doc['source'] ?? ''), $restrict),
            default => [
                'html' => $this->canonical->toSafeHtml($doc, $restrict),
                'text' => $this->canonical->toText($doc),
            ],
        };
    }

    /**
     * @param  list<string>  $restrict
     * @return array{html:string, text:string}
     */
    private function renderMarkdown(string $source, array $restrict = []): array
    {
        // Escape raw HTML and deny unsafe links at the converter, then sanitize (defense in depth).
        $raw = Str::markdown($source, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);

        return [
            'html' => $this->sanitizer->sanitize($raw, $restrict),
            'text' => trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
        ];
    }
}
