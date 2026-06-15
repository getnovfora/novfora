<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// Theme Studio 1.1 — a style theme may now override the core design tokens (surfaces / ink / borders /
// radius), not just the accent. Stored as a small JSON map of {token-key: value}; StyleThemeManager
// validates every value (strict hex / length) and emits them into the document head. Reversible.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->json('tokens')->nullable()->after('custom_css'); // {surface:#…, ink:#…, radius:10px, …}
        });
    }

    public function down(): void
    {
        Schema::table('site_themes', function (Blueprint $table): void {
            $table->dropColumn('tokens');
        });
    }
};
