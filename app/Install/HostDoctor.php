<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use App\Backup\BackupService;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * `novfora:doctor` preflight (phase-1.5). Extends {@see RequirementChecker} with the shared-host gotchas a
 * baseline operator actually hits: disabled functions (proc_open/exec/symlink), open_basedir, the
 * session/cache/queue drivers, outbound-mail capability, whether symlink() really works (with the
 * copy-based public-storage fallback when it doesn't), and coarse-cron liveness.
 *
 * Severity: `fail` = a hard blocker (a missing required extension, an unwritable path); `warn` = an
 * advisory the baseline tolerates (proc_open disabled → backups use the pure-PHP path; cron not seen yet).
 * The baseline is hardened so NONE of the commonly-disabled functions is a hard requirement, so a healthy
 * shared host is "green" (no fails) even with several warns.
 */
final class HostDoctor
{
    public function __construct(private readonly RequirementChecker $requirements) {}

    /**
     * @return array{ok:bool, checks:list<array{name:string, status:'pass'|'warn'|'fail', detail:string}>}
     */
    public function run(): array
    {
        $checks = $this->requirements->run()['checks'];

        $checks[] = $this->disabledFunctionsCheck();
        $checks[] = $this->symlinkCheck();
        $checks[] = $this->publicStorageCheck();
        $checks[] = $this->openBasedirCheck();
        $checks[] = $this->worldWritableCheck();
        $checks[] = $this->backupMethodCheck();
        foreach ($this->driverChecks() as $c) {
            $checks[] = $c;
        }
        $checks[] = $this->mailCheck();
        $checks[] = $this->cronCheck();

        $ok = ! collect($checks)->contains(fn ($c) => $c['status'] === 'fail');

        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function disabledFunctionsCheck(): array
    {
        $disabled = $this->disabledFunctions();
        // The baseline path needs NONE of these (backups have a pure-PHP fallback; storage has a copy
        // fallback). We report them so an operator understands which fallback their host will use.
        $relevant = array_values(array_intersect(['proc_open', 'exec', 'shell_exec', 'symlink', 'putenv'], $disabled));

        return [
            'name' => 'Disabled PHP functions',
            'status' => $relevant === [] ? 'pass' : 'warn',
            'detail' => $relevant === []
                ? 'None of the functions NovFora can optionally use are disabled.'
                : 'Disabled: '.implode(', ', $relevant).'. Fine on the baseline — NovFora falls back to '
                    .'pure-PHP backups and a copied public/storage. No baseline feature requires them.',
        ];
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function symlinkCheck(): array
    {
        $works = $this->symlinkWorks();

        return [
            'name' => 'Symlink support (storage:link)',
            'status' => $works ? 'pass' : 'warn',
            'detail' => $works
                ? 'symlink() works — public/storage will be a live symlink.'
                : 'symlink() is unavailable. NovFora will serve public files from a COPY at public/storage '
                    .'(the cron line refreshes it; or run `php artisan novfora:storage:publish`).',
        ];
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function publicStorageCheck(): array
    {
        $link = (string) config('novfora.storage.public_link', public_path('storage'));
        if (is_link($link)) {
            $state = ['warn' => false, 'detail' => 'Published as a symlink (live).'];
        } elseif (is_dir($link)) {
            $state = ['warn' => false, 'detail' => 'Published as a copy mirror (refreshed by the cron line).'];
        } else {
            $state = ['warn' => true, 'detail' => 'Not published yet. Run `php artisan novfora:storage:publish` '
                .'(the installer does this automatically) so avatars and images display.'];
        }

        return [
            'name' => 'Public storage (avatars, covers)',
            'status' => $state['warn'] ? 'warn' : 'pass',
            'detail' => $state['detail'],
        ];
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function openBasedirCheck(): array
    {
        $value = trim((string) ini_get('open_basedir'));

        return [
            'name' => 'open_basedir',
            'status' => $value === '' ? 'pass' : 'warn',
            'detail' => $value === ''
                ? 'Not restricted.'
                : "Restricted to: {$value}. Ensure your storage and backup paths are inside it.",
        ];
    }

    /**
     * Flag group- or world-writable application files/dirs (0777-style perms). cPanel's "Extract" often
     * produces 0777, which (a) makes CloudLinux/suEXEC refuse to run the site (HTTP 500), and (b) is a real
     * security hole on shared hosting — another account on the box could overwrite your code. We sample the
     * code paths that must never carry those bits rather than walking all of vendor/. Advisory ('warn'): a
     * non-suEXEC host tolerates it, but the fix is cheap and worth doing everywhere.
     *
     * @return array{name:string, status:'pass'|'warn'|'fail', detail:string}
     */
    private function worldWritableCheck(): array
    {
        // POSIX permission bits are meaningless on Windows (and NovFora's baseline target is a Linux host).
        if (\DIRECTORY_SEPARATOR === '\\') {
            return [
                'name' => 'File permissions (group/world-writable)',
                'status' => 'pass',
                'detail' => 'Permission bits are not applicable on this OS.',
            ];
        }

        $offenders = $this->laxPermissionOffenders($this->permissionSamplePaths());

        if ($offenders === []) {
            return [
                'name' => 'File permissions (group/world-writable)',
                'status' => 'pass',
                'detail' => 'No group- or world-writable application files detected (suEXEC/CloudLinux safe).',
            ];
        }

        return [
            'name' => 'File permissions (group/world-writable)',
            'status' => 'warn',
            'detail' => 'Group/world-writable paths found: '.implode(', ', \array_slice($offenders, 0, 6)).'. '
                .'CloudLinux/suEXEC hosts return HTTP 500 for these, and on any shared host another account '
                .'could overwrite your code. Fix from the app root: '
                .'`find . -type d -exec chmod 755 {} \\; ; find . -type f -exec chmod 644 {} \\; ; chmod 755 artisan` '
                .'(storage/ and bootstrap/cache/ stay owner-writable).',
        ];
    }

    /**
     * The code paths that must never be group/world-writable. Sampling these catches the common
     * "extracted everything 0777" case without walking the whole tree.
     *
     * @return list<string>
     */
    private function permissionSamplePaths(): array
    {
        return array_values(array_filter([
            base_path(),
            base_path('public'),
            public_path('index.php'),
            base_path('artisan'),
            base_path('bootstrap/app.php'),
            base_path('app'),
            base_path('config'),
            base_path('routes'),
            base_path('vendor/autoload.php'),
        ], 'file_exists'));
    }

    /**
     * Return the given paths that are group- or world-writable, each annotated with its octal mode.
     * Public so it can be unit-tested deterministically against fixtures with known permissions.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function laxPermissionOffenders(array $paths): array
    {
        $offenders = [];

        foreach ($paths as $path) {
            $perms = @fileperms($path);
            // 0022 = group-write (0020) | other-write (0002).
            if ($perms !== false && ($perms & 0022) !== 0) {
                $offenders[] = basename($path).' ('.substr(sprintf('%o', $perms), -4).')';
            }
        }

        return $offenders;
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function backupMethodCheck(): array
    {
        $conn = (string) config('database.default');
        $driver = (string) config("database.connections.{$conn}.driver", $conn);

        $detail = match (true) {
            $driver === 'sqlite' => 'SQLite is backed up by copying the database file (no external tool needed).',
            in_array($driver, ['mysql', 'mariadb'], true) => app(BackupService::class)->canShellOut()
                ? 'MySQL/MariaDB backups will use mysqldump (proc_open is available).'
                : 'MySQL/MariaDB backups will use the pure-PHP dumper (proc_open unavailable) — baseline-safe.',
            $driver === 'pgsql' => 'PostgreSQL backups use pg_dump/psql (an enhanced-tier database; needs proc_open).',
            default => "No specialised dumper for driver [{$driver}].",
        };

        // pgsql with no proc_open is the only combination that can't back up — but pgsql is enhanced-tier.
        $blocked = $driver === 'pgsql' && ! BackupService::processFunctionsAvailable();

        return [
            'name' => 'Database backups',
            'status' => $blocked ? 'warn' : 'pass',
            'detail' => $blocked
                ? 'PostgreSQL needs pg_dump (proc_open), which is disabled here. Use MySQL/MariaDB on a shared host.'
                : $detail,
        ];
    }

    /** @return list<array{name:string, status:'pass'|'warn'|'fail', detail:string}> */
    private function driverChecks(): array
    {
        $session = (string) config('session.driver');
        $cache = (string) config('cache.default');
        $queue = (string) config('queue.default');

        // Baseline-friendly drivers need no daemon (sync = inline jobs). redis is enhanced-tier and only
        // works with the service running.
        $baseline = ['file', 'database', 'array', 'cookie', 'sync'];

        $row = function (string $name, string $value) use ($baseline): array {
            $ok = in_array($value, $baseline, true);

            return [
                'name' => $name,
                'status' => $ok ? 'pass' : 'warn',
                'detail' => $ok
                    ? "Using '{$value}' (baseline-safe)."
                    : "Using '{$value}' — an enhanced-tier driver that needs its service running.",
            ];
        };

        return [
            $row('Session driver', $session),
            $row('Cache driver', $cache),
            $row('Queue driver', $queue),
        ];
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function mailCheck(): array
    {
        $mailer = (string) config('mail.default');

        return match ($mailer) {
            'log' => ['name' => 'Outbound mail', 'status' => 'warn', 'detail' => "Mailer is 'log' — email is written to the log, not sent. Configure SMTP for real delivery."],
            'array' => ['name' => 'Outbound mail', 'status' => 'warn', 'detail' => "Mailer is 'array' (testing only) — no email is sent."],
            default => ['name' => 'Outbound mail', 'status' => 'pass', 'detail' => "Mailer is '{$mailer}'. Verify real delivery with `php artisan novfora:mail:test you@example.com`."],
        };
    }

    /** @return array{name:string, status:'pass'|'warn'|'fail', detail:string} */
    private function cronCheck(): array
    {
        try {
            $last = Cache::get(HealthController::QUEUE_HEARTBEAT);
        } catch (Throwable) {
            $last = null;
        }

        if (! is_numeric($last)) {
            return [
                'name' => 'Cron (schedule:run)',
                'status' => 'warn',
                'detail' => 'No scheduler heartbeat seen yet. Add the one cron line and wait a minute, then re-run. '
                    .'On the baseline tier the cron drives mail, search, trust levels, and backups.',
            ];
        }

        $age = max(0, now()->timestamp - (int) $last);

        return [
            'name' => 'Cron (schedule:run)',
            'status' => $age < 1800 ? 'pass' : 'warn',
            'detail' => $age < 1800
                ? "The scheduler last ran {$age}s ago — cron is working."
                : "The scheduler last ran {$age}s ago (stale). The cron line may have stopped.",
        ];
    }

    /** @return list<string> */
    private function disabledFunctions(): array
    {
        return array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions'))));
    }

    /** Actually attempt a symlink in storage/framework to prove the host allows it (then clean up). */
    private function symlinkWorks(): bool
    {
        if (! \function_exists('symlink') || in_array('symlink', $this->disabledFunctions(), true)) {
            return false;
        }

        $dir = storage_path('framework');
        if (! is_dir($dir)) {
            return false;
        }

        $target = $dir.DIRECTORY_SEPARATOR.'.doctor-symlink-target-'.bin2hex(random_bytes(3));
        $link = $dir.DIRECTORY_SEPARATOR.'.doctor-symlink-'.bin2hex(random_bytes(3));

        $ok = false;
        try {
            @file_put_contents($target, '1');
            $ok = @symlink($target, $link) && is_link($link);
        } catch (Throwable) {
            $ok = false;
        } finally {
            @unlink($link);
            @unlink($target);
        }

        return $ok;
    }
}
