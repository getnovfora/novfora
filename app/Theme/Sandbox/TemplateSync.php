<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Theme\Sandbox;

use App\Models\SiteTemplate;
use App\Support\Audit;
use App\Support\Text\Diff3;

/**
 * U11 (NOV-109 / ADR-0112): reconcile stored template overrides against the CURRENT shipped defaults after a
 * core release. For every override whose recorded base differs from the live default, diff3-merge
 * (base = the default the admin edited from, ours = the admin's source, theirs = the new default):
 *
 *  - clean merge → the merged output must RE-PASS the sandbox lint/parse gate (two individually-safe inputs
 *    can interleave into a forbidden token — the ADR-0038 split-token lesson applied to merging); on pass it
 *    becomes the live source with the base advanced, marked `merged` for the admin to review at leisure.
 *  - conflict (or a lint-failing merge, or an over-budget diff) → the admin's EXISTING source keeps serving
 *    untouched (stale but functional — "conflicts are never fatal"), marked `conflict` for the ACP review.
 *    Conflict markers never exist anywhere in this pipeline (Diff3 returns merged = null on conflict).
 *
 * Wired: best-effort from UpgradeRunner (a sync failure never fails an upgrade), the novfora:templates:sync
 * command, and lazily from the ACP templates page. Test seam: pass an explicit defaults map.
 */
final class TemplateSync
{
    public function __construct(private readonly TemplateService $templates) {}

    /**
     * @param  array<string,string>|null  $defaults  override the live contract defaults (tests / rehearsal)
     * @return array<string,string> template_key => unchanged|merged|conflict|stamped|orphan
     */
    public function sync(?array $defaults = null): array
    {
        $report = [];

        foreach (SiteTemplate::query()->get() as $row) {
            $key = (string) $row->template_key;

            // A key no longer in the contract can never render (render() checks the contract first);
            // leave the row for the operator rather than silently deleting their work.
            if (! TemplateContract::has($key)) {
                $report[$key] = 'orphan';

                continue;
            }

            $newDefault = $defaults[$key] ?? TemplateContract::default($key);
            $base = (string) ($row->base_source ?? '');

            if ($base === '') {
                // Pre-U11 row that missed the backfill — record the current default as its base.
                $row->update(['base_source' => $newDefault]);
                $report[$key] = 'stamped';

                continue;
            }

            if ($base === $newDefault) {
                $report[$key] = 'unchanged';

                continue;
            }

            if ((string) $row->source === $base) {
                // The admin enabled the template but never diverged — follow the new default wholesale.
                $row->update(['source' => $newDefault, 'base_source' => $newDefault, 'merge_state' => 'merged', 'merged_at' => now()]);
                $report[$key] = 'merged';

                continue;
            }

            $m = Diff3::merge($base, (string) $row->source, $newDefault);

            if ($m['clean']) {
                $merged = (string) $m['merged'];
                try {
                    // The security gate: merged output re-passes the same lint/parse every admin save passes.
                    $this->templates->lint($merged);
                } catch (SandboxException) {
                    $row->update(['merge_state' => 'conflict']);
                    $report[$key] = 'conflict';

                    continue;
                }

                $row->update(['source' => $merged, 'base_source' => $newDefault, 'merge_state' => 'merged', 'merged_at' => now()]);
                $report[$key] = 'merged';
            } else {
                $row->update(['merge_state' => 'conflict']);
                $report[$key] = 'conflict';
            }
        }

        if ($report !== []) {
            Audit::log('template.sync', null, ['outcomes' => $report]);
        }

        return $report;
    }

    /** ACP conflict resolution: keep the admin's source as-is and accept the drift (base advances). */
    public function resolveKeepMine(string $key): void
    {
        $row = SiteTemplate::query()->where('template_key', $key)->firstOrFail();
        $row->update(['base_source' => TemplateContract::default($key), 'merge_state' => 'current', 'merged_at' => null]);
        Audit::log('template.conflict.kept', $row, ['key' => $key]);
    }

    /** ACP conflict resolution: discard the admin's source and take the new shipped default. */
    public function resolveTakeDefault(string $key): void
    {
        $this->templates->revert($key); // save() stamps base_source + merge_state=current
        Audit::log('template.conflict.reverted', null, ['key' => $key]);
    }

    /** True when any override is flagged for review (the ACP banner + lazy check). */
    public function hasPendingReview(): bool
    {
        try {
            return SiteTemplate::query()->whereIn('merge_state', ['merged', 'conflict'])->exists();
        } catch (\Throwable) {
            return false;
        }
    }
}
