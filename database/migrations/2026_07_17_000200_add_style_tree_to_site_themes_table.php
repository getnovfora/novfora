<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U10 (NOV-108): the multi-style tree. A style theme may descend from another (parent/child inheritance —
 * child values win, resolved by StyleThemeManager with a depth cap and cycle guard) and may be offered to
 * members in the per-user style chooser. Referential integrity is enforced by StyleThemeManager (the only
 * writer): a parent with children can't be deleted, so no FK cascade is needed. Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')->nullable()->after('slug')->index();
            $table->boolean('is_user_selectable')->default(false)->after('is_active')->index();
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->dropIndex(['parent_id']); // sqlite: indexes must go before their columns
            $table->dropIndex(['is_user_selectable']);
            $table->dropColumn(['parent_id', 'is_user_selectable']);
        });
    }
};
