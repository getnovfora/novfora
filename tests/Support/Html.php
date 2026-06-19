<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace Tests\Support;

/** Tiny helpers for asserting against rendered HTML in feature tests. */
final class Html
{
    /**
     * The visible text of the breadcrumb trail (labels only), tags stripped and whitespace collapsed.
     * Isolates the <nav aria-label="Breadcrumb"> block so assertions never collide with the same word
     * appearing elsewhere on the page (e.g. the global "Forums" nav link).
     */
    public static function breadcrumbTrail(string $html): string
    {
        if (preg_match('/<nav[^>]*aria-label="Breadcrumb".*?<\/nav>/s', $html, $m) !== 1) {
            return '';
        }

        // Decode entities so labels read as the human sees them ("What&#039;s new" → "What's new").
        $text = html_entity_decode(strip_tags($m[0]), ENT_QUOTES | ENT_HTML5);

        return trim(preg_replace('/\s+/', ' ', $text) ?? '');
    }
}
