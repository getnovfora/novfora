<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\WordFilter;

/**
 * Word filters (security §3): an active filter can rewrite matching text ('replace'), hold the post for
 * moderation ('flag'), or reject it ('block'). Matching is whole-word by default, optionally regex.
 * Applied server-side to the rendered display; the canonical source is untouched (consistent with §2.4).
 */
final class WordFilterService
{
    /** The strongest gate action triggered by the active block/flag filters. @return 'block'|'flag'|null */
    public function strongestAction(string $text): ?string
    {
        $rank = ['flag' => 1, 'block' => 2];
        $action = null;

        foreach (WordFilter::where('is_active', true)->whereIn('action', ['flag', 'block'])->get() as $filter) {
            if ($this->matches($filter, $text) && ($action === null || $rank[$filter->action] > $rank[$action])) {
                $action = $filter->action;
            }
        }

        return $action;
    }

    /** Apply every active 'replace' filter to the text. */
    public function applyReplacements(string $text): string
    {
        foreach (WordFilter::where('is_active', true)->where('action', 'replace')->get() as $filter) {
            $replacement = (string) ($filter->replacement ?? '');
            if ($filter->is_regex) {
                $text = (string) @preg_replace('~'.$filter->pattern.'~iu', $replacement, $text);
            } elseif ($filter->whole_word) {
                $text = (string) preg_replace('~\b'.preg_quote($filter->pattern, '~').'\b~iu', $replacement, $text);
            } else {
                $text = str_ireplace($filter->pattern, $replacement, $text);
            }
        }

        return $text;
    }

    private function matches(WordFilter $filter, string $text): bool
    {
        if ($filter->is_regex) {
            return @preg_match('~'.$filter->pattern.'~iu', $text) === 1; // invalid admin regex fails safe (no match)
        }
        if ($filter->whole_word) {
            return preg_match('~\b'.preg_quote($filter->pattern, '~').'\b~iu', $text) === 1;
        }

        return mb_stripos($text, $filter->pattern) !== false;
    }
}
