<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Admin;

use App\Backup\BackupService;
use App\Backup\RestoreState;
use App\Http\Controllers\HealthController;
use App\Upgrade\SchemaState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only visibility into the cron machinery (ACP v1, PART 4 — the MyBB-style "Task Manager" view).
 * Mirrors routes/console.php (the single cron line, ADR-0011): name + cadence, plus a last-run timestamp
 * where one is knowable from the existing heartbeats / state files (the cron itself records no run log on
 * the baseline tier, so the rest honestly show "—"). Kept in step with console.php by hand — it's a small,
 * stable list, and a web request does not load the console schedule to introspect.
 */
final class ScheduledTasks
{
    public function __construct(
        private readonly SchemaState $schema,
        private readonly RestoreState $restore,
        private readonly BackupService $backups,
    ) {}

    /**
     * @return list<array{name:string,detail:string,cadence:string,last:?int}>
     */
    public function list(): array
    {
        $heartbeat = $this->cacheEpoch(HealthController::QUEUE_HEARTBEAT);
        $backupCadence = (string) config('hearth.backup.schedule', 'daily');

        return [
            ['name' => 'Queue drain', 'detail' => 'Drains the database queue (emails, search indexing)', 'cadence' => 'Every minute', 'last' => $heartbeat],
            ['name' => 'Liveness heartbeat', 'detail' => 'Records the cron is alive (powers /health)', 'cadence' => 'Every minute', 'last' => $heartbeat],
            ['name' => 'Public storage mirror', 'detail' => 'Refreshes the public file copy on symlink-less hosts', 'cadence' => 'Every minute', 'last' => null],
            ['name' => 'Auto-upgrade check', 'detail' => 'Applies pending migrations after a deploy (RH-10)', 'cadence' => 'Every minute', 'last' => $this->stateEpoch($this->schema->lastRun())],
            ['name' => 'Panel restore', 'detail' => 'Runs a requested no-SSH restore (RH-11)', 'cadence' => 'Every minute', 'last' => $this->stateEpoch($this->restore->lastRun())],
            ['name' => 'Digest assembler', 'detail' => $this->deliverabilityDetail('Coalesces pending notifications into one digest email'), 'cadence' => 'Every minute', 'last' => null],
            ['name' => 'Bounce poll', 'detail' => $this->deliverabilityDetail('Polls the bounce mailbox; suppresses hard bounces / complaints'), 'cadence' => 'Every minute', 'last' => null],
            ['name' => 'Trust recompute', 'detail' => 'Promotes/demotes trust levels', 'cadence' => 'Hourly', 'last' => null],
            ['name' => 'Anti-spam purge', 'detail' => 'GDPR retention purge of registration checks', 'cadence' => 'Daily', 'last' => null],
            ['name' => 'Blocklist warm', 'detail' => 'Refreshes the crowdsourced spam blocklist', 'cadence' => 'Daily', 'last' => null],
            ['name' => 'Automated backups', 'detail' => 'Database + uploads → a portable archive', 'cadence' => $backupCadence === 'off' ? 'Off' : ucfirst($backupCadence), 'last' => $this->newestBackupEpoch()],
        ];
    }

    /** Append a "(dormant)" hint to a deliverability task's detail while the pipeline is off. */
    private function deliverabilityDetail(string $detail): string
    {
        return (bool) config('hearth.deliverability.enabled') ? $detail : $detail.' — dormant';
    }

    private function cacheEpoch(string $key): ?int
    {
        try {
            $v = Cache::get($key);

            return is_numeric($v) ? (int) $v : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param  array<string,mixed>|null  $lastRun */
    private function stateEpoch(?array $lastRun): ?int
    {
        $at = $lastRun['at'] ?? null;
        if (! is_string($at) || $at === '') {
            return null;
        }

        try {
            return Carbon::parse($at)->timestamp;
        } catch (\Throwable) {
            return null;
        }
    }

    private function newestBackupEpoch(): ?int
    {
        try {
            $items = $this->backups->list();
            if ($items === []) {
                return null;
            }

            return max(array_map(static fn (array $i): int => (int) $i['created'], $items));
        } catch (\Throwable) {
            return null;
        }
    }
}
