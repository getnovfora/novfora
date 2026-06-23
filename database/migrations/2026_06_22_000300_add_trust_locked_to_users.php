<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.x F2 (manual trust + reputation editing, ADR-0101). One additive, reversible column:
 *
 *   • `trust_locked` — set when an admin (members.trust.manage) MANUALLY sets a member's trust level. It marks
 *     the level as a sticky admin override so the cron trust recompute (TrustLevelManager::evaluate) never
 *     STRUCTURALLY demotes the member below it — the auto-engine may still promote ABOVE it when earned, and a
 *     serious live-infraction total still HARD-demotes to TL0 (the safety lever is preserved). Display/engine
 *     flag only; it does not itself feed acl_entries (the trust GROUP membership does), so no AclVersion bump.
 *
 * Existing rows default to false → byte-identical auto-trust behaviour until an admin sets a level. down()
 * drops the column (clean rollback, G3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'trust_locked')) {
                $table->boolean('trust_locked')->default(false)->after('trust_level');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'trust_locked')) {
                $table->dropColumn('trust_locked');
            }
        });
    }
};
