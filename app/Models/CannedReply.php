<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A canned / stock moderator reply (T1) — a titled, reusable canonical-JSON body. Stock replies are plain text
 * (one paragraph per line); a moderator picks one to pre-fill the composer, then may format it further. The
 * body is canonical JSON, rendered + sanitised through the same post pipeline (no new sanitise path).
 */
class CannedReply extends Model
{
    protected $guarded = [];

    protected $casts = [
        'body_canonical' => 'array',
        'is_active' => 'boolean',
    ];

    /** Build a canonical TipTap doc from plain text — one paragraph per non-empty line. */
    public static function textToDoc(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($text)) ?: [];
        $paragraphs = [];
        foreach ($lines as $line) {
            $line = trim($line);
            $paragraphs[] = $line === ''
                ? ['type' => 'paragraph', 'content' => []]
                : ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $line]]];
        }

        return ['type' => 'doc', 'content' => $paragraphs ?: [['type' => 'paragraph', 'content' => []]]];
    }

    /** The plain-text source of a canonical doc — paragraph text nodes joined by newlines (for the edit form). */
    public static function docToText(array $doc): string
    {
        $lines = [];
        foreach (($doc['content'] ?? []) as $node) {
            $text = '';
            foreach (($node['content'] ?? []) as $child) {
                if (($child['type'] ?? null) === 'text') {
                    $text .= (string) ($child['text'] ?? '');
                }
            }
            $lines[] = $text;
        }

        return trim(implode("\n", $lines));
    }
}
