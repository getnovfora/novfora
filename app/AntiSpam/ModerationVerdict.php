<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

use App\AntiSpam\Intelligence\SpamScore;

/** The tri-state outcome of post-time moderation (ADR-0007 §2.4): allow / hold (→ queue) / reject. */
final class ModerationVerdict
{
    public const ALLOW = 'allow';

    public const HOLD = 'hold';

    public const REJECT = 'reject';

    /**
     * @param  list<string>  $reasons
     * @param  ?SpamScore  $spam  the advanced-intelligence assessment (Phase 4 · M6.1), when one was computed
     */
    public function __construct(
        public readonly string $action,
        public readonly array $reasons = [],
        public readonly ?SpamScore $spam = null,
    ) {}

    public function held(): bool
    {
        return $this->action === self::HOLD;
    }

    public function rejected(): bool
    {
        return $this->action === self::REJECT;
    }
}
