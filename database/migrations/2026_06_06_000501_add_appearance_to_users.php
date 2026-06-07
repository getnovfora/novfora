<?php

// SPDX-License-Identifier: Apache-2.0

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Appearance settings (default-theme phase, PART 2) — the only behaviour additions in the theme pass.
| Per-user colour mode (auto | light | dark) and density (comfortable | compact). Both are presentation
| preferences applied server-side for signed-in users (so they work with NO JavaScript) and mirrored to
| localStorage for guests by the layout's inline boot snippet. Reversible and non-destructive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('color_mode', 10)->default('auto')->after('locale');     // auto | light | dark
            $table->string('density', 12)->default('comfortable')->after('color_mode'); // comfortable | compact
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['color_mode', 'density']);
        });
    }
};
