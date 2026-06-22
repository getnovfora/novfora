<?php

// SPDX-License-Identifier: Apache-2.0

use App\Backup\RestoreRunner;
use App\Backup\RestoreState;
use App\Http\Controllers\HealthController;
use App\Install\PublicStorageLinker;
use App\Upgrade\UpgradeRunner;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

// While a no-SSH restore is requested/running/stuck, the DB is being (or was) overwritten — and on the
// baseline tier the cache/session/queue tables live in it. So stand the DB-touching scheduled work DOWN for
// the restore window (RH-11): a `->skip()` predicate that mirrors the HTTP maintenance gate. This is the
// scheduler-side analogue of PreventRequestsDuringUpgrade — the auto-upgrade tick already self-guards, and
// the restore drain itself + the cache-only heartbeat are intentionally NOT skipped.
$duringRestore = fn () => app(RestoreState::class)->shouldGateRequests();

// Drain the database queue in bounded, overlap-locked batches — queued email, search indexing, and
// notifications. `--stop-when-empty` + `--max-time` keep each run short on a coarse interval; on the
// enhanced tier this same command drains Redis. (ADR-0011 / ADR-0014.) Skipped during a restore so a worker
// never reserves/executes a job against the `jobs` table the restore is replacing.
Schedule::command('queue:work --stop-when-empty --tries=3 --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->skip($duringRestore);

// Liveness heartbeat for GET /health: records that the scheduler fired. A stale value means the cron
// line has stopped — the single most common silent failure on a shared host. (Cache-only; not DB schema —
// left running so liveness keeps ticking; harmless if the DB-backed cache is mid-restore.)
Schedule::call(fn () => Cache::put(HealthController::QUEUE_HEARTBEAT, now()->timestamp, now()->addDay()))
    ->everyMinute()
    ->name('novfora-queue-heartbeat');

// On hosts without symlinks, public/storage is a COPIED mirror of uploaded avatars/covers (the installer's
// copy fallback). Refresh it each tick so new uploads appear; a fast no-op where a real symlink is in place.
// Skipped during a restore — storage/app is being overwritten too.
Schedule::call(fn () => app(PublicStorageLinker::class)->refresh())
    ->everyMinute()
    ->name('novfora-storage-mirror')
    ->skip($duringRestore);

// No-SSH automatic upgrade (RH-10 / ADR-0021): when deployed code has pending migrations, apply them behind
// a backup-first maintenance window — so extracting a new release over a live install "just migrates", no
// SSH. Always registered: it refreshes the cached schema-state flag that GET /health and the maintenance
// gate read (cheap; a no-op when nothing's pending), and only RUNS the upgrade when NOVFORA_AUTO_UPGRADE is
// on. withoutOverlapping + the runner's own cache lock mean it can never double-run on a coarse, overlapping
// cron; a run killed mid-migration is resumed idempotently on the next tick (migrations are per-migration
// transactional, already-applied ones skipped). Separate from the heartbeat above, which keeps firing.
//
// The overlap mutex gets a SHORT, bounded expiry (just over the runner's lock window) — NOT Laravel's 24h
// default: a process hard-killed mid-run (SIGKILL / OOM / fatal) releases no signal handler, so a 24h mutex
// would strand the auto-upgrade — and the maintenance gate — for up to a day. The runner's own cache lock is
// the real double-run guard, so a ~lock-window expiry is enough to let the next tick resume.
$upgradeMutexMinutes = max(2, (int) ceil(((int) config('novfora.upgrade.lock_seconds', 600)) / 60) + 2);
Schedule::call(fn () => app(UpgradeRunner::class)->runAutomatic())
    ->everyMinute()
    ->name('novfora-auto-upgrade')
    ->withoutOverlapping($upgradeMutexMinutes);

// No-SSH panel restore (RH-11 / ADR-0022): the Admin → System → Backups "Restore" action records a request
// in a FILE (App\Backup\RestoreState — not the cache/DB, which the restore overwrites), and this tick drains
// it — taking a pre-restore safety snapshot, restoring DB + storage, then refreshing the schema state so the
// RH-10 auto-upgrade can apply any now-pending migrations from a restored older schema. A cheap no-op when
// nothing is requested. The runner holds its own FILE lock (a cache lock would be wiped mid-restore); the
// short overlap mutex (like the auto-upgrade's) is the belt. The auto-upgrade task above skips while a
// restore is in progress, so the two never race over the database.
$restoreMutexMinutes = $upgradeMutexMinutes;
Schedule::call(fn () => app(RestoreRunner::class)->runPending())
    ->everyMinute()
    ->name('novfora-panel-restore')
    ->withoutOverlapping($restoreMutexMinutes);

// Anti-spam trust automation (ADR-0007 §2.3): auto promotion/demotion. Idempotent + overlap-guarded so a
// long run on a large board never doubles up on a coarse interval. Skipped during a restore (writes users).
Schedule::command('novfora:trust:recompute')->hourly()->withoutOverlapping()->skip($duringRestore);

// Custom-group AND/OR auto-promotion (ACP v3 · v3-e / ADR-0083): promote users into the custom groups whose
// criteria they now meet. Promotion-only + idempotent (an already-member is skipped), so a coarse/overlapping
// run is safe; it self-skips when no group auto-promotes. The catch-up + the only path that crosses the
// time-based `tenure_days` bar (the post/reputation events promote eagerly). Skipped during a restore (writes
// the group_user pivot).
Schedule::command('novfora:groups:auto-promote')->hourly()->withoutOverlapping()->skip($duringRestore);

// Post scheduling (member tool 2.4): publish replies whose time has passed. Every minute so a scheduled
// time is honoured promptly; a SHORT overlap mutex + PostScheduler's per-row claim make a coarse/overlapping
// run safe (never a double-publish). Skipped during a restore (it writes posts).
Schedule::command('novfora:posts:publish-scheduled')->everyMinute()->withoutOverlapping(5)->skip($duringRestore);

// Membership expiry (Phase 4 · M5.1): expire subscriptions past their expiry and revoke their perks through
// the engine. Hourly is granular enough for membership; overlap-guarded + skipped during a restore (it writes
// users' acl_entries). Baseline-safe — no worker required.
Schedule::command('novfora:tiers:expire')->hourly()->withoutOverlapping()->skip($duringRestore);

// Expired-grant prune (ACP v3 · v3-0 / ADR-0080 §5): hard-delete lapsed TTL acl_entries rows (temporary-access
// delegation, v3-f) and bump AclVersion so caches refresh. The resolver's expiry filter is already
// authoritative, so this is hygiene only — a lagging run never honours an expired grant. Every few minutes so a
// lapsed delegation is cleared promptly; a SHORT overlap mutex (not Laravel's 24h default — RH-10 lesson) so a
// hard-killed tick can't strand it, and skipped during a restore (it writes acl_entries).
Schedule::command('novfora:acl:prune-expired')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->skip($duringRestore);

// Orphan-attachment hygiene (ADR-0094): hard-delete never-published draft uploads past the configured
// window. A SHORT overlap mutex (RH-10) so a hard-killed tick can't strand it; skipped during a restore (it
// deletes files + rows). Hygiene only — a missed run just defers cleanup; nothing is load-bearing.
Schedule::command('novfora:attachments:prune')
    ->hourly()
    ->withoutOverlapping(10)
    ->skip($duringRestore);

// Reputation denorm self-heal (P2-M5 ⚙): reconcile users.reputation_points to the reputation_events
// ledger — belt-and-braces under any missed/reordered queue event. Idempotent + bounded, with a SHORT
// overlap mutex (not Laravel's 24h default) so a hard-killed run can't strand the heal (RH-10 lesson).
// `novfora:` command prefix — the 1.0 brand rename completed rename surface #8 (P5.5/ADR-0073).
Schedule::command('novfora:reputation:recompute')
    ->hourly()
    ->withoutOverlapping(10)
    ->skip($duringRestore);

// Badge catch-up sweep (P2-M5 ⚙): award anything a missed event dropped. Awards are permanent +
// UNIQUE-keyed, so the sweep only ever adds — idempotent by construction. Daily is enough latency for a
// missed badge; same short-mutex discipline. `novfora:` naming as above (P5.5/ADR-0073 brand rename).
Schedule::command('novfora:badges:recompute')
    ->daily()
    ->withoutOverlapping(10)
    ->skip($duringRestore);

// Baseline-tier cache hygiene (P2-M5 adversarial-review finding): version-keyed cache entries (feeds,
// reaction tallies, ACL) are never read again after a version bump, and the DATABASE store only evicts
// an expired row when that exact key is next read — so superseded rows accumulate forever. Prune expired
// rows daily. A no-op on the enhanced tier (Redis evicts itself) and during a restore.
Schedule::call(function (): void {
    if (config('cache.default') === 'database') {
        DB::table((string) config('cache.stores.database.table', 'cache'))
            ->where('expiration', '<', now()->getTimestamp())
            ->delete();
    }
})->daily()->name('novfora-cache-prune')->skip($duringRestore);

// Outbound webhook egress (ADR-0033, B3): drain pending deliveries, signing + POSTing with retry/backoff.
// The cron path makes delivery work on the baseline tier (no persistent worker); overlap-guarded so a coarse
// interval never double-sends, and skipped during a restore (it touches webhook_deliveries).
Schedule::command('webhooks:deliver')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->skip($duringRestore);

// Daily admin analytics rollup (ADR-0035, B5): compute aggregate daily metrics (no PII) on the baseline tier.
// Idempotent (UNIQUE per date+key), so finalising yesterday + refreshing today is safe; skipped during a
// restore (it reads users/topics/posts which a restore is swapping).
Schedule::command('novfora:analytics:rollup')
    ->daily()
    ->withoutOverlapping(10)
    ->skip($duringRestore);

// Privacy/GDPR retention (ADR-0007 §2.6): purge aged registration checks + expired blocklist cache.
Schedule::command('novfora:antispam:purge')->daily()->skip($duringRestore);

// Keep the crowdsourced blocklist warm (phase-1.5 F-C) so the registration screener has an offline signal
// when the live API is down — never cold. Degrades to a no-op on any network failure.
Schedule::command('novfora:antispam:warm')->daily()->skip($duringRestore);

// Automated backups (M5): DB + storage + manifest, pruned to the retention count. Honours the configured
// cadence (daily | weekly | off — config novfora.backup.schedule). Skipped during a restore so it never
// snapshots a half-restored database.
if (($backupCadence = (string) config('novfora.backup.schedule', 'daily')) !== 'off') {
    $backup = Schedule::command('novfora:backup')->withoutOverlapping()->skip($duringRestore);
    $backupCadence === 'weekly' ? $backup->weekly() : $backup->daily();
}

// Deliverability (Spike P2, Phase-2 plan §4) — DORMANT BY DEFAULT. The two ticks below are always wired
// into the single cron line, but each command early-returns until config('novfora.deliverability.enabled')
// (and, for the digest, novfora.deliverability.digest.enabled) — so a deploy changes no behaviour; P2-M2
// flips the flag. Both follow the M5 drain discipline: everyMinute + withoutOverlapping + skip during a
// restore (they write the DB). The digest tick uses a SHORT, bounded overlap mutex — NOT Laravel's 24h
// default — because the real double-run guard is the committed UNIQUE(user,cadence,period) row, not the
// lock; a hard-killed tick (which releases no handler) must not strand the digest for a day (RH-10 lesson).
$digestMutexMinutes = max(2, (int) config('novfora.deliverability.digest.mutex_minutes', 2));
Schedule::command('novfora:deliverability:digest-run')
    ->everyMinute()
    ->withoutOverlapping($digestMutexMinutes)
    ->name('novfora-digest-run')
    ->skip($duringRestore);

// Bounce/complaint poll (the daemon-free IMAP path); no-op when no mailbox is configured / the imap ext is
// absent (degrades to the VERP + manual-ACP floor). Webhook ingestion is push (a route), not scheduled.
Schedule::command('novfora:deliverability:poll-bounces')
    ->everyMinute()
    ->withoutOverlapping()
    ->name('novfora-poll-bounces')
    ->skip($duringRestore);
