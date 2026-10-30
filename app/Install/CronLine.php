<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * The ONE cron line the baseline tier needs (ADR-0011), as shown by the install wizard and `novfora:install`.
 *
 * It is a single absolute command (`<php> <base>/artisan schedule:run`) with no `cd … &&` prefix: Hostinger's
 * cron panel silently never executes a compound `cd X && …` entry (NOV-165, verified on the novfora.com
 * production host), whereas artisan resolves its own base path, so the working directory doesn't matter. The
 * trailing `>> /dev/null 2>&1` is kept — it is harmless on panel hosts and stops a plain crontab from mailing
 * the operator every minute.
 */
final class CronLine
{
    public static function line(?string $php = null, ?string $basePath = null): string
    {
        $php ??= self::phpBinary();
        $artisan = rtrim($basePath ?? base_path(), '/').'/artisan';

        return '* * * * * '.self::arg($php).' '.self::arg($artisan).' schedule:run >> /dev/null 2>&1';
    }

    /**
     * The CLI interpreter for this PHP install. Under the CLI SAPI that is PHP_BINARY itself. Under PHP-FPM,
     * PHP_BINARY is the FPM daemon, so prefer the versioned CLI binary beside it (`php8.3`): on Debian-style hosts
     * the unversioned `php` is an alternatives link that can point at a different PHP version than the site runs.
     * Otherwise Symfony's finder (PHP_BINDIR/php, then PATH); a bare `php` when nothing executable can be found
     * (e.g. open_basedir hides the bin dir).
     */
    public static function phpBinary(string $sapi = PHP_SAPI, string $binDir = PHP_BINDIR): string
    {
        if (! in_array($sapi, ['cli', 'phpdbg'], true)) {
            $versioned = rtrim($binDir, '/').'/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;
            if (@is_executable($versioned) && ! @is_dir($versioned)) {
                return $versioned;
            }
        }

        $found = (new PhpExecutableFinder)->find(false);

        return is_string($found) && $found !== '' ? $found : 'php';
    }

    /**
     * Quote a path only when it needs it, so the common case stays copy-paste readable. A `%` is escaped too:
     * crontab turns a bare `%` into a newline.
     */
    private static function arg(string $value): string
    {
        $arg = preg_match('#^[A-Za-z0-9_./-]+$#', $value) === 1 ? $value : escapeshellarg($value);

        return str_replace('%', '\\%', $arg);
    }
}
