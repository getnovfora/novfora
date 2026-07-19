<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Maintenance\CounterRebuildService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Queued counter self-heal (U16 / NOV-114). Dispatched from the ACP Maintenance page so a full-board recompute
 * never blocks the admin request; drained by the baseline every-minute `queue:work` tick (cron-tolerant).
 * Serialised so two admins can't run overlapping rebuilds.
 */
class RebuildCountersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Give a full-board recompute room to finish in one run (the baseline `queue:work --max-time=50` only stops
     *  the worker BETWEEN jobs, so an in-flight job runs to its own timeout). Kept below the lock TTL below.
     *  (If a board legitimately runs past the DB queue's default 90s `retry_after`, the queue re-marks it visible
     *  and a phantom re-pick occurs; the overlap lock below blocks it from executing concurrently, so it is a
     *  harmless churn — set DB_QUEUE_RETRY_AFTER > 280 to silence it. Every write is an authoritative SET, so even
     *  an interleaved recompute cannot corrupt.) */
    public int $timeout = 280;

    /** 4 attempts × the 120s release spacing below (~360s) outlasts the 300s lock TTL, so even a hard-killed leaked
     *  lock is recovered within the same episode rather than exhausting into failed_jobs. */
    public int $tries = 4;

    /**
     * Serialise rebuilds so two admins (or a retry_after re-pick of a long run) can't recompute concurrently —
     * but RELEASE a blocked job back to the queue rather than dropping it (the old `dontRelease()` silently
     * deleted retries + re-clicks, so a rebuild too big for one drain window could never complete while the UI
     * flashed "queued"). A hard-killed lock lapses within `expireAfter`, after which a released retry runs. The
     * weekly `novfora:forums:recompute-counters` CLI (no timeout) is the unbounded fallback for huge boards.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('rebuild-counters'))->expireAfter(300)->releaseAfter(120)];
    }

    public function handle(CounterRebuildService $service): void
    {
        $service->rebuildAll();
    }
}
