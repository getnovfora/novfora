<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Forum\AttachmentService;
use Illuminate\Console\Command;

/**
 * Orphan-attachment hygiene (ADR-0094, apex lifecycle). A draft attachment uploaded into the composer but
 * never published stays `post_id = NULL` (uploader-only); this hard-deletes those that are older than the
 * configured window (file + row), so abandoned drafts don't accumulate on disk. Published attachments carry
 * a post_id and are never touched. Scheduled hourly, `withoutOverlapping` + restore-skipped, so it is
 * baseline-safe on a cron-only host with no worker (the cron-only discipline — ADR-0011).
 */
class PruneAttachmentsCommand extends Command
{
    protected $signature = 'novfora:attachments:prune';

    protected $description = 'Hard-delete orphaned (never-published) draft attachments older than the configured window.';

    public function handle(AttachmentService $service): int
    {
        $hours = (int) config('novfora.attachments.orphan_prune_hours', 24);
        $count = $service->pruneOrphans($hours);

        $this->info("Pruned {$count} orphaned ".($count === 1 ? 'attachment' : 'attachments').'.');

        return self::SUCCESS;
    }
}
