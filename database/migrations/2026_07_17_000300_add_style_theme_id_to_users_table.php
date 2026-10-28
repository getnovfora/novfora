<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U10 (NOV-108): the per-user style choice — a plain nullable reference to a user-selectable site_themes row
 * (null = the site default), following the users-table appearance-preference pattern (color_mode/density).
 * Written only by AppearanceController via direct assignment (never mass-assignment); StyleThemeManager
 * clears selections when a style is deleted. Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedBigInteger('style_theme_id')->nullable()->after('density')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['style_theme_id']); // sqlite: the index must go before the column
            $table->dropColumn('style_theme_id');
        });
    }
};
