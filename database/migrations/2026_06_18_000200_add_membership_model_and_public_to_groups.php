<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ACP v3 · v3-e (group system). Two additive, reversible group columns (ADR-0083):
 *
 *  • `membership_model` — HOW humans join a (custom) group: admin (manual add only, unchanged default),
 *    request (a moderated approval queue), or open (a public Join button). Orthogonal to auto-promotion:
 *    auto-promotion is "can the SYSTEM add you" (driven by a non-empty `auto_promotion` rule tree, evaluated
 *    by cron + criterion events) and can coexist with any membership_model. System + trust groups keep the
 *    `admin` default and are guarded from the join paths regardless (their membership is engine-managed).
 *
 *  • `is_public` — whether the group appears on the public Groups directory (default OFF, privacy-by-default).
 *
 * Existing rows default to `admin` / not-public → byte-identical behaviour. down() drops both columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            if (! Schema::hasColumn('groups', 'membership_model')) {
                // admin | request | open — see App\Models\Group::MEMBERSHIP_*.
                $table->string('membership_model')->default('admin')->after('auto_promotion');
            }
            if (! Schema::hasColumn('groups', 'is_public')) {
                $table->boolean('is_public')->default(false)->after('membership_model');
            }
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table): void {
            if (Schema::hasColumn('groups', 'is_public')) {
                $table->dropColumn('is_public');
            }
            if (Schema::hasColumn('groups', 'membership_model')) {
                $table->dropColumn('membership_model');
            }
        });
    }
};
