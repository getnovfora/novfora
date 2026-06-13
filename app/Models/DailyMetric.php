<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One aggregate analytics figure for a day (ADR-0035) — `(metric_date, metric_key) → value`, UNIQUE. Aggregates
 * only; no PII. Written by App\Analytics\AnalyticsService.
 *
 * `metric_date` is kept as a plain `Y-m-d` STRING (not a date cast) so it stores + compares identically to
 * `toDateString()` across drivers — the cast was reformatting it to a datetime, which broke exact-date lookups
 * and the idempotency match.
 *
 * @property string $metric_date
 * @property string $metric_key
 * @property int $value
 */
class DailyMetric extends Model
{
    protected $guarded = [];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return [
            'value' => 'integer',
        ];
    }
}
