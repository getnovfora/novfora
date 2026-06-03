<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

/** The tri-state outcome of post-time moderation (ADR-0007 §2.4): allow / hold (→ queue) / reject. */
final class ModerationVerdict
{
    public const ALLOW = 'allow';

    public const HOLD = 'hold';

    public const REJECT = 'reject';

    /** @param list<string> $reasons */
    public function __construct(
        public readonly string $action,
        public readonly array $reasons = [],
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
