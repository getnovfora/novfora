<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Services\Tier;

/**
 * A reachability probe for one optional enhanced-tier service (Redis, Meilisearch, Reverb, S3…).
 *
 * CONTRACT: probe() MUST NOT throw under any circumstance. A missing, misconfigured, or unreachable
 * service is reported as a ProbeResult, never an exception — this is what makes "detect and degrade
 * gracefully, never error" (ADR-0003) true.
 */
interface ServiceProbe
{
    /** Stable key, e.g. 'redis'. */
    public function key(): string;

    /** Human label, e.g. 'Redis'. */
    public function label(): string;

    /** What enabling this service unlocks (shown in the admin panel). */
    public function unlocks(): string;

    /** Is the app actually configured to use this service (vs. a placeholder env value)? */
    public function configured(): bool;

    /** Probe reachability. MUST catch everything and return a ProbeResult. */
    public function probe(): ProbeResult;
}
