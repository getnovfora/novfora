<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * The deployment tier a capability is currently running on (ADR-0003).
 * Baseline = shared PHP host (no daemons). Enhanced = Docker/VPS with extra services.
 */
enum Tier: string
{
    case Baseline = 'baseline';
    case Enhanced = 'enhanced';

    public function label(): string
    {
        return $this === self::Enhanced ? 'Enhanced' : 'Baseline';
    }
}
