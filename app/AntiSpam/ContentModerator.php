<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\AntiSpam\Intelligence\SpamScorer;
use App\Models\User;
use App\Modules\Facades\Hook;

/**
 * Post-time moderation orchestrator (ADR-0007 §2.4): combines word filters, content scanning, the new-user
 * queue, and the advanced spam intelligence (Phase 4 · M6.1) into one tri-state verdict (allow / hold /
 * reject). Reject beats hold beats allow. The scanner is resolved through the {@see ContentScanner} contract,
 * so Akismet can replace the local heuristics with no change here. The spam intelligence may only ever HOLD —
 * it can never reject/delete.
 *
 * MODULE SEAM (API 1.2): the final verdict passes through the `moderation.verdict` filter
 * (value: ModerationVerdict; args: $author, $text) — ESCALATE-ONLY. A module may raise the outcome
 * (allow → hold → reject; an external scorer, or a plugin routing its own authors to the queue) but a
 * filtered verdict that lowers or merely equals the core severity is discarded, so a module can never
 * un-hold spam or widen what core moderation decided to restrict (the HookRegistry non-widening rule).
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

        $verdict = new ModerationVerdict($action, $reasons, $spam);

        // Module seam (escalate-only — see class docblock). A non-verdict return is ignored outright.
        $filtered = Hook::applyFilters('moderation.verdict', $verdict, $author, $text);
        if ($filtered instanceof ModerationVerdict && $filtered !== $verdict
            && ($rank[$filtered->action] ?? -1) > $rank[$verdict->action]) {
            return new ModerationVerdict($filtered->action, array_values(array_unique(array_merge($verdict->reasons, $filtered->reasons))), $spam);
        }

        return $verdict;
    }
}
