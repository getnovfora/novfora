<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-g (staff flair + roster, ADR-0088). Three additive, reversible group columns — ALL DISPLAY-ONLY,
 * none feed permission resolution (no `acl_entries` touch, no AclVersion bump):
 *
 *   • `show_on_staff_page` — this group's active members appear on the public /staff "The Team" roster (default
 *     OFF). SEEDED true on the Administrators + Moderators system groups: this migration sets it for an EXISTING
 *     install (rows already present); a FRESH install gets it from GroupSeeder (which runs after migrations, when
 *     no rows exist yet, so the data-update below is a harmless no-op there).
 *   • `show_staff_icon` — decorate this group's staff flair with an icon (default OFF).
 *   • `staff_title`     — an optional per-group title that overrides the canonical role label in the flair (NULL =
 *     use the canonical label). The only "custom title" surface — there are no per-USER titles (v3-g non-goal).
 *
 * Existing rows default to OFF / NULL → byte-identical behaviour. down() drops all three (clean rollback, G3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('groups', 'show_on_staff_page')) {
                $table->boolean('show_on_staff_page')->default(false)->after('is_public');
            }
            if (! Schema::hasColumn('groups', 'show_staff_icon')) {
                $table->boolean('show_staff_icon')->default(false)->after('show_on_staff_page');
            }
            if (! Schema::hasColumn('groups', 'staff_title')) {
                $table->string('staff_title')->nullable()->after('show_staff_icon');
            }
        });

        // Existing installs: surface the two system staff groups on the Team page. (Fresh installs seed this via
        // GroupSeeder; here the rows already exist, so this is the one-time backfill.)
        DB::table('groups')->whereIn('slug', ['admins', 'moderators'])->update(['show_on_staff_page' => true]);
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            foreach (['staff_title', 'show_staff_icon', 'show_on_staff_page'] as $column) {
                if (Schema::hasColumn('groups', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
