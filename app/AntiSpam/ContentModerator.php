<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\Models\User;

/**
 * Post-time moderation orchestrator (ADR-0007 §2.4): combines word filters, content scanning, and the
 * new-user queue into one tri-state verdict (allow / hold / reject). Reject beats hold beats allow. The
 * scanner is resolved through the {@see ContentScanner} contract, so Akismet can replace the local
 * heuristics in Phase 2 with no change here.
 */
final class ContentModerator
{
    public function __construct(
        private readonly ContentScanner $scanner,
        private readonly WordFilterService $words,
        private readonly NewUserModeration $newUser,
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

        return new ModerationVerdict($action, $reasons);
    }
}
