<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Deliverability\Digest;

use App\Models\DigestPreference;
use Illuminate\Support\Carbon;

/**
 * Spike P2 — the deterministic digest period bucket. The key is derived by FLOORING `now()` to the cadence
 * boundary, never from a per-item timestamp or a per-tick wall-clock, so every coarse / overlapping cron
 * tick within the same period computes the IDENTICAL key. That, plus the UNIQUE(user_id,cadence,period_key)
 * row, is what makes assembly exactly-once: a second tick in the same period collides on the unique index
 * and skips. char(10)-safe for both shapes (daily `2026-06-08` = 10, weekly `2026-W24` = 8).
 */
final class PeriodKey
{
    /** The bucket string for a cadence at a given moment (defaults to now). */
    public static function for(string $cadence, ?Carbon $at = null): string
    {
        $at ??= Carbon::now();

        return match ($cadence) {
            DigestPreference::WEEKLY => $at->format('o-\WW'), // ISO year-week, e.g. 2026-W24
            default => $at->format('Y-m-d'),                  // daily bucket, e.g. 2026-06-08
        };
    }
}
