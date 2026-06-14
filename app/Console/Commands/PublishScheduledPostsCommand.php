<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Forum\PostScheduler;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:posts:publish-scheduled` — publish replies whose scheduled time has passed (member
 * tool 2.4). Driven every minute by the single baseline cron line; idempotent + overlap-safe via
 * PostScheduler's per-row claim, so a coarse/overlapping schedule never double-publishes.
 */
class PublishScheduledPostsCommand extends Command
{
    protected $signature = 'novfora:posts:publish-scheduled {--limit=200 : Max items to publish per run}';

    protected $description = 'Publish replies scheduled for a past time (cron-tolerant).';

    public function handle(PostScheduler $scheduler): int
    {
        $count = $scheduler->publishDue((int) $this->option('limit'));

        $this->components->info($count === 0
            ? 'No scheduled posts were due.'
            : "Published {$count} scheduled post(s).");

        return self::SUCCESS;
    }
}
