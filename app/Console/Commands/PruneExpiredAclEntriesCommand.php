<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AclEntry;
use App\Permissions\AclVersion;
use Illuminate\Console\Command;

/**
 * ACP v3 · v3-0 (ADR-0080 §5). Hard-delete lapsed TTL grants from `acl_entries` and bump the ACL cache version
 * so resolved-permission caches refresh. This is HYGIENE ONLY: honouring is already authoritative without it —
 * the resolver's read filter drops lapsed rows, and PermissionResolver caps the cached can() to the earliest
 * contributing TTL — so a missed or lagging run never lets an expired grant be honoured on either path; the
 * sweep just stops dead rows accumulating. Scheduled every few minutes, `withoutOverlapping` + restore-skipped,
 * so it is baseline-safe on a cron-only host with no worker.
 */
class PruneExpiredAclEntriesCommand extends Command
{
    protected $signature = 'novfora:acl:prune-expired';

    protected $description = 'Hard-delete expired (lapsed TTL) acl_entries rows and bump the ACL cache version.';

    public function handle(AclVersion $version): int
    {
        // A query-builder delete bypasses the per-row `deleted` model event (which would bump the version once
        // per row). So we delete in one statement and bump ONCE, and only when something actually lapsed.
        $count = AclEntry::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();

        if ($count > 0) {
            $version->bump();
        }

        $this->info("Pruned {$count} expired ACL ".($count === 1 ? 'entry' : 'entries').'.');

        return self::SUCCESS;
    }
}
