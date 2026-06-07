<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\HealthController;
use App\Install\PublicStorageLinker;
use App\Upgrade\UpgradeRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The single cron line drives everything (ADR-0011)
|--------------------------------------------------------------------------
| On the baseline tier, ONE cron entry — `* * * * * php artisan schedule:run` — runs everything below.
| No daemon, no persistent worker. Every job is idempotent and correct within one (possibly coarse)
| cron interval. On the enhanced tier the same schedule runs, and a real queue worker can supersede the
| bounded drain with no code change (progressive enhancement).
*/

// Drain the database queue in bounded, overlap-locked batches — queued email, search indexing, and
// notifications. `--stop-when-empty` + `--max-time` keep each run short on a coarse interval; on the
// enhanced tier this same command drains Redis. (ADR-0011 / ADR-0014.)
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping();

// Liveness heartbeat for GET /health: records that the scheduler fired. A stale value means the cron
// line has stopped — the single most common silent failure on a shared host.
Schedule::call(fn () => Cache::put(HealthController::QUEUE_HEARTBEAT, now()->timestamp, now()->addDay()))
    ->everyMinute()
    ->name('hearth-queue-heartbeat');

// On hosts without symlinks, public/storage is a COPIED mirror of uploaded avatars/covers (the installer's
// copy fallback). Refresh it each tick so new uploads appear; a fast no-op where a real symlink is in place.
Schedule::call(fn () => app(PublicStorageLinker::class)->refresh())
    ->everyMinute()
    ->name('hearth-storage-mirror');

// No-SSH automatic upgrade (RH-10 / ADR-0021): when deployed code has pending migrations, apply them behind
// a backup-first maintenance window — so extracting a new release over a live install "just migrates", no
// SSH. Always registered: it refreshes the cached schema-state flag that GET /health and the maintenance
// gate read (cheap; a no-op when nothing's pending), and only RUNS the upgrade when HEARTH_AUTO_UPGRADE is
// on. withoutOverlapping + the runner's own cache lock mean it can never double-run on a coarse, overlapping
// cron; a run killed mid-migration is resumed idempotently on the next tick (migrations are per-migration
// transactional, already-applied ones skipped). Separate from the heartbeat above, which keeps firing.
//
// The overlap mutex gets a SHORT, bounded expiry (just over the runner's lock window) — NOT Laravel's 24h
// default: a process hard-killed mid-run (SIGKILL / OOM / fatal) releases no signal handler, so a 24h mutex
// would strand the auto-upgrade — and the maintenance gate — for up to a day. The runner's own cache lock is
// the real double-run guard, so a ~lock-window expiry is enough to let the next tick resume.
$upgradeMutexMinutes = max(2, (int) ceil(((int) config('hearth.upgrade.lock_seconds', 600)) / 60) + 2);
Schedule::call(fn () => app(UpgradeRunner::class)->runAutomatic())
    ->everyMinute()
    ->name('hearth-auto-upgrade')
    ->withoutOverlapping($upgradeMutexMinutes);

// Anti-spam trust automation (ADR-0007 §2.3): auto promotion/demotion. Idempotent + overlap-guarded so a
// long run on a large board never doubles up on a coarse interval.
Schedule::command('hearth:trust:recompute')->hourly()->withoutOverlapping();

// Privacy/GDPR retention (ADR-0007 §2.6): purge aged registration checks + expired blocklist cache.
Schedule::command('hearth:antispam:purge')->daily();

// Keep the crowdsourced blocklist warm (phase-1.5 F-C) so the registration screener has an offline signal
// when the live API is down — never cold. Degrades to a no-op on any network failure.
Schedule::command('hearth:antispam:warm')->daily();

// Automated backups (M5): DB + storage + manifest, pruned to the retention count. Honours the configured
// cadence (daily | weekly | off — config hearth.backup.schedule).
if (($backupCadence = (string) config('hearth.backup.schedule', 'daily')) !== 'off') {
    $backup = Schedule::command('hearth:backup')->withoutOverlapping();
    $backupCadence === 'weekly' ? $backup->weekly() : $backup->daily();
}
