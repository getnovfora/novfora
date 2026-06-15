<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam\Intelligence;

/**
 * The result of scoring a post for spam (Phase 4 · M6.1). A pure value object: the total score, the per-signal
 * point breakdown (for the M6.2 review surface), whether it crosses the HOLD threshold, and the reason tags.
 * It NEVER carries a "reject"/"delete" outcome — advanced intelligence may only HOLD for the moderation queue.
 */
final class SpamScore
{
    /**
     * @param  array<string,int>  $signals  signal name => points contributed
     * @param  list<string>  $reasons  reason tags (e.g. ['similarity', 'burst'])
     */
    public function __construct(
        public readonly int $score,
        public readonly array $signals,
        public readonly bool $held,
        public readonly array $reasons = [],
    ) {}

    public static function clear(): self
    {
        return new self(0, [], false, []);
    }
}
