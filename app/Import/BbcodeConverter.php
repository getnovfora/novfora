<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Import;

/**
 * Converts legacy BBCode bodies to NovFora's canonical markdown (ADR-0034). CLEAN-ROOM: this is an independent
 * implementation of the PUBLIC BBCode tag semantics (bold/italic/url/quote/code/…) — it copies no reference
 * forum's parser. phpBB stamps each tag with a per-post `bbcode_uid` (`[b:1a2b3c]…[/b:1a2b3c]`); pass it to
 * strip the markers. Unhandled tags are removed so no raw BBCode leaks into the rendered (and separately
 * sanitised) post.
 */
final class BbcodeConverter
{
    public function toMarkdown(string $text, string $uid = ''): string
    {
        if ($uid !== '') {
            $text = str_replace(':'.$uid, '', $text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $rules = [
            '/\[b\](.*?)\[\/b\]/is' => '**$1**',
            '/\[i\](.*?)\[\/i\]/is' => '*$1*',
            '/\[u\](.*?)\[\/u\]/is' => '$1',
            '/\[url=(.*?)\](.*?)\[\/url\]/is' => '[$2]($1)',
            '/\[url\](.*?)\[\/url\]/is' => '$1',
            '/\[img\](.*?)\[\/img\]/is' => '$1',
            '/\[code\](.*?)\[\/code\]/is' => "\n```\n$1\n```\n",
            '/\[quote[^\]]*\](.*?)\[\/quote\]/is' => '> $1',
            '/\[list[^\]]*\](.*?)\[\/list\]/is' => '$1',
            '/\[\*\]/i' => '- ',
        ];
        foreach ($rules as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        // Drop any remaining BBCode tags (no raw markup escapes to the post body). The negative lookahead
        // `(?!\()` preserves a converted markdown link's text — `[link](url)` — whose `[link]` would otherwise
        // look like a tag; a real BBCode open/close tag is never immediately followed by `(`.
        $text = preg_replace('/\[\/?[a-z0-9*]+(=[^\]]*)?\](?!\()/i', '', $text) ?? $text;

        return trim($text);
    }
}
