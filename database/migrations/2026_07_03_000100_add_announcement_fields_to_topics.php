<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| U4 (NOV-102) — finishes the half-wired `announcement` topic type (topics.type already accepts it). Two
| additive, nullable columns carry the announcement's runtime behaviour:
|   - announcement_audience  JSON, null = everyone; else {"groups":[ids]} — the criteria-targeting set.
|   - announcement_expires_at  optional auto-expiry; null = shows until retracted.
| Non-announcement topics leave both null. Additive + reversible (down drops both) — no data surgery.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            if (! Schema::hasColumn('topics', 'announcement_audience')) {
                $table->json('announcement_audience')->nullable()->after('type');
            }
            if (! Schema::hasColumn('topics', 'announcement_expires_at')) {
                $table->timestamp('announcement_expires_at')->nullable()->after('announcement_audience');
            }
        });
    }

    public function down(): void
    {
        Schema::table('topics', function (Blueprint $table) {
            foreach (['announcement_audience', 'announcement_expires_at'] as $column) {
                if (Schema::hasColumn('topics', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
