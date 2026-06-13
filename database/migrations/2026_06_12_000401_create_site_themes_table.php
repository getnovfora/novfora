<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

// DB-backed "style themes" (ACP visual theme editor). Each row is a named visual preset — an AA-safe accent
// colour plus an optional block of custom CSS — that an admin creates/activates from the panel WITHOUT
// touching the filesystem. Distinct from filesystem child themes (which override Blade views via
// ThemeManager); a style theme only emits CSS into the document head. Exactly one row may be active.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_themes', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('accent_color')->nullable(); // validated hex (#rrggbb) or null = inherit built-in
            $table->text('custom_css')->nullable();      // admin-authored CSS, sanitised before storage
            $table->boolean('is_active')->default(false);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_themes');
    }
};
