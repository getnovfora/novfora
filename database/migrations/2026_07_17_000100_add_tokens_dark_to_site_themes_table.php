<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U9 (NOV-107): per-token DARK values for DB style themes. `tokens` stays the light map (Theme Studio 1.1);
 * `tokens_dark` holds optional dark-layer overrides, validated by the same strict cleanTokens() gate.
 * Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->json('tokens_dark')->nullable()->after('tokens');
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->dropColumn('tokens_dark');
        });
    }
};
