<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * U11 (NOV-109 / ADR-0112): admin-authored template-hook fragments. Each row attaches one sandbox-language
 * fragment to a NAMED anchor (`TemplateContract::hooks()`) rendered by `<x-template-hook>` — anchored by
 * name, not by file content, so a core release never invalidates them (the upgrade-safe alternative to
 * whole-file view overrides). Sources pass the same sandbox lint/parse gate as template overrides.
 * Additive + reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('site_template_hooks')) {
            Schema::create('site_template_hooks', function (Blueprint $table): void {
                $table->id();
                $table->string('hook_key', 60)->index();
                $table->string('name', 100);
                $table->text('source');
                $table->unsignedInteger('position')->default(0);
                $table->boolean('is_enabled')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('site_template_hooks');
    }
};
