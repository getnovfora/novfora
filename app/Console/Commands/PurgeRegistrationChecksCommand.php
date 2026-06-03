<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BlocklistEntry;
use App\Models\RegistrationCheck;
use Illuminate\Console\Command;

/**
 * `php artisan hearth:antispam:purge` — privacy/GDPR retention (ADR-0007 §2.6). Deletes registration_checks
 * (which carry IP/email PII) past the configured retention window, and prunes expired blocklist-cache rows.
 * Scheduled daily (cron, ADR-0011); idempotent.
 */
class PurgeRegistrationChecksCommand extends Command
{
    protected $signature = 'hearth:antispam:purge';

    protected $description = 'Purge registration checks past the retention window (GDPR) and prune expired blocklist cache.';

    public function handle(): int
    {
        $days = (int) config('hearth.antispam.retention.registration_checks_days', 90);

        $checks = RegistrationCheck::where('created_at', '<', now()->subDays($days))->delete();
        $blocklist = BlocklistEntry::whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        $this->info("Purged {$checks} registration check(s) older than {$days}d; pruned {$blocklist} expired blocklist row(s).");

        return self::SUCCESS;
    }
}
