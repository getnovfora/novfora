<?php
// SPDX-License-Identifier: Apache-2.0

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Canonical (TipTap JSON) -> safe HTML.  Spike 0, criterion #4 — the SECURITY BOUNDARY.
 *
 * HTML is ALWAYS generated server-side from the canonical document; the browser never supplies HTML.
 * Defense in depth:
 *   (1) every text value and URL is escaped/validated while mapping JSON -> HTML;
 *   (2) the mapped HTML is then passed through an allowlist sanitizer (the final gate).
 * A bug in (1) is caught by (2). The sanitizer's allowlist is the authoritative safe surface.
 *
 * Spike node set: doc, paragraph, heading(h1-h3), bulletList, orderedList, listItem,
 * blockquote, codeBlock, hardBreak, text(marks: bold, italic, code, link), mention.
 */
final class CanonicalRenderer
{
    private const MAX_HEADING = 3;
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto'];

    public function toSafeHtml(array $doc): string
    {
        $mapped = $this->nodesToHtml($doc['content'] ?? []);
        return $this->sanitizer()->sanitize($mapped);
    }

    /** Plain-text projection (search/indexing in the real app; useful in tests). */
    public function toText(array $doc): string
    {
        return trim($this->nodesToText($doc['content'] ?? []));
    }

    private function sanitizer(): HtmlSanitizer
    {
        // Explicit allowlist for the spike node set. Anything not listed is dropped.
        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')
            ->allowElement('h1')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('strong')
            ->allowElement('em')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('br')
            ->allowElement('a', ['href'])
            ->allowElement('span', ['class'])
            ->allowElement('img', ['src', 'alt'])
            ->allowRelativeMedias()
            ->allowMediaSchemes(['http', 'https'])
            ->allowLinkSchemes(self::ALLOWED_LINK_SCHEMES)
            ->allowRelativeLinks()
            ->forceAttribute('a', 'rel', 'nofollow noopener noreferrer')
            ->dropElement('script')
            ->dropElement('style');

        return new HtmlSanitizer($config);
    }

    private function nodesToHtml(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            if (is_array($node)) {
                $out .= $this->nodeToHtml($node);
            }
        }
        return $out;
    }

    private function nodeToHtml(array $node): string
    {
        $type = $node['type'] ?? null;
        $children = $node['content'] ?? [];

        return match ($type) {
            'paragraph'   => '<p>' . $this->nodesToHtml($children) . '</p>',
            'heading'     => $this->heading($node),
            'bulletList'  => '<ul>' . $this->nodesToHtml($children) . '</ul>',
            'orderedList' => '<ol>' . $this->nodesToHtml($children) . '</ol>',
            'listItem'    => '<li>' . $this->nodesToHtml($children) . '</li>',
            'blockquote'  => '<blockquote>' . $this->nodesToHtml($children) . '</blockquote>',
            'codeBlock'   => '<pre><code>' . $this->esc($this->rawText($children)) . '</code></pre>',
            'hardBreak'   => '<br>',
            'text'        => $this->text($node),
            'mention'     => $this->mention($node),
            'image'       => $this->image($node),
            // Unknown node types: recurse into children so content is never silently lost,
            // but never emit an unknown tag. The sanitizer is the backstop regardless.
            default       => $this->nodesToHtml($children),
        };
    }

    private function heading(array $node): string
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        $level = max(1, min(self::MAX_HEADING, $level)); // clamp to h1..h3
        return "<h{$level}>" . $this->nodesToHtml($node['content'] ?? []) . "</h{$level}>";
    }

    private function text(array $node): string
    {
        $text = $this->esc((string) ($node['text'] ?? ''));
        foreach ($node['marks'] ?? [] as $mark) {
            if (is_array($mark)) {
                $text = $this->applyMark($mark, $text);
            }
        }
        return $text;
    }

    private function applyMark(array $mark, string $inner): string
    {
        return match ($mark['type'] ?? null) {
            'bold'   => "<strong>{$inner}</strong>",
            'italic' => "<em>{$inner}</em>",
            'code'   => "<code>{$inner}</code>",
            'link'   => $this->link($mark, $inner),
            default  => $inner, // unknown mark -> leave the (already-escaped) text untouched
        };
    }

    private function link(array $mark, string $inner): string
    {
        $href = trim((string) ($mark['attrs']['href'] ?? ''));
        if (! $this->safeHref($href)) {
            return $inner; // drop unsafe link, keep the escaped text
        }
        return '<a href="' . $this->esc($href) . '">' . $inner . '</a>';
    }

    private function mention(array $node): string
    {
        $label = $node['attrs']['label'] ?? ($node['attrs']['id'] ?? '');
        return '<span class="mention">@' . $this->esc((string) $label) . '</span>';
    }

    private function image(array $node): string
    {
        $src = trim((string) ($node['attrs']['src'] ?? ''));
        if (! $this->safeHref($src)) {
            return ''; // drop images with an unsafe src (e.g. javascript:/data:)
        }
        $alt = $this->esc((string) ($node['attrs']['alt'] ?? ''));
        return '<img src="' . $this->esc($src) . '" alt="' . $alt . '">';
    }

    /** Defense-in-depth scheme check; the sanitizer's allowLinkSchemes is the backstop. */
    private function safeHref(string $href): bool
    {
        if ($href === '') {
            return false;
        }
        if (str_starts_with($href, '/') || str_starts_with($href, '#')) {
            return true; // relative path / same-page anchor
        }
        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false || $scheme === '') {
            // no scheme and not relative-prefixed: treat as relative only if it has no colon
            return ! str_contains($href, ':');
        }
        return in_array(strtolower($scheme), self::ALLOWED_LINK_SCHEMES, true);
    }

    /** Concatenate raw text (code blocks render plain text, no marks). */
    private function rawText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? '') === 'text') {
                $out .= (string) ($n['text'] ?? '');
            } elseif (! empty($n['content'])) {
                $out .= $this->rawText($n['content']);
            }
        }
        return $out;
    }

    private function nodesToText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $type = $n['type'] ?? '';
            if ($type === 'text') {
                $out .= (string) ($n['text'] ?? '');
            } elseif ($type === 'mention') {
                $out .= '@' . (string) ($n['attrs']['label'] ?? '');
            } elseif ($type === 'hardBreak') {
                $out .= "\n";
            }
            if (! empty($n['content'])) {
                $out .= $this->nodesToText($n['content']);
            }
            if (in_array($type, ['paragraph', 'heading', 'blockquote', 'codeBlock', 'listItem'], true)) {
                $out .= "\n";
            }
        }
        return $out;
    }

    private function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
