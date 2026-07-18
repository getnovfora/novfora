<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Theme\Sandbox\TemplateContract;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * U11 (NOV-109 / ADR-0112): three-way-merge tracking for sandbox template overrides. `base_source` snapshots
 * the shipped default the admin's `source` was derived from (stamped on every explicit save); when a release
 * changes a default, TemplateSync diff3-merges (base, ours, theirs) — `merge_state` records the outcome and
 * a conflict NEVER touches `source` (the old override keeps rendering; the ACP surfaces the review).
 *
 * Backfill: pre-U11 rows can't know their true base (it was never recorded) — they're stamped with the
 * CURRENT default, so the FIRST future default change merges against an approximate base. Documented in the
 * ADR as the honest cold-start; every explicit save from now on records the exact base. Additive+reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_templates', function (Blueprint $table): void {
            $table->text('base_source')->nullable()->after('source');
            $table->string('merge_state', 12)->default('current')->after('is_enabled');
            $table->timestamp('merged_at')->nullable()->after('merge_state');
        });

        // Backfill existing overrides with the current shipped default as their base.
        foreach (DB::table('site_templates')->whereNull('base_source')->pluck('template_key', 'id') as $id => $key) {
            DB::table('site_templates')->where('id', $id)->update([
                'base_source' => TemplateContract::default((string) $key),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('site_templates', function (Blueprint $table): void {
            $table->dropColumn(['base_source', 'merge_state', 'merged_at']);
        });
    }
};
