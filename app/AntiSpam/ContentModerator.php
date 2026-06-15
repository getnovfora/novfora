<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\AntiSpam\Intelligence\SpamScorer;
use App\Models\User;

/**
 * Post-time moderation orchestrator (ADR-0007 §2.4): combines word filters, content scanning, the new-user
 * queue, and the advanced spam intelligence (Phase 4 · M6.1) into one tri-state verdict (allow / hold /
 * reject). Reject beats hold beats allow. The scanner is resolved through the {@see ContentScanner} contract,
 * so Akismet can replace the local heuristics with no change here. The spam intelligence may only ever HOLD —
 * it can never reject/delete.
 */
final class ContentModerator
{
    public function __construct(
        private readonly ContentScanner $scanner,
        private readonly WordFilterService $words,
        private readonly NewUserModeration $newUser,
        private readonly SpamScorer $spam,
    ) {}

    public function review(User $author, string $text): ModerationVerdict
    {
        $rank = [ModerationVerdict::ALLOW => 0, ModerationVerdict::HOLD => 1, ModerationVerdict::REJECT => 2];
        $action = ModerationVerdict::ALLOW;
        $reasons = [];

        $escalate = function (string $to) use (&$action, $rank) {
            if ($rank[$to] > $rank[$action]) {
                $action = $to;
            }
        };

        $word = $this->words->strongestAction($text);
        if ($word === 'block') {
            $escalate(ModerationVerdict::REJECT);
            $reasons[] = 'word_filter:block';
        } elseif ($word === 'flag') {
            $escalate(ModerationVerdict::HOLD);
            $reasons[] = 'word_filter:flag';
        }

        $scan = $this->scanner->scan($text);
        if ($scan->suspicious) {
            $escalate(ModerationVerdict::HOLD);
            $reasons = array_merge($reasons, array_map(fn ($r) => 'scan:'.$r, $scan->reasons));
        }

        if ($this->newUser->shouldHold($author)) {
            $escalate(ModerationVerdict::HOLD);
            $reasons[] = 'new_user';
        }

        // Advanced spam intelligence (Phase 4 · M6.1). HOLD-ONLY: a high score can never escalate past HOLD,
        // so this layer can never reject/delete a post — it only routes to the moderation queue.
        $spam = $this->spam->score($author, $text);
        if ($spam->held) {
            $escalate(ModerationVerdict::HOLD);
            $reasons = array_merge($reasons, array_map(fn (string $r) => 'spam:'.$r, $spam->reasons));
        }

        return new ModerationVerdict($action, $reasons, $spam);
    }
}
