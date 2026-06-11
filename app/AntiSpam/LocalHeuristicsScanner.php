<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

/**
 * Local, dependency-free content heuristics (ADR-0007 §2.4) — the baseline ContentScanner. Conservative by
 * design: it scores configured spam phrases and an excessive link count, and only flags above a threshold,
 * so ordinary posts are never held. A flagged scan routes the post to the moderation queue, never a hard
 * block (flag-don't-block).
 */
final class LocalHeuristicsScanner implements ContentScanner
{
    public function scan(string $text): ScanResult
    {
        $score = 0;
        $reasons = [];
        $lower = mb_strtolower($text);

        foreach ((array) config('novfora.antispam.content.suspicious_phrases', []) as $phrase) {
            $phrase = (string) $phrase;
            if ($phrase !== '' && str_contains($lower, mb_strtolower($phrase))) {
                $score += 2;
                $reasons[] = 'phrase';
            }
        }

        $links = (int) preg_match_all('~https?://~i', $text);
        if ($links >= (int) config('novfora.antispam.content.max_links', 3)) {
            $score += 2;
            $reasons[] = 'many_links';
        }

        $threshold = (int) config('novfora.antispam.content.suspicious_score', 2);

        return new ScanResult($score >= $threshold, $score, $reasons);
    }
}
