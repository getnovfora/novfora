<?php

// SPDX-License-Identifier: Apache-2.0

use App\Http\Controllers\HealthController;
use App\Install\PublicStorageLinker;
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

// Anti-spam trust automation (ADR-0007 §2.3): auto promotion/demotion. Idempotent + overlap-guarded so a
// long run on a large board never doubles up on a coarse interval.
Schedule::command('hearth:trust:recompute')->hourly()->withoutOverlapping();

// Privacy/GDPR retention (ADR-0007 §2.6): purge aged registration checks + expired blocklist cache.
Schedule::command('hearth:antispam:purge')->daily();

// Automated backups (M5): DB + storage + manifest, pruned to the retention count. Honours the configured
// cadence (daily | weekly | off — config hearth.backup.schedule).
if (($backupCadence = (string) config('hearth.backup.schedule', 'daily')) !== 'off') {
    $backup = Schedule::command('hearth:backup')->withoutOverlapping();
    $backupCadence === 'weekly' ? $backup->weekly() : $backup->daily();
}
