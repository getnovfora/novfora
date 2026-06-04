<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BlocklistEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * `php artisan hearth:antispam:warm` — refresh the cron-cached StopForumSpam toxic-domains blocklist
 * (phase-1.5 F-C). Keeps the blocklist warm so the registration screener has a real offline signal when
 * the live API is unreachable, instead of failing open. Baseline-safe: it degrades to a no-op on ANY
 * network failure (never fails the cron run) and bounds the import for shared hosts.
 */
class WarmBlocklistCommand extends Command
{
    protected $signature = 'hearth:antispam:warm';

    protected $description = 'Refresh the cron-cached StopForumSpam toxic-domains blocklist (degrades gracefully).';

    public function handle(): int
    {
        $cfg = (array) config('hearth.antispam.registration.stopforumspam.warm', []);
        if (! ($cfg['enabled'] ?? true)) {
            $this->components->info('Blocklist warming is disabled (hearth.antispam…stopforumspam.warm.enabled).');

            return self::SUCCESS;
        }

        $url = (string) ($cfg['domains_url'] ?? '');
        if ($url === '') {
            return self::SUCCESS;
        }

        $body = $this->fetch($url);
        if ($body === null) {
            $this->components->warn('Could not fetch the blocklist; keeping the existing cache.');

            return self::SUCCESS; // graceful — a warm failure must never break the cron line
        }

        $ttl = now()->addDays((int) ($cfg['ttl_days'] ?? 14))->toDateTimeString(); // upsert bypasses casts
        $max = (int) ($cfg['max_entries'] ?? 20000);

        $count = 0;
        $batch = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) ?: [] as $line) {
            $domain = strtolower(trim((string) $line));
            if ($domain === '' || str_starts_with($domain, '#') || ! str_contains($domain, '.')) {
                continue; // skip blanks, comments, and non-domain lines
            }

            $batch[] = ['type' => 'email_domain', 'value' => $domain, 'source' => 'stopforumspam', 'confidence' => 100, 'expires_at' => $ttl];
            if (count($batch) >= 500) {
                $this->flush($batch);
                $batch = [];
            }
            if (++$count >= $max) {
                break;
            }
        }
        if ($batch !== []) {
            $this->flush($batch);
        }

        $this->components->info("Warmed {$count} toxic domain(s) into the blocklist cache.");

        return self::SUCCESS;
    }

    private function fetch(string $url): ?string
    {
        try {
            $resp = Http::timeout(20)->connectTimeout(5)->get($url);

            return $resp->ok() ? (string) $resp->body() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function flush(array $rows): void
    {
        // ON DUPLICATE KEY UPDATE on the (type, value, source) unique index — one query per batch.
        BlocklistEntry::upsert($rows, ['type', 'value', 'source'], ['confidence', 'expires_at']);
    }
}
