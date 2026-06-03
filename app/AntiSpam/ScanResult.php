<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\AntiSpam;

/** The outcome of a content scan (ADR-0007 §2.4). */
final class ScanResult
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $suspicious,
        public readonly int $score = 0,
        public readonly array $reasons = [],
    ) {}
}
