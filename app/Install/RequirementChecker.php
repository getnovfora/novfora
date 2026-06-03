<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Install;

/**
 * The host-compatibility checklist for the web installer (M5, phase-1-plan §7 risk: "no-SSH installer
 * across diverse shared hosts"). Probes the things a baseline shared host must provide — PHP version,
 * required + recommended extensions, and writable paths — and returns structured results the wizard
 * renders as pass / warn / fail.
 *
 * `fail` (a hard requirement) blocks the install; `warn` (a recommendation, e.g. GD for thumbnails)
 * does not. The same checklist runs from the CLI installer so VPS operators get the same gate.
 */
final class RequirementChecker
{
    public const PHP_FLOOR = '8.3.0';

    /** Hard-required PHP extensions (Laravel 13 + Hearth's content/DB/archive paths). */
    public const REQUIRED_EXTENSIONS = [
        'pdo', 'mbstring', 'openssl', 'tokenizer', 'ctype', 'json', 'fileinfo', 'zip',
    ];

    /** Recommended-but-optional extensions. Absence warns, never blocks. */
    public const RECOMMENDED_EXTENSIONS = [
        'gd' => 'Image thumbnails for avatars, covers, and attachments (Imagick also works).',
        'pdo_mysql' => 'The default MySQL/MariaDB driver. Needed unless you use PostgreSQL or SQLite.',
        'intl' => 'Locale-aware formatting and transliteration.',
    ];

    /** Paths the app must be able to write to. */
    public function writablePaths(): array
    {
        return [
            base_path('.env') => 'The environment file the installer writes your settings to.',
            storage_path('framework') => 'Compiled views, file cache, and sessions.',
            storage_path('logs') => 'Application logs.',
            storage_path('app') => 'Uploaded files (avatars, attachments) and backups.',
            base_path('bootstrap/cache') => 'Cached config/routes/packages.',
        ];
    }

    /**
     * Run the full checklist.
     *
     * @return array{ok:bool, checks:list<array{name:string, status:'pass'|'warn'|'fail', detail:string}>}
     */
    public function run(): array
    {
        $checks = [];

        $checks[] = $this->phpVersionCheck();

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $checks[] = [
                'name' => "PHP extension: {$ext}",
                'status' => \extension_loaded($ext) ? 'pass' : 'fail',
                'detail' => \extension_loaded($ext) ? 'Loaded.' : 'Required — ask your host to enable it.',
            ];
        }

        foreach (self::RECOMMENDED_EXTENSIONS as $ext => $why) {
            $checks[] = [
                'name' => "PHP extension: {$ext} (recommended)",
                'status' => \extension_loaded($ext) ? 'pass' : 'warn',
                'detail' => \extension_loaded($ext) ? 'Loaded.' : $why,
            ];
        }

        foreach ($this->writablePaths() as $path => $why) {
            $writable = $this->isWritable($path);
            $checks[] = [
                'name' => 'Writable: '.$this->display($path),
                'status' => $writable ? 'pass' : 'fail',
                'detail' => $writable ? 'Writable.' : $why.' Make it writable (e.g. chmod 775).',
            ];
        }

        $ok = ! collect($checks)->contains(fn ($c) => $c['status'] === 'fail');

        return ['ok' => $ok, 'checks' => $checks];
    }

    /** @return array{name:string, status:'pass'|'fail', detail:string} */
    private function phpVersionCheck(): array
    {
        $ok = version_compare(PHP_VERSION, self::PHP_FLOOR, '>=');

        return [
            'name' => 'PHP version ≥ '.self::PHP_FLOOR,
            'status' => $ok ? 'pass' : 'fail',
            'detail' => 'Running PHP '.PHP_VERSION.($ok ? '.' : ' — upgrade to 8.3+ (8.4 recommended).'),
        ];
    }

    /** A path is "writable" if it exists and is writable, or (for .env) its parent dir is writable. */
    private function isWritable(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }

        $dir = is_dir($path) ? $path : \dirname($path);

        return is_dir($dir) && is_writable($dir);
    }

    private function display(string $path): string
    {
        $base = base_path();

        return str_starts_with($path, $base) ? './'.ltrim(substr($path, \strlen($base)), '/\\') : $path;
    }
}
