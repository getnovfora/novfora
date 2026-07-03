<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Onboarding-lite (NOV-123) — one nullable timestamp records when a member dismissed the getting-started
| checklist, so it never nags again. null = not dismissed (still eligible to show, until every item is done).
| Additive + reversible.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'onboarding_dismissed_at')) {
                $table->timestamp('onboarding_dismissed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'onboarding_dismissed_at')) {
                $table->dropColumn('onboarding_dismissed_at');
            }
        });
    }
};
