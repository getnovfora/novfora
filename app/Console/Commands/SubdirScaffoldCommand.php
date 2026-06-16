<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Install\SubdirectoryScaffold;
use Illuminate\Console\Command;

/**
 * `php artisan novfora:subdir:scaffold {webdir}` — RH-4.3 (ADR-0070) Option B helper.
 *
 * Generates the web-subdir artifacts for a subdirectory install on a host that forbids a full web-root
 * symlink: a thin index.php stub (boots the app from outside the web root), an .htaccess with the right
 * RewriteBase, and build/ + storage/ links to the app's single canonical trees. Idempotent — re-run after a
 * deploy. Not needed for Option A (a full public/ symlink) or the root/subdomain layout.
 */
class SubdirScaffoldCommand extends Command
{
    protected $signature = 'novfora:subdir:scaffold
        {webdir : Absolute path to the web-visible subdir, e.g. /home/you/public_html/community}
        {--base= : URL subpath (e.g. /community); defaults to the webdir basename}';

    protected $description = 'Generate the Option B subdirectory front controller (stub + .htaccess + build/storage links).';

    public function handle(SubdirectoryScaffold $scaffold): int
    {
        $webDir = (string) $this->argument('webdir');
        $base = $this->option('base');

        $report = $scaffold->scaffold($webDir, is_string($base) && $base !== '' ? $base : null);

        foreach ($report as $artifact => $result) {
            $result === 'failed'
                ? $this->components->error(sprintf('%s: FAILED', $artifact))
                : $this->components->info(sprintf('%s: %s', $artifact, $result));
        }

        if (in_array('failed', $report, true)) {
            $this->components->warn('Some artifacts could not be created — check filesystem permissions and the path.');

            return self::FAILURE;
        }

        $this->components->info('Subdirectory front controller ready. Set APP_URL + ASSET_URL to the full subpath URL.');

        return self::SUCCESS;
    }
}
