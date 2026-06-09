<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Content;

/**
 * Edit-history diff (P2-M1, amendment #3). FORMAT-AWARE, so a diff reflects the real edit rather than an
 * artefact of the search projection:
 *   - markdown    → diff the readable canonical source (body_canonical['source']).
 *   - tiptap_json → diff a NORMALISED, FORMATTING-PRESERVING text extraction of the doc — NOT `body_text`,
 *     which is the tags-stripped SEARCH projection and would hide a bold-/link-/image-only edit.
 *
 * The line diff is a small, dependency-free LCS (no external diff library — see DECISIONS.md). Output lines
 * carry the comparable representation as PLAIN text; the view escapes them on render.
 */
final class RevisionDiffService
{
    /**
     * Diff two content versions into an ordered list of lines tagged same | add | del.
     *
     * @param  array<string,mixed>  $oldCanonical
     * @param  array<string,mixed>  $newCanonical
     * @return list<array{type:string, text:string}>
     */
    public function diff(string $oldFormat, array $oldCanonical, string $newFormat, array $newCanonical): array
    {
        return $this->lcsDiff(
            $this->toLines($this->extract($oldFormat, $oldCanonical)),
            $this->toLines($this->extract($newFormat, $newCanonical)),
        );
    }

    /**
     * The format-aware comparable representation of a version's content.
     *
     * @param  array<string,mixed>  $canonical
     */
    public function extract(string $format, array $canonical): string
    {
        return $format === 'markdown'
            ? trim((string) ($canonical['source'] ?? ''))
            : trim($this->extractTiptap($canonical));
    }

    /** Walk a TipTap doc into a formatting-preserving, line-oriented text (a line per block; inline marks kept). */
    private function extractTiptap(array $doc): string
    {
        $lines = [];
        foreach ($doc['content'] ?? [] as $node) {
            if (is_array($node)) {
                $this->blockToLines($node, $lines, 0);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string,mixed>  $node
     * @param  list<string>  $lines
     */
    private function blockToLines(array $node, array &$lines, int $depth): void
    {
        $type = $node['type'] ?? '';
        $indent = str_repeat('  ', $depth);
        $children = is_array($node['content'] ?? null) ? $node['content'] : [];

        switch ($type) {
            case 'heading':
                $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 1)));
                $lines[] = str_repeat('#', $level).' '.$this->inline($children);
                break;
            case 'paragraph':
                $lines[] = $indent.$this->inline($children);
                break;
            case 'blockquote':
                $sub = [];
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $this->blockToLines($child, $sub, 0);
                    }
                }
                foreach ($sub as $s) {
                    $lines[] = '> '.$s;
                }
                break;
            case 'bulletList':
            case 'orderedList':
                $i = 1;
                foreach ($children as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $marker = $type === 'orderedList' ? ($i++).'.' : '-';
                    $itemLines = [];
                    foreach ($item['content'] ?? [] as $ib) {
                        if (is_array($ib)) {
                            $this->blockToLines($ib, $itemLines, 0);
                        }
                    }
                    $first = array_shift($itemLines) ?? '';
                    $lines[] = $indent.$marker.' '.$first;
                    foreach ($itemLines as $extra) {
                        $lines[] = $indent.'   '.$extra;
                    }
                }
                break;
            case 'codeBlock':
                $lines[] = '```';
                foreach (explode("\n", $this->rawText($children)) as $cl) {
                    $lines[] = $cl;
                }
                $lines[] = '```';
                break;
            case 'horizontalRule':
                $lines[] = '---';
                break;
            case 'image':
                $lines[] = '!['.((string) ($node['attrs']['alt'] ?? '')).']('.((string) ($node['attrs']['src'] ?? '')).')';
                break;
            case 'spoiler':
                $lines[] = '[spoiler: '.((string) ($node['attrs']['summary'] ?? 'Spoiler')).']';
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $this->blockToLines($child, $lines, $depth + 1);
                    }
                }
                break;
            default:
                // Tables and unknown blocks: recurse so nested text still participates in the diff.
                foreach ($children as $child) {
                    if (is_array($child)) {
                        $this->blockToLines($child, $lines, $depth);
                    }
                }
        }
    }

    /**
     * Inline content of a block → text WITH formatting markers, so a formatting-only edit (bold/link/image)
     * is VISIBLE in the diff (the whole point of amendment #3).
     *
     * @param  array<int,mixed>  $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            $type = $n['type'] ?? '';
            if ($type === 'text') {
                $out .= $this->applyMarks((string) ($n['text'] ?? ''), is_array($n['marks'] ?? null) ? $n['marks'] : []);
            } elseif ($type === 'mention') {
                $out .= '@'.((string) ($n['attrs']['label'] ?? $n['attrs']['id'] ?? ''));
            } elseif ($type === 'hardBreak') {
                $out .= ' ';
            } elseif ($type === 'image') {
                $out .= '!['.((string) ($n['attrs']['alt'] ?? '')).']('.((string) ($n['attrs']['src'] ?? '')).')';
            } else {
                $out .= $this->inline(is_array($n['content'] ?? null) ? $n['content'] : []);
            }
        }

        return $out;
    }

    /**
     * Wrap text in markdown-like markers per mark so bold/italic/link/etc. changes show up in the diff.
     *
     * @param  array<int,mixed>  $marks
     */
    private function applyMarks(string $text, array $marks): string
    {
        foreach ($marks as $mark) {
            if (! is_array($mark)) {
                continue;
            }
            $text = match ($mark['type'] ?? '') {
                'bold' => "**{$text}**",
                'italic' => "*{$text}*",
                'strike' => "~~{$text}~~",
                'underline' => "_{$text}_",
                'code' => "`{$text}`",
                'link' => '['.$text.']('.((string) ($mark['attrs']['href'] ?? '')).')',
                default => $text,
            };
        }

        return $text;
    }

    /** @param array<int,mixed> $nodes */
    private function rawText(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $n) {
            if (! is_array($n)) {
                continue;
            }
            if (($n['type'] ?? '') === 'text') {
                $out .= (string) ($n['text'] ?? '');
            } elseif (is_array($n['content'] ?? null)) {
                $out .= $this->rawText($n['content']);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function toLines(string $text): array
    {
        $lines = preg_split("/\r\n|\r|\n/", $text);

        return $lines === false ? [''] : $lines;
    }

    /**
     * A minimal LCS line diff → ordered same/add/del lines. O(n·m); content line-counts are bounded, so this
     * is fine for post revisions and needs no external diff library.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<array{type:string, text:string}>
     */
    private function lcsDiff(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);

        // dp[i][j] = LCS length of a[i..] and b[j..].
        $dp = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $dp[$i][$j] = $a[$i] === $b[$j]
                    ? $dp[$i + 1][$j + 1] + 1
                    : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
            }
        }

        $out = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $out[] = ['type' => 'same', 'text' => $a[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $out[] = ['type' => 'del', 'text' => $a[$i]];
                $i++;
            } else {
                $out[] = ['type' => 'add', 'text' => $b[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $out[] = ['type' => 'del', 'text' => $a[$i++]];
        }
        while ($j < $m) {
            $out[] = ['type' => 'add', 'text' => $b[$j++]];
        }

        return $out;
    }
}
