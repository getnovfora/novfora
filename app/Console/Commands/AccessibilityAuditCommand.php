<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Console\Commands;

use App\Accessibility\AccessibilityAuditor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Ad-hoc WCAG 2.1 AA audit of a rendered page (URL) or a local HTML file.
 *
 * The same AccessibilityAuditor that backs the Pest gate. Reports the machine-checkable findings only —
 * contrast, focus order and screen-reader experience are out of scope here (see the manual checklist in
 * docs/architecture/accessibility.md). Exit code is non-zero when findings exist, so CI/cron can gate on it.
 */
final class AccessibilityAuditCommand extends Command
{
    protected $signature = 'novfora:a11y:audit {target : a URL (http/https) or a local HTML file path}
                            {--fragment : audit as an HTML fragment (skip document-level page checks)}';

    protected $description = 'Run the deterministic WCAG 2.1 AA audit over a URL or HTML file';

    public function handle(AccessibilityAuditor $auditor): int
    {
        $target = (string) $this->argument('target');

        if (str_starts_with($target, 'http://') || str_starts_with($target, 'https://')) {
            $response = Http::timeout(15)->get($target);
            if ($response->failed()) {
                $this->error("Could not fetch {$target} (HTTP {$response->status()}).");

                return self::FAILURE;
            }
            $html = $response->body();
        } elseif (is_file($target)) {
            $html = (string) file_get_contents($target);
        } else {
            $this->error("Target is neither a reachable URL nor an existing file: {$target}");

            return self::FAILURE;
        }

        $findings = $auditor->audit($html, ! $this->option('fragment'));

        if ($findings === []) {
            $this->info("No machine-detectable WCAG 2.1 AA violations in {$target}.");
            $this->line('Remember: contrast, focus order and screen-reader checks are manual (docs/architecture/accessibility.md).');

            return self::SUCCESS;
        }

        $this->error(count($findings).' accessibility finding(s) in '.$target.':');
        foreach ($findings as $finding) {
            $this->line(' • '.$finding->label());
        }

        return self::FAILURE;
    }
}
