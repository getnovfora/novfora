<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content;

/**
 * Canonical (TipTap/ProseMirror JSON) → safe HTML. The SECURITY BOUNDARY (ADR-0005, Spike-0 criterion #4),
 * ported from the spike and extended to the M2 node set.
 *
 * Defense in depth:
 *   (1) every text value and URL is escaped/validated while mapping JSON → HTML;
 *   (2) the mapped HTML is passed through the allowlist {@see ContentSanitizer} (the final, authoritative gate).
 * A bug in (1) is caught by (2). The browser never supplies HTML.
 *
 * M2 node set: doc, paragraph, heading(h1–h3), bulletList, orderedList, listItem, blockquote, codeBlock,
 * horizontalRule, hardBreak, table/tableRow/tableHeader/tableCell, spoiler(→details/summary),
 * image, mention, text(marks: bold, italic, strike, underline, code, link).
 */
final class CanonicalRenderer
{
    private const MAX_HEADING = 3;

    /** @var list<string> */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto'];

    public function __construct(private readonly ContentSanitizer $sanitizer) {}

    /** @param array<string,mixed> $doc */
    public function toSafeHtml(array $doc): string
    {
        return $this->sanitizer->sanitize($this->nodesToHtml($doc['content'] ?? []));
    }

    /** Plain-text projection for search/excerpts. @param array<string,mixed> $doc */
    public function toText(array $doc): string
    {
        return trim($this->nodesToText($doc['content'] ?? []));
    }

    /** @param array<int,mixed> $nodes */
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

    /** @param array<string,mixed> $node */
    private function nodeToHtml(array $node): string
    {
        $type = $node['type'] ?? null;
        $children = is_array($node['content'] ?? null) ? $node['content'] : [];

        return match ($type) {
            'paragraph' => '<p>'.$this->nodesToHtml($children).'</p>',
            'heading' => $this->heading($node),
            'bulletList' => '<ul>'.$this->nodesToHtml($children).'</ul>',
            'orderedList' => '<ol>'.$this->nodesToHtml($children).'</ol>',
            'listItem' => '<li>'.$this->nodesToHtml($children).'</li>',
            'blockquote' => '<blockquote>'.$this->nodesToHtml($children).'</blockquote>',
            'codeBlock' => '<pre><code>'.$this->esc($this->rawText($children)).'</code></pre>',
            'horizontalRule' => '<hr>',
            'hardBreak' => '<br>',
            'table' => '<table><tbody>'.$this->nodesToHtml($children).'</tbody></table>',
            'tableRow' => '<tr>'.$this->nodesToHtml($children).'</tr>',
            'tableHeader' => $this->tableCell('th', $node),
            'tableCell' => $this->tableCell('td', $node),
            'spoiler' => $this->spoiler($node),
            'text' => $this->text($node),
            'mention' => $this->mention($node),
            'image' => $this->image($node),
            // Unknown node types: recurse into children so content is never silently lost, but never emit
            // an unknown tag. The sanitizer is the backstop regardless.
            default => $this->nodesToHtml($children),
        };
    }

    /** @param array<string,mixed> $node */
    private function heading(array $node): string
    {
        $level = max(1, min(self::MAX_HEADING, (int) ($node['attrs']['level'] ?? 1)));

        return "<h{$level}>".$this->nodesToHtml($node['content'] ?? [])."</h{$level}>";
    }

    /** @param array<string,mixed> $node */
    private function tableCell(string $tag, array $node): string
    {
        $attrs = '';
        foreach (['colspan', 'rowspan'] as $key) {
            $value = (int) ($node['attrs'][$key] ?? 0);
            if ($value > 1) {
                $attrs .= " {$key}=\"{$value}\"";
            }
        }

        return "<{$tag}{$attrs}>".$this->nodesToHtml($node['content'] ?? [])."</{$tag}>";
    }

    /** @param array<string,mixed> $node */
    private function spoiler(array $node): string
    {
        $summary = $this->esc((string) ($node['attrs']['summary'] ?? 'Spoiler'));

        return '<details><summary>'.$summary.'</summary>'.$this->nodesToHtml($node['content'] ?? []).'</details>';
    }

    /** @param array<string,mixed> $node */
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

    /** @param array<string,mixed> $mark */
    private function applyMark(array $mark, string $inner): string
    {
        return match ($mark['type'] ?? null) {
            'bold' => "<strong>{$inner}</strong>",
            'italic' => "<em>{$inner}</em>",
            'strike' => "<s>{$inner}</s>",
            'underline' => "<u>{$inner}</u>",
            'code' => "<code>{$inner}</code>",
            'link' => $this->link($mark, $inner),
            default => $inner, // unknown mark → leave the (already-escaped) text untouched
        };
    }

    /** @param array<string,mixed> $mark */
    private function link(array $mark, string $inner): string
    {
        $href = trim((string) ($mark['attrs']['href'] ?? ''));
        if (! $this->safeHref($href)) {
            return $inner; // drop unsafe link, keep the escaped text
        }

        return '<a href="'.$this->esc($href).'">'.$inner.'</a>';
    }

    /** @param array<string,mixed> $node */
    private function mention(array $node): string
    {
        $label = $node['attrs']['label'] ?? ($node['attrs']['id'] ?? '');

        return '<span class="mention">@'.$this->esc((string) $label).'</span>';
    }

    /** @param array<string,mixed> $node */
    private function image(array $node): string
    {
        $src = trim((string) ($node['attrs']['src'] ?? ''));
        if (! $this->safeHref($src)) {
            return ''; // drop images with an unsafe src (e.g. javascript:/data:)
        }
        $alt = $this->esc((string) ($node['attrs']['alt'] ?? ''));

        return '<img src="'.$this->esc($src).'" alt="'.$alt.'">';
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
            return ! str_contains($href, ':'); // no scheme + no colon → relative
        }

        return in_array(strtolower($scheme), self::ALLOWED_LINK_SCHEMES, true);
    }

    /** @param array<int,mixed> $nodes Concatenate raw text (code blocks render plain text, no marks). */
    private function rawText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? '') === 'text') {
                $out .= (string) ($n['text'] ?? '');
            } elseif (! empty($n['content']) && is_array($n['content'])) {
                $out .= $this->rawText($n['content']);
            }
        }

        return $out;
    }

    /** @param array<int,mixed> $nodes */
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
                $out .= '@'.(string) ($n['attrs']['label'] ?? '');
            } elseif ($type === 'hardBreak') {
                $out .= "\n";
            }
            if (! empty($n['content']) && is_array($n['content'])) {
                $out .= $this->nodesToText($n['content']);
            }
            if (in_array($type, ['paragraph', 'heading', 'blockquote', 'codeBlock', 'listItem', 'tableCell', 'tableHeader'], true)) {
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
