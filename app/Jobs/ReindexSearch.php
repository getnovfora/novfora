<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Artisan;

/**
 * Rebuild the search index from the database (Phase 4 · M4.1). Dispatched from Admin → Settings → Search
 * after pointing the board at a fresh Meilisearch instance. It is a QUEUED job so it is drained by the
 * baseline every-minute `queue:work` tick (cron-tolerant) and never blocks the admin request. On the
 * `database` driver it is a no-op (that engine indexes incrementally on save), so the job self-skips.
 */
class ReindexSearch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Serialise re-imports so two admins can't kick off overlapping rebuilds. */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('search-reindex'))->expireAfter(600)->dontRelease()];
    }

    public function handle(): void
    {
        // Only an external engine needs a bulk import; the database engine is always already consistent.
        if (! in_array(config('scout.driver'), ['meilisearch', 'typesense', 'algolia'], true)) {
            return;
        }

        Artisan::call('scout:import', ['model' => Post::class]);
    }
}
